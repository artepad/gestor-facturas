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

// ===================== Venta =====================

/**
 * Busca productos del negocio para la pantalla de venta.
 *
 * Primero por código exacto (lo que manda el lector de código de barras), y si
 * no hay match, por código que empiece igual o por nombre que contenga el
 * término. Devuelve como máximo $limite resultados.
 */
function buscar_producto_pos(PDO $pdo, int $negocioId, string $termino, int $limite = 20): array
{
    $t = trim($termino);
    if ($t === '') return [];

    $cols = "id, codigo, nombre, precio_venta, precio_costo, departamento, afecto_iva, stock";

    // Código exacto: el caso del escáner. Devuelve uno solo y listo.
    $st = $pdo->prepare(
        "SELECT $cols FROM productos
          WHERE negocio_id = ? AND codigo = ? AND activo = 1 LIMIT 1"
    );
    $st->execute([$negocioId, $t]);
    if ($fila = $st->fetch()) return [$fila];

    $st = $pdo->prepare(
        "SELECT $cols FROM productos
          WHERE negocio_id = ? AND activo = 1 AND (codigo LIKE ? OR nombre LIKE ?)
          ORDER BY (codigo LIKE ?) DESC, nombre
          LIMIT " . (int)$limite
    );
    $st->execute([$negocioId, $t . '%', '%' . $t . '%', $t . '%']);
    return $st->fetchAll();
}

/** Venta ya registrada con ese uuid, o null. Base de la idempotencia. */
function venta_por_uuid(PDO $pdo, string $uuid): ?array
{
    $st = $pdo->prepare("SELECT * FROM ventas WHERE uuid = ?");
    $st->execute([$uuid]);
    return $st->fetch() ?: null;
}

/**
 * Registra una venta completa (cabecera + líneas + pagos) en una transacción.
 *
 * IDEMPOTENTE: si el uuid ya existe devuelve la venta previa sin duplicar nada.
 * Así los reintentos de la caja (o un doble clic) nunca cobran dos veces.
 *
 * Los totales se RECALCULAN acá desde las líneas: nunca se confía en el total
 * que manda el navegador.
 *
 * $items: [{producto_id?, codigo?, nombre, cantidad, precio_unitario,
 *           descuento?, departamento?, costo_unitario?, afecto_iva?}]
 * $pagos: [{medio, monto, referencia?}]
 */
function registrar_venta(PDO $pdo, array $usuario, array $sesion, string $uuid,
                         array $items, array $pagos, ?float $pagoCon = null,
                         string $origen = 'online', string $nota = ''): array
{
    if ($previa = venta_por_uuid($pdo, $uuid)) {
        return ['venta' => $previa, 'duplicada' => true];
    }
    if (!$items) {
        throw new RuntimeException('La venta no tiene productos.');
    }

    // Normaliza las líneas y calcula los totales del lado del servidor.
    $lineas = [];
    $subtotal = 0.0; $descuentos = 0.0; $costoTotal = 0.0;
    foreach ($items as $it) {
        $nombre = trim((string)($it['nombre'] ?? ''));
        $cant   = (float)($it['cantidad'] ?? 0);
        $precio = (float)($it['precio_unitario'] ?? 0);
        $desc   = (float)($it['descuento'] ?? 0);
        if ($nombre === '' || $cant <= 0 || $precio < 0) {
            throw new RuntimeException('Hay una línea de la venta con datos inválidos.');
        }
        $totalLinea = round($cant * $precio - $desc, 2);
        if ($totalLinea < 0) $totalLinea = 0.0;

        $costoUnit = isset($it['costo_unitario']) && $it['costo_unitario'] !== null
                   ? (float)$it['costo_unitario'] : null;
        if ($costoUnit !== null) $costoTotal += $costoUnit * $cant;

        $subtotal   += $cant * $precio;
        $descuentos += $desc;
        $lineas[] = [
            'producto_id'     => isset($it['producto_id']) && $it['producto_id'] ? (int)$it['producto_id'] : null,
            'codigo'          => ($it['codigo'] ?? '') !== '' ? substr((string)$it['codigo'], 0, 40) : null,
            'nombre'          => substr($nombre, 0, 255),
            'departamento'    => ($it['departamento'] ?? '') !== '' ? substr((string)$it['departamento'], 0, 150) : null,
            'cantidad'        => $cant,
            'precio_unitario' => $precio,
            'descuento'       => $desc,
            'total'           => $totalLinea,
            'costo_unitario'  => $costoUnit,
            'afecto_iva'      => !empty($it['afecto_iva']) ? 1 : 0,
        ];
    }
    $total = round($subtotal - $descuentos, 2);
    if ($total < 0) $total = 0.0;

    // Los pagos deben cubrir exactamente el total (tolerancia de 1 peso por
    // redondeos). El vuelto NO es un pago negativo: se calcula aparte.
    $sumaPagos = 0.0;
    foreach ($pagos as $p) $sumaPagos += (float)($p['monto'] ?? 0);
    if (abs($sumaPagos - $total) > 1) {
        throw new RuntimeException('Los pagos no suman el total de la venta.');
    }

    $vuelto = ($pagoCon !== null && $pagoCon > $total) ? round($pagoCon - $total, 2) : 0.0;
    $negocioId = (int)$sesion['negocio_id'];

    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        // El folio es correlativo por negocio. Con varias cajas a la vez dos
        // podrían calcular el mismo número: el UNIQUE (negocio_id, folio) lo
        // impide y acá se reintenta con el siguiente.
        $ventaId = 0; $folio = 0;
        for ($intento = 1; $intento <= 5; $intento++) {
            $st = $pdo->prepare("SELECT COALESCE(MAX(folio),0)+1 FROM ventas WHERE negocio_id = ?");
            $st->execute([$negocioId]);
            $folio = (int)$st->fetchColumn();
            try {
                $st = $pdo->prepare(
                    "INSERT INTO ventas (uuid, negocio_id, caja_sesion_id, usuario_id, folio, fecha,
                                         subtotal, descuento_total, total, costo_total,
                                         pago_con, vuelto, origen, nota)
                     VALUES (?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?)"
                );
                $st->execute([$uuid, $negocioId, (int)$sesion['id'], $usuario['id'] ?? null, $folio,
                              round($subtotal, 2), round($descuentos, 2), $total,
                              ($costoTotal > 0 ? round($costoTotal, 2) : null),
                              $pagoCon, $vuelto, $origen, ($nota !== '' ? $nota : null)]);
                $ventaId = (int)$pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                // 23000 = clave duplicada. Si chocó el uuid, otra caja ya
                // registró esta misma venta: es idempotencia, no un error.
                if ($e->getCode() !== '23000') throw $e;
                if ($previa = venta_por_uuid($pdo, $uuid)) {
                    if ($propia) $pdo->commit();
                    return ['venta' => $previa, 'duplicada' => true];
                }
                if ($intento === 5) throw $e;   // chocó el folio 5 veces: raro
            }
        }

        $stI = $pdo->prepare(
            "INSERT INTO venta_items (venta_id, producto_id, codigo, nombre, departamento,
                                      cantidad, precio_unitario, descuento, total,
                                      costo_unitario, afecto_iva)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($lineas as $l) {
            $stI->execute([$ventaId, $l['producto_id'], $l['codigo'], $l['nombre'], $l['departamento'],
                           $l['cantidad'], $l['precio_unitario'], $l['descuento'], $l['total'],
                           $l['costo_unitario'], $l['afecto_iva']]);
        }

        $stP = $pdo->prepare(
            "INSERT INTO venta_pagos (venta_id, medio, monto, referencia) VALUES (?,?,?,?)"
        );
        foreach ($pagos as $p) {
            $medio = (string)($p['medio'] ?? 'efectivo');
            if (!in_array($medio, ['efectivo', 'tarjeta', 'transferencia', 'credito'], true)) {
                throw new RuntimeException('Medio de pago desconocido: ' . $medio);
            }
            $stP->execute([$ventaId, $medio, (float)($p['monto'] ?? 0),
                           ($p['referencia'] ?? '') !== '' ? $p['referencia'] : null]);
        }

        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $st = $pdo->prepare("SELECT * FROM ventas WHERE id = ?");
    $st->execute([$ventaId]);
    return ['venta' => $st->fetch(), 'duplicada' => false];
}

/** Líneas de una venta. */
function items_de_venta(PDO $pdo, int $ventaId): array
{
    $st = $pdo->prepare("SELECT * FROM venta_items WHERE venta_id = ? ORDER BY id");
    $st->execute([$ventaId]);
    return $st->fetchAll();
}

/** Pagos de una venta. */
function pagos_de_venta(PDO $pdo, int $ventaId): array
{
    $st = $pdo->prepare("SELECT * FROM venta_pagos WHERE venta_id = ? ORDER BY id");
    $st->execute([$ventaId]);
    return $st->fetchAll();
}

/** Ventas del turno, de la más nueva a la más vieja. */
function ventas_del_turno(PDO $pdo, int $sesionId, int $limite = 50): array
{
    $st = $pdo->prepare(
        "SELECT v.*, (SELECT COUNT(*) FROM venta_items i WHERE i.venta_id = v.id) AS n_items,
                (SELECT GROUP_CONCAT(p.medio) FROM venta_pagos p WHERE p.venta_id = v.id) AS medios
           FROM ventas v
          WHERE v.caja_sesion_id = ?
          ORDER BY v.id DESC
          LIMIT " . (int)$limite
    );
    $st->execute([$sesionId]);
    return $st->fetchAll();
}

/** Última venta del turno (para el panel "Total / Pagó con / Cambio"). */
function ultima_venta_del_turno(PDO $pdo, int $sesionId): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM ventas WHERE caja_sesion_id = ? AND estado = 'completada'
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$sesionId]);
    return $st->fetch() ?: null;
}

// ===================== Ventas en espera (tickets aparcados) =====================

/** Tickets en espera del turno. */
function listar_espera(PDO $pdo, int $sesionId): array
{
    $st = $pdo->prepare(
        "SELECT * FROM ventas_espera WHERE caja_sesion_id = ? ORDER BY id"
    );
    $st->execute([$sesionId]);
    return $st->fetchAll();
}

/** Aparca un carrito y devuelve el ticket guardado. */
function guardar_espera(PDO $pdo, array $usuario, array $sesion,
                        array $items, string $etiqueta, float $total): array
{
    if (!$items) throw new RuntimeException('No hay nada que dejar en espera.');
    $st = $pdo->prepare("SELECT COALESCE(MAX(numero),0)+1 FROM ventas_espera WHERE caja_sesion_id = ?");
    $st->execute([(int)$sesion['id']]);
    $numero = (int)$st->fetchColumn();

    $uuid = uuid4();
    $st = $pdo->prepare(
        "INSERT INTO ventas_espera (uuid, negocio_id, caja_sesion_id, usuario_id,
                                    numero, etiqueta, items, total_estimado)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $st->execute([$uuid, (int)$sesion['negocio_id'], (int)$sesion['id'], $usuario['id'] ?? null,
                  $numero, ($etiqueta !== '' ? substr($etiqueta, 0, 80) : null),
                  json_encode($items, JSON_UNESCAPED_UNICODE), $total]);

    return ['id' => (int)$pdo->lastInsertId(), 'numero' => $numero,
            'etiqueta' => $etiqueta, 'total_estimado' => $total];
}

/** Recupera un ticket en espera y lo borra (pasa a ser el carrito activo). */
function tomar_espera(PDO $pdo, int $sesionId, int $esperaId): array
{
    $st = $pdo->prepare("SELECT * FROM ventas_espera WHERE id = ? AND caja_sesion_id = ?");
    $st->execute([$esperaId, $sesionId]);
    $t = $st->fetch();
    if (!$t) throw new RuntimeException('Ese ticket en espera ya no existe.');
    $pdo->prepare("DELETE FROM ventas_espera WHERE id = ?")->execute([$esperaId]);
    return ['items' => json_decode((string)$t['items'], true) ?: [],
            'etiqueta' => $t['etiqueta'], 'numero' => (int)$t['numero']];
}

/** Descarta un ticket en espera sin cobrarlo. Devuelve su descripción. */
function descartar_espera(PDO $pdo, int $sesionId, int $esperaId): string
{
    $st = $pdo->prepare("SELECT * FROM ventas_espera WHERE id = ? AND caja_sesion_id = ?");
    $st->execute([$esperaId, $sesionId]);
    $t = $st->fetch();
    if (!$t) throw new RuntimeException('Ese ticket en espera ya no existe.');
    $pdo->prepare("DELETE FROM ventas_espera WHERE id = ?")->execute([$esperaId]);
    return ($t['etiqueta'] ?: 'Ticket ' . $t['numero']) . ' por ' . clp($t['total_estimado']);
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
