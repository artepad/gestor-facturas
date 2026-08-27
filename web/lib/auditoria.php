<?php
/**
 * Auditoría simple: registra en la tabla `auditoria` cada acción sensible de
 * los módulos (quién, qué, cuándo y sobre qué). Pensado para dejar un rastro
 * completo y consultable a futuro.
 *
 * El diseño guarda un *snapshot* del usuario (nombre y rol) además del
 * `usuario_id`, para que el registro siga siendo legible aunque el usuario se
 * elimine después. `cliente_id` es un simple entero (sin FK): si el cliente se
 * borra, la fila de auditoría se conserva con su descripción intacta.
 *
 * Regla de oro: la auditoría NUNCA debe romper la operación principal. Si el
 * INSERT falla por cualquier motivo, se registra en el log de PHP y se sigue.
 */

/**
 * Guarda un evento de auditoría.
 *
 * @param array   $usuario     El usuario en sesión (id, nombre, rol).
 * @param string  $modulo      Módulo donde ocurre (ej. 'fiados').
 * @param string  $accion      Acción realizada (ej. 'eliminar_fiado').
 * @param int|null $clienteId  Cliente afectado, si aplica.
 * @param string  $descripcion Resumen legible del evento.
 * @param string|null $detalle Detalle opcional (ej. JSON con antes/después).
 * @param string|null $refTipo Tipo de lo afectado ('producto', 'venta', 'caja'...).
 * @param int|null    $refId   Id de lo afectado, para poder filtrar el historial.
 */
function registrar_auditoria(PDO $pdo, array $usuario, string $modulo, string $accion,
                             ?int $clienteId, string $descripcion, ?string $detalle = null,
                             ?string $refTipo = null, ?int $refId = null): void
{
    try {
        $st = $pdo->prepare(
            "INSERT INTO auditoria
               (usuario_id, usuario_nombre, rol, modulo, accion, cliente_id, descripcion,
                detalle, referencia_tipo, referencia_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $usuario['id']     ?? null,
            $usuario['nombre'] ?? null,
            $usuario['rol']    ?? null,
            $modulo,
            $accion,
            $clienteId,
            mb_substr($descripcion, 0, 255),
            $detalle,
            $refTipo,
            $refId,
        ]);
    } catch (Throwable $e) {
        error_log('auditoria: ' . $e->getMessage());
    }
}

/**
 * Compara dos versiones de un registro y devuelve un JSON legible con solo los
 * campos que cambiaron: {"precio_venta":{"antes":1200,"despues":1500}}.
 * Devuelve null si no cambió nada (así no se auditan ediciones vacías).
 */
function cambios_json(array $antes, array $despues, array $campos): ?string
{
    $dif = [];
    foreach ($campos as $c) {
        $a = $antes[$c]   ?? null;
        $d = $despues[$c] ?? null;
        // Compara como texto para que "1200" y 1200.00 no cuenten como cambio.
        if (is_numeric($a) && is_numeric($d)) {
            if (abs((float)$a - (float)$d) < 0.001) continue;
        } elseif ((string)$a === (string)$d) {
            continue;
        }
        $dif[$c] = ['antes' => $a, 'despues' => $d];
    }
    return $dif ? json_encode($dif, JSON_UNESCAPED_UNICODE) : null;
}
