<?php
/**
 * Endpoints JSON de la pantalla de venta (pos.php).
 *
 *   ?accion=buscar     GET   busca productos del negocio del turno abierto
 *   ?accion=cobrar     POST  registra la venta (idempotente por uuid)
 *   ?accion=espera_*   POST  guardar / tomar / descartar tickets en espera
 *   ?accion=movimiento POST  entrada o salida de efectivo (F7 / F8)
 *
 * Todo exige sesión web con permiso 'ventas' y un turno de caja abierto del
 * propio usuario: el negocio sale del turno, nunca del navegador. Así una caja
 * no puede escribir en un negocio ajeno.
 *
 * Diseño completo del módulo: web/POS_DISENO.md
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/pos.php';
require __DIR__ . '/lib/auditoria.php';

$usuario = exigir_login();
if (!puede($usuario, 'ventas')) {
    responder_json(['error' => 'Sin permiso para vender.'], 403);
}

$pdo = obtener_pdo();
$accion = $_GET['accion'] ?? '';

// Todas las acciones necesitan un turno abierto (caja obligatoria por diseño).
$sesion = sesion_abierta_de($pdo, (int)$usuario['id']);
if (!$sesion) {
    responder_json(['error' => 'No tienes una caja abierta.', 'sin_caja' => true], 409);
}
$negocioId = (int)$sesion['negocio_id'];
if (!puede_ver_negocio($usuario, $negocioId)) {
    responder_json(['error' => 'No tienes acceso a ese negocio.'], 403);
}

/** Lee el cuerpo JSON de la petición. */
function cuerpo_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

$esPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

try {
    // ---------- Buscar productos (escáner o búsqueda por nombre) ----------
    if ($accion === 'buscar') {
        $q = (string)($_GET['q'] ?? '');
        $filas = buscar_producto_pos($pdo, $negocioId, $q);
        $out = array_map(fn($p) => [
            'producto_id'     => (int)$p['id'],
            'codigo'          => $p['codigo'],
            'nombre'          => $p['nombre'],
            'precio_unitario' => (float)$p['precio_venta'],
            'costo_unitario'  => $p['precio_costo'] !== null ? (float)$p['precio_costo'] : null,
            'departamento'    => $p['departamento'],
            'afecto_iva'      => (int)$p['afecto_iva'],
        ], $filas);
        // exacto = un único resultado por código: la caja lo agrega directo.
        responder_json([
            'ok' => true,
            'exacto' => (count($out) === 1 && strcasecmp((string)$out[0]['codigo'], trim($q)) === 0),
            'resultados' => $out,
        ]);
    }

    if (!$esPost) {
        responder_json(['error' => 'Método no permitido.'], 405);
    }
    $datos = cuerpo_json();

    // ---------- Cobrar ----------
    if ($accion === 'cobrar') {
        $uuid  = trim((string)($datos['uuid'] ?? ''));
        if ($uuid === '') responder_json(['error' => 'Falta el identificador de la venta.'], 400);

        $items = $datos['items'] ?? [];
        $medio = (string)($datos['medio'] ?? 'efectivo');
        if (!in_array($medio, ['efectivo', 'tarjeta', 'transferencia'], true)) {
            // 'credito' (fiado) y el pago mixto llegan en la Etapa 2.
            responder_json(['error' => 'Medio de pago no disponible todavía.'], 400);
        }

        // El total se recalcula en registrar_venta(); acá solo armamos el pago.
        $total = 0.0;
        foreach ($items as $it) {
            $total += ((float)($it['cantidad'] ?? 0)) * ((float)($it['precio_unitario'] ?? 0))
                    - (float)($it['descuento'] ?? 0);
        }
        $total = round($total, 2);
        $pagoCon = isset($datos['pago_con']) && $datos['pago_con'] !== ''
                 ? (float)$datos['pago_con'] : null;
        if ($medio === 'efectivo' && $pagoCon !== null && $pagoCon + 1 < $total) {
            responder_json(['error' => 'El monto recibido es menor que el total.'], 400);
        }

        $r = registrar_venta($pdo, $usuario, $sesion, $uuid, $items,
                             [['medio' => $medio, 'monto' => $total]], $pagoCon);
        $v = $r['venta'];
        if (!$r['duplicada']) {
            registrar_auditoria($pdo, $usuario, 'ventas', 'venta', null,
                'Venta N° ' . $v['folio'] . ' por ' . clp($v['total']) . ' (' . $medio . ')');
        }
        responder_json([
            'ok' => true,
            'duplicada' => $r['duplicada'],
            'venta' => [
                'id' => (int)$v['id'], 'folio' => (int)$v['folio'],
                'total' => (float)$v['total'],
                'pago_con' => $v['pago_con'] !== null ? (float)$v['pago_con'] : null,
                'vuelto' => (float)$v['vuelto'],
            ],
        ]);
    }

    // ---------- Tickets en espera ----------
    if ($accion === 'espera_guardar') {
        $items = $datos['items'] ?? [];
        $total = 0.0;
        foreach ($items as $it) {
            $total += ((float)($it['cantidad'] ?? 0)) * ((float)($it['precio_unitario'] ?? 0))
                    - (float)($it['descuento'] ?? 0);
        }
        $t = guardar_espera($pdo, $usuario, $sesion, $items,
                            trim((string)($datos['etiqueta'] ?? '')), round($total, 2));
        responder_json(['ok' => true, 'ticket' => $t, 'espera' => listar_espera($pdo, (int)$sesion['id'])]);
    }

    if ($accion === 'espera_listar') {
        responder_json(['ok' => true, 'espera' => listar_espera($pdo, (int)$sesion['id'])]);
    }

    if ($accion === 'espera_tomar') {
        $t = tomar_espera($pdo, (int)$sesion['id'], (int)($datos['id'] ?? 0));
        responder_json(['ok' => true, 'ticket' => $t,
                        'espera' => listar_espera($pdo, (int)$sesion['id'])]);
    }

    if ($accion === 'espera_descartar') {
        $desc = descartar_espera($pdo, (int)$sesion['id'], (int)($datos['id'] ?? 0));
        registrar_auditoria($pdo, $usuario, 'ventas', 'descartar_espera', null,
            'Descartó un ticket en espera: ' . $desc);
        responder_json(['ok' => true, 'espera' => listar_espera($pdo, (int)$sesion['id'])]);
    }

    // ---------- Entrada / salida de efectivo (F7 / F8) ----------
    if ($accion === 'movimiento') {
        $tipo = ($datos['tipo'] ?? '') === 'entrada' ? 'entrada' : 'salida';
        $monto = parsear_monto((string)($datos['monto'] ?? ''));
        if ($monto === null || $monto <= 0) {
            responder_json(['error' => 'Indica un monto válido mayor que cero.'], 400);
        }
        $motivo = trim((string)($datos['motivo'] ?? ''));
        if ($tipo === 'salida' && $motivo === '') {
            responder_json(['error' => 'Escribe el motivo de la salida.'], 400);
        }
        registrar_movimiento_caja($pdo, $usuario, (int)$sesion['id'], $tipo, $monto, $motivo);
        registrar_auditoria($pdo, $usuario, 'ventas', 'movimiento_caja', null,
            ucfirst($tipo) . ' de efectivo por ' . clp($monto) . ($motivo !== '' ? " ($motivo)" : ''));
        responder_json(['ok' => true, 'mensaje' => ucfirst($tipo) . ' registrada: ' . clp($monto)]);
    }

    // ---------- Pago de cuenta (recaudación de un convenio: Klap y otros) ----------
    if ($accion === 'recaudacion') {
        $uuid = trim((string)($datos['uuid'] ?? ''));
        if ($uuid === '') responder_json(['error' => 'Falta el identificador de la operación.'], 400);

        $monto = parsear_monto((string)($datos['monto'] ?? ''));
        if ($monto === null || $monto <= 0) {
            responder_json(['error' => 'Escribe el monto de la cuenta.'], 400);
        }
        $convenio = (int)($datos['convenio_id'] ?? 0)
                  ?: (int)convenio_por_defecto($pdo, $negocioId)['id'];
        $comision = ($datos['comision'] ?? '') !== ''
                  ? parsear_monto((string)$datos['comision']) : null;

        $r = registrar_recaudacion(
            $pdo, $usuario, $sesion, $uuid, $convenio,
            trim((string)($datos['servicio'] ?? 'Otro servicio')), $monto,
            (string)($datos['medio_pago'] ?? 'efectivo'),
            trim((string)($datos['referencia'] ?? '')), $comision
        );
        $o = $r['operacion'];
        if (!$r['duplicada']) {
            registrar_auditoria($pdo, $usuario, 'ventas', 'recaudacion', null,
                'Pago de cuenta (' . $o['servicio'] . ') por ' . clp($o['monto']) .
                ' en ' . $o['medio_pago']);
        }
        responder_json([
            'ok' => true,
            'duplicada' => $r['duplicada'],
            'operacion' => [
                'id' => (int)$o['id'], 'servicio' => $o['servicio'],
                'monto' => (float)$o['monto'], 'medio_pago' => $o['medio_pago'],
            ],
            'resumen' => resumen_caja($pdo, (int)$sesion['id']),
        ]);
    }

    responder_json(['error' => 'Acción desconocida.'], 400);

} catch (RuntimeException $e) {
    responder_json(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('pos_api: ' . $e->getMessage());
    responder_json(['error' => 'Ocurrió un error al procesar la operación.'], 500);
}
