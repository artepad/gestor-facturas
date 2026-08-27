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
        "SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(costo_total),0) c
           FROM ventas WHERE caja_sesion_id = ? AND estado = 'completada'"
    );
    $st->execute([$sesionId]);
    $v = $st->fetch();
    $r['numero_ventas'] = (int)$v['n'];
    $r['ventas_total']  = (float)$v['t'];
    $r['costo_total']   = (float)$v['c'];
    // Ganancia real del turno. Solo tiene sentido si los productos vendidos
    // traían precio de costo (el producto común no aporta costo).
    $r['ganancia'] = $r['costo_total'] > 0 ? $r['ventas_total'] - $r['costo_total'] : null;

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
 * Ventas por departamento del turno, para el corte. Las líneas sin
 * departamento (producto común, monto libre) se agrupan en "Sin departamento".
 */
function departamentos_del_turno(PDO $pdo, int $sesionId): array
{
    $st = $pdo->prepare(
        "SELECT COALESCE(NULLIF(i.departamento,''), 'Sin departamento') AS departamento,
                COALESCE(SUM(i.total),0) AS monto
           FROM venta_items i
           JOIN ventas v ON v.id = i.venta_id
          WHERE v.caja_sesion_id = ? AND v.estado = 'completada'
          GROUP BY departamento
          ORDER BY monto DESC"
    );
    $st->execute([$sesionId]);
    return $st->fetchAll();
}

/**
 * Genera el `corte` del turno reutilizando registrar_corte() de lib/eleventa.php
 * — el mismo que usan los correos de Eleventa. Así el módulo Ingresos y todos
 * los reportes siguen funcionando igual, pero con datos reales del POS.
 *
 * Es idempotente por (negocio, cerrado_en, cajero), como el de los correos.
 * Devuelve el id del corte.
 */
function generar_corte_de_sesion(PDO $pdo, array $usuario, array $sesion,
                                 array $r, float $contado, float $diferencia): int
{
    if (!function_exists('registrar_corte')) {
        require_once __DIR__ . '/eleventa.php';
    }
    $negocioId = (int)$sesion['negocio_id'];
    $cajero = trim((string)($usuario['nombre'] ?? '')) ?: 'Cajero';

    $movs = [];
    foreach (movimientos_caja($pdo, (int)$sesion['id']) as $m) {
        $movs[] = [
            'tipo'        => $m['tipo'],
            'hora'        => substr((string)$m['creado_en'], 11, 5),
            'descripcion' => $m['motivo'],
            'monto'       => (float)$m['monto'],
        ];
    }

    $corteId = registrar_corte($pdo, $negocioId, null, [
        'cajero'        => $cajero,
        'caja'          => $sesion['caja'],
        'abierto_en'    => $sesion['abierto_en'],
        'cerrado_en'    => date('Y-m-d H:i:s'),
        // Ventas del negocio. La recaudación de convenios NO va acá: no es
        // venta (ver POS_DISENO.md 7.7); viaja en recaudado_efectivo.
        'ventas_totales'       => $r['ventas_total'],
        'ganancia'             => ($r['ganancia'] ?? null),
        'numero_ventas'        => $r['numero_ventas'],
        'fondo_caja'           => $r['fondo_inicial'],
        'ventas_efectivo'      => $r['ventas_efectivo'],
        'abonos_efectivo'      => null,     // los abonos de fiados llegan en la Etapa 2
        'entradas_caja'        => $r['entradas'],
        'salidas_caja'         => $r['salidas'],
        'efectivo_esperado'    => $r['efectivo_esperado'],
        'ventas_tarjeta'       => $r['ventas_tarjeta'],
        'ventas_credito'       => $r['ventas_credito'],
        'ventas_vales'         => 0,        // no aplica en Chile (ver POS_DISENO.md)
        'ventas_transferencia' => $r['ventas_transferencia'],
        'recaudado_efectivo'   => $r['recaudado_efectivo'],
        'diferencia'           => $diferencia,
        'movimientos'          => $movs,
        'departamentos'        => departamentos_del_turno($pdo, (int)$sesion['id']),
    ]);

    // Vincula el cajero con el usuario web si aún no lo estaba: permite
    // reportes "por vendedor de la plataforma".
    $pdo->prepare(
        "UPDATE cajeros SET usuario_id = ?
          WHERE negocio_id = ? AND nombre = ? AND usuario_id IS NULL"
    )->execute([$usuario['id'] ?? null, $negocioId, $cajero]);

    return $corteId;
}

/**
 * Cierra el turno guardando el arqueo. Devuelve el resumen con la diferencia
 * (contado − esperado): negativa = faltante, positiva = sobrante.
 *
 * Al cerrar genera además el `corte`, que es lo que alimenta el módulo
 * Ingresos y el balance por negocio (reemplaza al correo de Eleventa).
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

        $st = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id = ?");
        $st->execute([$sesionId]);
        $sesion = $st->fetch();

        $r = resumen_caja($pdo, $sesionId);
        $diferencia = $contado - $r['efectivo_esperado'];

        // Genera el corte que alimenta Ingresos (reemplaza al correo de Eleventa).
        $corteId = generar_corte_de_sesion($pdo, $usuario, $sesion, $r, $contado, $diferencia);

        $pdo->prepare(
            "UPDATE caja_sesiones
                SET estado='cerrada', cerrado_en=NOW(), usuario_cierre=?,
                    efectivo_contado=?, efectivo_esperado=?, diferencia=?, nota=?,
                    corte_id=?
              WHERE id = ?"
        )->execute([$usuario['id'] ?? null, $contado, $r['efectivo_esperado'],
                    $diferencia, ($nota !== '' ? $nota : null), $corteId, $sesionId]);

        if ($propia) $pdo->commit();
        $r['efectivo_contado'] = $contado;
        $r['diferencia'] = $diferencia;
        $r['corte_id'] = $corteId;
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
function buscar_producto_pos(PDO $pdo, string $termino, int $limite = 20): array
{
    $t = trim($termino);
    if ($t === '') return [];

    // El catálogo es compartido por los dos negocios (POS_DISENO.md 7.1), así
    // que la búsqueda ya no se filtra por negocio.
    $cols = "id, codigo, nombre, precio_venta, precio_costo, departamento, afecto_iva";

    // Código exacto: el caso del escáner. Devuelve uno solo y listo.
    $st = $pdo->prepare(
        "SELECT $cols FROM productos WHERE codigo = ? AND activo = 1 LIMIT 1"
    );
    $st->execute([$t]);
    if ($fila = $st->fetch()) return [$fila];

    $st = $pdo->prepare(
        "SELECT $cols FROM productos
          WHERE activo = 1 AND (codigo LIKE ? OR nombre LIKE ?)
          ORDER BY (codigo LIKE ?) DESC, nombre
          LIMIT " . (int)$limite
    );
    $st->execute([$t . '%', '%' . $t . '%', $t . '%']);
    return $st->fetchAll();
}

/**
 * Clientes de fiado del negocio, con su deuda actual. Para elegir a quién se le
 * fía en la pantalla de venta.
 */
function clientes_para_pos(PDO $pdo, int $negocioId): array
{
    $st = $pdo->prepare(
        "SELECT c.id, TRIM(CONCAT(c.nombre, ' ', COALESCE(c.apellido,''))) AS nombre,
                COALESCE((SELECT SUM(f.monto) FROM fiados f WHERE f.cliente_id = c.id),0)
              - COALESCE((SELECT SUM(a.monto) FROM abonos a WHERE a.cliente_id = c.id),0) AS saldo
           FROM clientes c
          WHERE c.negocio_id = ? AND c.activo = 1
          ORDER BY nombre"
    );
    $st->execute([$negocioId]);
    return $st->fetchAll();
}

// ===================== Promociones automáticas =====================
// El POS las aplica solo al vender: si hay "4 yogures por $1.000" y el cliente
// lleva 4, cobra $1.000 en vez de 4 × $300. La vendedora no tiene que
// acordarse ni calcular nada.

/**
 * Promociones vigentes hoy para un negocio (las de negocio_id NULL valen en
 * los dos). Devuelve las de producto y las de departamento por separado.
 */
function promociones_vigentes(PDO $pdo, int $negocioId): array
{
    $st = $pdo->prepare(
        "SELECT * FROM promociones
          WHERE activo = 1
            AND (negocio_id IS NULL OR negocio_id = ?)
            AND (desde IS NULL OR desde <= CURDATE())
            AND (hasta IS NULL OR hasta >= CURDATE())
          ORDER BY id"
    );
    $st->execute([$negocioId]);

    $porProducto = [];
    $porDepto = [];
    foreach ($st->fetchAll() as $p) {
        if ($p['producto_id'])       $porProducto[(int)$p['producto_id']][] = $p;
        elseif ($p['departamento'])  $porDepto[$p['departamento']][] = $p;
    }
    return ['producto' => $porProducto, 'departamento' => $porDepto];
}

/**
 * Cuánto cuestan $cantidad unidades de un producto de $precio con esta promo.
 * Devuelve el total ya rebajado.
 *
 *   pack       "4 x $1.000": cada grupo de `cantidad` cuesta `valor`; el resto
 *              que no completa un grupo va a precio normal.
 *   nxm        "lleve 3 pague 2": cantidad=3, valor=2.
 *   precio     precio unitario rebajado (`valor`).
 *   porcentaje `valor`% de descuento.
 */
function precio_con_promocion(array $promo, float $cantidad, float $precio): float
{
    $n = (float)($promo['cantidad'] ?? 0);
    $v = (float)$promo['valor'];

    switch ($promo['tipo']) {
        case 'pack':
            if ($n <= 0) return $cantidad * $precio;
            $grupos = floor($cantidad / $n);
            $resto  = $cantidad - ($grupos * $n);
            return ($grupos * $v) + ($resto * $precio);

        case 'nxm':
            if ($n <= 0 || $v <= 0 || $v >= $n) return $cantidad * $precio;
            $grupos = floor($cantidad / $n);
            $resto  = $cantidad - ($grupos * $n);
            return (($grupos * $v) + $resto) * $precio;

        case 'precio':
            return $cantidad * $v;

        case 'porcentaje':
            return $cantidad * $precio * (1 - ($v / 100));
    }
    return $cantidad * $precio;
}

/**
 * Elige la promo que le conviene al cliente para una línea y calcula su
 * descuento. Las de producto mandan sobre las de departamento; entre varias,
 * gana la que deja el total más bajo.
 *
 * Devuelve ['promocion' => fila|null, 'descuento' => float, 'total' => float].
 */
function promocion_para_linea(array $promos, ?int $productoId, ?string $departamento,
                              float $cantidad, float $precio): array
{
    $normal = round($cantidad * $precio, 2);
    $candidatas = [];
    if ($productoId && isset($promos['producto'][$productoId])) {
        $candidatas = $promos['producto'][$productoId];
    } elseif ($departamento && isset($promos['departamento'][$departamento])) {
        $candidatas = $promos['departamento'][$departamento];
    }
    if (!$candidatas) return ['promocion' => null, 'descuento' => 0.0, 'total' => $normal];

    $mejor = null;
    $mejorTotal = $normal;
    foreach ($candidatas as $p) {
        $t = round(precio_con_promocion($p, $cantidad, $precio), 2);
        if ($t < $mejorTotal - 0.001) { $mejorTotal = $t; $mejor = $p; }
    }
    return [
        'promocion' => $mejor,
        'descuento' => round($normal - $mejorTotal, 2),
        'total'     => $mejorTotal,
    ];
}

/** Texto corto de la promo para mostrarla en el carrito y la boleta. */
function texto_promocion(array $p): string
{
    $n = rtrim(rtrim(number_format((float)($p['cantidad'] ?? 0), 3, ',', '.'), '0'), ',');
    switch ($p['tipo']) {
        case 'pack':       return $n . ' x ' . clp($p['valor']);
        case 'nxm':        return 'Lleve ' . $n . ' pague ' . (int)$p['valor'];
        case 'precio':     return 'Oferta ' . clp($p['valor']);
        case 'porcentaje': return rtrim(rtrim(number_format((float)$p['valor'], 2, ',', '.'), '0'), ',') . '% dcto.';
    }
    return $p['nombre'];
}

/**
 * Total de un carrito con las promociones ya aplicadas. Lo usa el endpoint
 * para validar que los pagos cuadren con lo que el cliente realmente paga.
 */
function total_con_promociones(PDO $pdo, int $negocioId, array $items): float
{
    $promos = promociones_vigentes($pdo, $negocioId);
    $total = 0.0;
    foreach ($items as $it) {
        $cant   = (float)($it['cantidad'] ?? 0);
        $precio = (float)($it['precio_unitario'] ?? 0);
        $pid = isset($it['producto_id']) && $it['producto_id'] ? (int)$it['producto_id'] : null;
        $dep = ($it['departamento'] ?? '') !== '' ? (string)$it['departamento'] : null;
        $total += promocion_para_linea($promos, $pid, $dep, $cant, $precio)['total'];
    }
    return round($total, 2);
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
                         string $origen = 'online', string $nota = '',
                         ?int $clienteId = null): array
{
    if ($previa = venta_por_uuid($pdo, $uuid)) {
        return ['venta' => $previa, 'duplicada' => true];
    }
    if (!$items) {
        throw new RuntimeException('La venta no tiene productos.');
    }

    // Normaliza las líneas y calcula los totales del lado del servidor.
    // Las promociones se recalculan ACÁ: nunca se confía en el descuento que
    // manda el navegador (podría manipularse).
    $promos = promociones_vigentes($pdo, (int)$sesion['negocio_id']);
    $lineas = [];
    $subtotal = 0.0; $descuentos = 0.0; $costoTotal = 0.0;
    foreach ($items as $it) {
        $nombre = trim((string)($it['nombre'] ?? ''));
        $cant   = (float)($it['cantidad'] ?? 0);
        $precio = (float)($it['precio_unitario'] ?? 0);
        if ($nombre === '' || $cant <= 0 || $precio < 0) {
            throw new RuntimeException('Hay una línea de la venta con datos inválidos.');
        }
        $pid = isset($it['producto_id']) && $it['producto_id'] ? (int)$it['producto_id'] : null;
        $dep = ($it['departamento'] ?? '') !== '' ? (string)$it['departamento'] : null;
        $ap  = promocion_para_linea($promos, $pid, $dep, $cant, $precio);
        $desc = $ap['descuento'];
        $totalLinea = $ap['total'];
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
            'promocion_id'    => $ap['promocion'] ? (int)$ap['promocion']['id'] : null,
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

    // El vuelto se calcula SOLO sobre la parte pagada en efectivo: si el cliente
    // paga $10.000 de los cuales $4.000 son tarjeta, entrega $6.000 en billetes
    // y el vuelto sale de esos $6.000, no del total.
    $montoEfectivo = 0.0;
    foreach ($pagos as $p) {
        if (($p['medio'] ?? '') === 'efectivo') $montoEfectivo += (float)($p['monto'] ?? 0);
    }
    $vuelto = ($pagoCon !== null && $pagoCon > $montoEfectivo)
            ? round($pagoCon - $montoEfectivo, 2) : 0.0;
    $negocioId = (int)$sesion['negocio_id'];

    // Fiado: si algun pago es a credito hay que saber a quien se le fia, y el
    // cliente debe ser de ESTE negocio (no se confia en lo que manda la caja).
    $montoCredito = 0.0;
    foreach ($pagos as $p) {
        if (($p['medio'] ?? '') === 'credito') $montoCredito += (float)($p['monto'] ?? 0);
    }
    if ($montoCredito > 0) {
        if (!$clienteId) {
            throw new RuntimeException('Para fiar hay que elegir al cliente.');
        }
        $st = $pdo->prepare("SELECT id FROM clientes WHERE id = ? AND negocio_id = ? AND activo = 1");
        $st->execute([$clienteId, $negocioId]);
        if (!$st->fetch()) {
            throw new RuntimeException('Ese cliente no existe en este negocio.');
        }
    } else {
        $clienteId = null;   // sin credito no se guarda cliente
    }

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
                    "INSERT INTO ventas (uuid, negocio_id, caja_sesion_id, usuario_id, cliente_id,
                                         folio, fecha, subtotal, descuento_total, total, costo_total,
                                         pago_con, vuelto, origen, nota)
                     VALUES (?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?)"
                );
                $st->execute([$uuid, $negocioId, (int)$sesion['id'], $usuario['id'] ?? null,
                              $clienteId, $folio,
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
                                      costo_unitario, afecto_iva, promocion_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($lineas as $l) {
            $stI->execute([$ventaId, $l['producto_id'], $l['codigo'], $l['nombre'], $l['departamento'],
                           $l['cantidad'], $l['precio_unitario'], $l['descuento'], $l['total'],
                           $l['costo_unitario'], $l['afecto_iva'], $l['promocion_id']]);
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

        // La parte fiada se anota como deuda del cliente en el modulo Fiados.
        // Queda enlazada a la venta (venta_id) para poder revertirla al anular.
        if ($montoCredito > 0) {
            $pdo->prepare(
                "INSERT INTO fiados (cliente_id, fecha, monto, descripcion, venta_id)
                 VALUES (?, CURDATE(), ?, ?, ?)"
            )->execute([$clienteId, $montoCredito, 'Venta N° ' . $folio, $ventaId]);
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

/**
 * Anula una venta del turno abierto.
 *
 * Solo se pueden anular ventas de una caja TODAVÍA ABIERTA. Una vez cerrada,
 * su corte ya está registrado en Ingresos y anular reescribiría historia
 * contable: para eso corresponde una devolución, que saca la plata de la caja
 * de hoy. El mensaje de error se lo explica al usuario.
 *
 * Si la venta había generado un fiado, se elimina: el cliente nunca debió esa
 * plata. Queda el rastro en la auditoría.
 *
 * El arqueo se corrige solo, porque resumen_caja() solo cuenta las ventas
 * 'completada'.
 */
function anular_venta(PDO $pdo, array $usuario, int $ventaId, string $motivo): array
{
    $motivo = trim($motivo);
    if ($motivo === '') {
        throw new RuntimeException('Escribe el motivo de la anulación.');
    }

    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "SELECT v.*, s.estado AS estado_caja, s.usuario_apertura
               FROM ventas v
               LEFT JOIN caja_sesiones s ON s.id = v.caja_sesion_id
              WHERE v.id = ? FOR UPDATE"
        );
        $st->execute([$ventaId]);
        $v = $st->fetch();

        if (!$v) throw new RuntimeException('Esa venta ya no existe.');
        if ($v['estado'] === 'anulada') {
            throw new RuntimeException('Esa venta ya estaba anulada.');
        }
        if (($v['estado_caja'] ?? '') !== 'abierta') {
            throw new RuntimeException(
                'No se puede anular una venta de una caja ya cerrada: su corte ya quedó '
                . 'registrado. Para devolverle la plata al cliente, registra una salida '
                . 'de efectivo (F8) indicando la venta N° ' . $v['folio'] . '.'
            );
        }
        if (!puede_ver_negocio($usuario, (int)$v['negocio_id'])) {
            throw new RuntimeException('No tienes acceso a esa venta.');
        }

        // Revierte el fiado que hubiera generado esta venta.
        $fiado = 0.0;
        $stF = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM fiados WHERE venta_id = ?");
        $stF->execute([$ventaId]);
        $fiado = (float)$stF->fetchColumn();
        if ($fiado > 0) {
            $pdo->prepare("DELETE FROM fiados WHERE venta_id = ?")->execute([$ventaId]);
        }

        $pdo->prepare(
            "UPDATE ventas SET estado='anulada', anulada_en=NOW(), anulada_por=?,
                    motivo_anulacion=? WHERE id=?"
        )->execute([$usuario['id'] ?? null, mb_substr($motivo, 0, 255), $ventaId]);

        if ($propia) $pdo->commit();
        return ['venta' => $v, 'fiado_revertido' => $fiado];
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
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

// ============ Recaudación de servicios (Klap y otros convenios) ============
// CRÍTICO para el balance y el arqueo: la plata que paga el cliente NO es venta
// del negocio (es de la empresa de servicios), pero el efectivo SÍ entra al
// cajón. Ver POS_DISENO.md sección 7.7.

/** Servicios que se pueden pagar. Lista fija por ahora; se puede volver tabla. */
const SERVICIOS_RECAUDACION = [
    'Luz', 'Agua', 'Gas', 'Teléfono/Internet', 'Recarga celular',
    'Cuenta municipal', 'Otro servicio',
];

/** Convenios activos de un negocio. */
function convenios_de(PDO $pdo, int $negocioId): array
{
    $st = $pdo->prepare(
        "SELECT * FROM convenios WHERE negocio_id = ? AND activo = 1 ORDER BY nombre"
    );
    $st->execute([$negocioId]);
    return $st->fetchAll();
}

/**
 * Convenio con el que registrar un pago. Si el negocio todavía no tiene
 * ninguno, crea "Klap" (prepago) para que el vendedor no tenga que configurar
 * nada antes de cobrar la primera cuenta. La gestión de convenios llega en la
 * Etapa 2; esto solo evita que el paso 1D quede bloqueado.
 */
function convenio_por_defecto(PDO $pdo, int $negocioId): array
{
    $lista = convenios_de($pdo, $negocioId);
    if ($lista) return $lista[0];

    $pdo->prepare(
        "INSERT IGNORE INTO convenios (negocio_id, nombre, modalidad) VALUES (?, 'Klap', 'prepago')"
    )->execute([$negocioId]);
    $lista = convenios_de($pdo, $negocioId);
    if (!$lista) throw new RuntimeException('No se pudo preparar el convenio de recaudación.');
    return $lista[0];
}

/** Operación de servicio ya registrada con ese uuid, o null (idempotencia). */
function operacion_por_uuid(PDO $pdo, string $uuid): ?array
{
    $st = $pdo->prepare("SELECT * FROM servicio_operaciones WHERE uuid = ?");
    $st->execute([$uuid]);
    return $st->fetch() ?: null;
}

/**
 * Registra el pago de una cuenta de un cliente.
 *
 * IDEMPOTENTE por uuid, igual que las ventas: reenviar el mismo pago no lo
 * duplica. NO crea una venta ni toca `ventas`; solo deja la operación, que el
 * arqueo suma al efectivo esperado cuando el medio es efectivo.
 */
function registrar_recaudacion(PDO $pdo, array $usuario, array $sesion, string $uuid,
                               int $convenioId, string $servicio, float $monto,
                               string $medioPago, string $referencia = '',
                               ?float $comision = null): array
{
    if ($previa = operacion_por_uuid($pdo, $uuid)) {
        return ['operacion' => $previa, 'duplicada' => true];
    }
    if ($monto <= 0) {
        throw new RuntimeException('El monto de la cuenta debe ser mayor que cero.');
    }
    if (!in_array($medioPago, ['efectivo', 'tarjeta', 'transferencia'], true)) {
        throw new RuntimeException('Medio de pago desconocido: ' . $medioPago);
    }
    // El convenio debe ser del mismo negocio del turno (no se confía en el navegador).
    $st = $pdo->prepare("SELECT * FROM convenios WHERE id = ? AND negocio_id = ? AND activo = 1");
    $st->execute([$convenioId, (int)$sesion['negocio_id']]);
    if (!$st->fetch()) {
        throw new RuntimeException('Convenio inválido para este negocio.');
    }

    $st = $pdo->prepare(
        "INSERT INTO servicio_operaciones
            (uuid, negocio_id, convenio_id, caja_sesion_id, usuario_id, tipo,
             servicio, monto, medio_pago, comision, referencia, fecha)
         VALUES (?,?,?,?,?, 'recaudacion', ?,?,?,?,?, NOW())"
    );
    try {
        $st->execute([$uuid, (int)$sesion['negocio_id'], $convenioId, (int)$sesion['id'],
                      $usuario['id'] ?? null, substr($servicio, 0, 80), $monto, $medioPago,
                      $comision, ($referencia !== '' ? substr($referencia, 0, 120) : null)]);
    } catch (PDOException $e) {
        // 23000 = uuid duplicado: llegó dos veces la misma operación.
        if ($e->getCode() === '23000' && ($previa = operacion_por_uuid($pdo, $uuid))) {
            return ['operacion' => $previa, 'duplicada' => true];
        }
        throw $e;
    }
    $st = $pdo->prepare("SELECT * FROM servicio_operaciones WHERE id = ?");
    $st->execute([(int)$pdo->lastInsertId()]);
    return ['operacion' => $st->fetch(), 'duplicada' => false];
}

/** Pagos de cuentas registrados en el turno, del más nuevo al más viejo. */
function recaudaciones_del_turno(PDO $pdo, int $sesionId, int $limite = 50): array
{
    $st = $pdo->prepare(
        "SELECT o.*, c.nombre AS convenio, u.nombre AS usuario
           FROM servicio_operaciones o
           JOIN convenios c ON c.id = o.convenio_id
           LEFT JOIN usuarios u ON u.id = o.usuario_id
          WHERE o.caja_sesion_id = ? AND o.tipo = 'recaudacion'
          ORDER BY o.id DESC
          LIMIT " . (int)$limite
    );
    $st->execute([$sesionId]);
    return $st->fetchAll();
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
