<?php
/**
 * Lógica del módulo Ventas / POS: turnos de caja, arqueo y movimientos de
 * efectivo. Web-nativo (la web es la fuente de verdad).
 *
 * Las funciones reciben los IDs ya acotados a los negocios visibles del usuario
 * desde el llamador (nunca consultan sin ese filtro).
 *
 * Diseño completo del módulo: web/POS_DISENO.md
 */

/**
 * UUID v4. Es la clave de idempotencia de ventas y turnos: el mismo uuid nunca
 * se registra dos veces, aunque el envío se repita (mismo patrón que
 * facturas.uuid_local en api/facturas.php).
 */
function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);   // versión 4
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);   // variante RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Turno abierto de un usuario, o null si no tiene ninguno.
 *
 * Un usuario puede tener UN turno abierto a la vez. Así dos cajas simultáneas
 * son dos personas distintas, cada una con su propio efectivo y su propio
 * arqueo — que es como funciona de verdad en el local.
 */
function sesion_abierta_de(PDO $pdo, int $usuarioId): ?array
{
    $st = $pdo->prepare(
        "SELECT s.*, n.nombre AS negocio_nombre
           FROM caja_sesiones s
           JOIN negocios n ON n.id = s.negocio_id
          WHERE s.estado = 'abierta' AND s.usuario_apertura = ?
          ORDER BY s.abierto_en DESC LIMIT 1"
    );
    $st->execute([$usuarioId]);
    return $st->fetch() ?: null;
}

/** Turnos abiertos de los negocios dados (vista de administración). */
function sesiones_abiertas(PDO $pdo, array $idsNegocios): array
{
    if (!$idsNegocios) return [];
    $ph = implode(',', array_fill(0, count($idsNegocios), '?'));
    $st = $pdo->prepare(
        "SELECT s.*, n.nombre AS negocio_nombre, u.nombre AS cajero
           FROM caja_sesiones s
           JOIN negocios n ON n.id = s.negocio_id
           LEFT JOIN usuarios u ON u.id = s.usuario_apertura
          WHERE s.estado = 'abierta' AND s.negocio_id IN ($ph)
          ORDER BY s.abierto_en DESC"
    );
    $st->execute($idsNegocios);
    return $st->fetchAll();
}

/** Abre un turno de caja y devuelve su id. */
function abrir_caja(PDO $pdo, array $usuario, int $negocioId, string $caja, float $fondo): int
{
    $st = $pdo->prepare(
        "INSERT INTO caja_sesiones (uuid, negocio_id, caja, usuario_apertura, fondo_inicial, abierto_en)
         VALUES (?,?,?,?,?,NOW())"
    );
    $st->execute([uuid4(), $negocioId, ($caja !== '' ? $caja : null),
                  $usuario['id'] ?? null, $fondo]);
    return (int)$pdo->lastInsertId();
}

/** Registra una entrada o salida de efectivo del turno. */
function registrar_movimiento_caja(PDO $pdo, array $usuario, int $sesionId,
                                   string $tipo, float $monto, string $motivo): void
{
    $st = $pdo->prepare(
        "INSERT INTO caja_movimientos (caja_sesion_id, tipo, monto, motivo, usuario_id)
         VALUES (?,?,?,?,?)"
    );
    $st->execute([$sesionId, $tipo, $monto, ($motivo !== '' ? $motivo : null),
                  $usuario['id'] ?? null]);
}

/** Movimientos de efectivo del turno, del más nuevo al más viejo. */
function movimientos_caja(PDO $pdo, int $sesionId): array
{
    $st = $pdo->prepare(
        "SELECT m.*, u.nombre AS usuario
           FROM caja_movimientos m
           LEFT JOIN usuarios u ON u.id = m.usuario_id
          WHERE m.caja_sesion_id = ?
          ORDER BY m.id DESC"
    );
    $st->execute([$sesionId]);
    return $st->fetchAll();
}

/** Cuántos tickets quedaron en espera en el turno (bloquean el cierre). */
function tickets_en_espera(PDO $pdo, int $sesionId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM ventas_espera WHERE caja_sesion_id = ?");
    $st->execute([$sesionId]);
    return (int)$st->fetchColumn();
}

/**
 * Resumen del turno para el arqueo.
 *
 * efectivo_esperado = fondo + ventas en efectivo + recaudaciones en efectivo
 *                     + entradas − salidas
 *
 * Ojo con las recaudaciones (Klap y otros convenios): el efectivo del cliente
 * SÍ entra al cajón aunque NO sea una venta. Si no se sumaran acá, el arqueo
 * mostraría un sobrante todos los días. Ver POS_DISENO.md sección 7.7.
 */
function resumen_caja(PDO $pdo, int $sesionId): array
{
    $r = [
        'fondo_inicial'    => 0.0,
        'ventas_total'     => 0.0,
        'numero_ventas'    => 0,
        'ventas_efectivo'  => 0.0,
        'ventas_tarjeta'   => 0.0,
        'ventas_transferencia' => 0.0,
        'ventas_credito'   => 0.0,
        'recaudado_efectivo' => 0.0,
        'recaudado_total'  => 0.0,
        'entradas'         => 0.0,
        'salidas'          => 0.0,
    ];

    $st = $pdo->prepare("SELECT fondo_inicial FROM caja_sesiones WHERE id = ?");
    $st->execute([$sesionId]);
    $r['fondo_inicial'] = (float)$st->fetchColumn();

    // Ventas del turno (solo las no anuladas).
    $st = $pdo->prepare(
        "SELECT COUNT(*) n, COALESCE(SUM(total),0) t
           FROM ventas WHERE caja_sesion_id = ? AND estado = 'completada'"
    );
    $st->execute([$sesionId]);
    $v = $st->fetch();
    $r['numero_ventas'] = (int)$v['n'];
    $r['ventas_total']  = (float)$v['t'];

    // Desglose por medio de pago (una venta puede tener varios: pago mixto).
    $st = $pdo->prepare(
        "SELECT p.medio, COALESCE(SUM(p.monto),0) m
           FROM venta_pagos p
           JOIN ventas v ON v.id = p.venta_id
          WHERE v.caja_sesion_id = ? AND v.estado = 'completada'
          GROUP BY p.medio"
    );
    $st->execute([$sesionId]);
    $mapa = ['efectivo' => 'ventas_efectivo', 'tarjeta' => 'ventas_tarjeta',
             'transferencia' => 'ventas_transferencia', 'credito' => 'ventas_credito'];
    foreach ($st->fetchAll() as $f) {
        if (isset($mapa[$f['medio']])) $r[$mapa[$f['medio']]] = (float)$f['m'];
    }

    // Recaudación de servicios: NO es venta, pero el efectivo entra al cajón.
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(monto),0) total,
                COALESCE(SUM(CASE WHEN medio_pago = 'efectivo' THEN monto ELSE 0 END),0) efectivo
           FROM servicio_operaciones
          WHERE caja_sesion_id = ? AND tipo = 'recaudacion'"
    );
    $st->execute([$sesionId]);
    $s = $st->fetch();
    $r['recaudado_total']    = (float)$s['total'];
    $r['recaudado_efectivo'] = (float)$s['efectivo'];

    // Entradas y salidas de efectivo.
    $st = $pdo->prepare(
        "SELECT tipo, COALESCE(SUM(monto),0) m FROM caja_movimientos
          WHERE caja_sesion_id = ? GROUP BY tipo"
    );
    $st->execute([$sesionId]);
    foreach ($st->fetchAll() as $f) {
        if ($f['tipo'] === 'entrada') $r['entradas'] = (float)$f['m'];
        if ($f['tipo'] === 'salida')  $r['salidas']  = (float)$f['m'];
    }

    $r['efectivo_esperado'] = $r['fondo_inicial'] + $r['ventas_efectivo']
                            + $r['recaudado_efectivo'] + $r['entradas'] - $r['salidas'];
    return $r;
}

/**
 * Cierra el turno guardando el arqueo. Devuelve el resumen con la diferencia
 * (contado − esperado): negativa = faltante, positiva = sobrante.
 *
 * Lanza RuntimeException si quedan tickets en espera (no se puede cerrar con
 * ventas a medio hacer) o si el turno ya estaba cerrado.
 */
function cerrar_caja(PDO $pdo, array $usuario, int $sesionId,
                     float $contado, string $nota = ''): array
{
    if (tickets_en_espera($pdo, $sesionId) > 0) {
        throw new RuntimeException('Hay ventas en espera sin terminar. Cóbralas o descártalas antes de cerrar la caja.');
    }
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        // Relee el estado dentro de la transacción para no cerrar dos veces.
        $st = $pdo->prepare("SELECT estado FROM caja_sesiones WHERE id = ? FOR UPDATE");
        $st->execute([$sesionId]);
        if ($st->fetchColumn() !== 'abierta') {
            throw new RuntimeException('Esta caja ya está cerrada.');
        }

        $r = resumen_caja($pdo, $sesionId);
        $diferencia = $contado - $r['efectivo_esperado'];

        $pdo->prepare(
            "UPDATE caja_sesiones
                SET estado='cerrada', cerrado_en=NOW(), usuario_cierre=?,
                    efectivo_contado=?, efectivo_esperado=?, diferencia=?, nota=?
              WHERE id = ?"
        )->execute([$usuario['id'] ?? null, $contado, $r['efectivo_esperado'],
                    $diferencia, ($nota !== '' ? $nota : null), $sesionId]);

        if ($propia) $pdo->commit();
        $r['efectivo_contado'] = $contado;
        $r['diferencia'] = $diferencia;
        return $r;
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Turnos ya cerrados de los negocios dados (historial). */
function sesiones_cerradas(PDO $pdo, array $idsNegocios, int $limite = 30): array
{
    if (!$idsNegocios) return [];
    $ph = implode(',', array_fill(0, count($idsNegocios), '?'));
    $st = $pdo->prepare(
        "SELECT s.*, n.nombre AS negocio_nombre, u.nombre AS cajero
           FROM caja_sesiones s
           JOIN negocios n ON n.id = s.negocio_id
           LEFT JOIN usuarios u ON u.id = s.usuario_apertura
          WHERE s.estado = 'cerrada' AND s.negocio_id IN ($ph)
          ORDER BY s.cerrado_en DESC
          LIMIT " . (int)$limite
    );
    $st->execute($idsNegocios);
    return $st->fetchAll();
}
