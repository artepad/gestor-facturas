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
 */
function registrar_auditoria(PDO $pdo, array $usuario, string $modulo, string $accion,
                             ?int $clienteId, string $descripcion, ?string $detalle = null): void
{
    try {
        $st = $pdo->prepare(
            "INSERT INTO auditoria
               (usuario_id, usuario_nombre, rol, modulo, accion, cliente_id, descripcion, detalle)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
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
        ]);
    } catch (Throwable $e) {
        error_log('auditoria: ' . $e->getMessage());
    }
}
