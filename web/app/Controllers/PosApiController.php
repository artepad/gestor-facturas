<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Models\CajaSesion;
use App\Models\Convenio;
use App\Models\Promocion;
use App\Models\Venta;

/**
 * Endpoints JSON de la pantalla de venta. Corte 2b de la Fase 6 (el más
 * delicado: acá está el dinero), ver web/docs/bitacora-migracion.md.
 *
 * web/pos_api.php sigue intacto y es al que la vista (/public/pos/) le
 * sigue hablando hasta que CADA una de estas 13 acciones se verifique por
 * separado contra el original. Recién ahí se cambia a dónde apunta la
 * vista — no antes.
 *
 * NO extiende BaseController: ese está pensado para pantallas HTML que
 * redirigen (login.php, home.php); acá, igual que el original, cualquier
 * falla de sesión/permiso/CSRF responde JSON con su código HTTP, nunca
 * una redirección (un fetch() no "sigue" un redirect como lo haría un
 * navegador con un formulario).
 */
final class PosApiController
{
    public function index(array $parametros = []): void
    {
        require_once dirname(__DIR__, 2) . '/lib/db.php';        // responder_json()
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/permisos.php';
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // clp(), parsear_monto()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php';

        $usuario = \usuario_actual();
        if (!$usuario || !\puede($usuario, 'ventas')) {
            \responder_json(['error' => 'Sin permiso para vender.'], 403);
        }

        // Toda acción necesita un turno abierto (caja obligatoria por diseño).
        // El negocio sale SIEMPRE del turno, nunca del navegador.
        $sesion = CajaSesion::abiertaDe((int)$usuario['id']);
        if (!$sesion) {
            \responder_json(['error' => 'No tienes una caja abierta.', 'sin_caja' => true], 409);
        }
        $negocioId = (int)$sesion['negocio_id'];
        if (!\puede_ver_negocio($usuario, $negocioId)) {
            \responder_json(['error' => 'No tienes acceso a ese negocio.'], 403);
        }
        // Caja Express: celular/tablet sin efectivo (ver CLAUDE.md, módulo
        // Ventas/POS). No se confía en lo que oculte el navegador: estas
        // acciones se rechazan acá, en el servidor, para cualquier sesión
        // marcada 'express', la haya abierto quien la haya abierto.
        $esExpress = ($sesion['tipo'] ?? 'fisica') === 'express';

        $accion = Request::get('accion');
        $esPost = Request::metodo() === 'POST';

        try {
            // ---------- Catálogo completo (la caja lo guarda en el PC) ----------
            if ($accion === 'catalogo') {
                $firma = Venta::firmaCatalogo();
                if (($_GET['firma'] ?? '') === $firma) {
                    \responder_json(['ok' => true, 'sin_cambios' => true, 'firma' => $firma]);
                }
                \responder_json([
                    'ok' => true,
                    'sin_cambios' => false,
                    'firma' => $firma,
                    'productos' => Venta::catalogoParaPos(),
                    'promos' => Promocion::vigentes($negocioId),
                ]);
            }

            if ($accion === 'promos') {
                \responder_json(['ok' => true, 'promos' => Promocion::vigentes($negocioId)]);
            }

            if ($accion === 'buscar') {
                $q = Request::get('q');
                $filas = Venta::buscarProducto($q);
                $out = array_map(fn($p) => [
                    'producto_id'     => (int)$p['id'],
                    'codigo'          => $p['codigo'],
                    'nombre'          => $p['nombre'],
                    'precio_unitario' => (float)$p['precio_venta'],
                    'costo_unitario'  => $p['precio_costo'] !== null ? (float)$p['precio_costo'] : null,
                    'departamento'    => $p['departamento'],
                    'afecto_iva'      => (int)$p['afecto_iva'],
                ], $filas);
                \responder_json([
                    'ok' => true,
                    'exacto' => (count($out) === 1 && strcasecmp((string)$out[0]['codigo'], trim($q)) === 0),
                    'resultados' => $out,
                ]);
            }

            // ---------- Ventas del día (todas las cajas, todas las vendedoras) ----------
            if ($accion === 'ventas_dia') {
                $fecha = (string)Request::get('fecha');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                    $fecha = date('Y-m-d');
                }
                $out = array_map(fn($v) => [
                    'id'           => (int)$v['id'],
                    'folio'        => (int)$v['folio'],
                    'vendedor'     => $v['vendedor'] ?: '—',
                    'n_items'      => (int)$v['n_items'],
                    'total'        => (float)$v['total'],
                    'hora'         => substr((string)$v['fecha'], 11, 5),
                    'estado'       => $v['estado'],
                    'motivo'       => $v['motivo_anulacion'],
                    'caja_abierta' => ($v['estado_caja'] ?? '') === 'abierta',
                ], Venta::delDia($negocioId, $fecha));
                \responder_json(['ok' => true, 'fecha' => $fecha, 'ventas' => $out]);
            }

            // ---------- Detalle de una venta (panel derecho) ----------
            if ($accion === 'venta_detalle') {
                $v = Venta::detalle($negocioId, (int)Request::get('id'));
                if (!$v) \responder_json(['error' => 'Esa venta ya no existe.'], 404);
                \responder_json(['ok' => true, 'venta' => [
                    'id'           => (int)$v['id'],
                    'folio'        => (int)$v['folio'],
                    'vendedor'     => $v['vendedor'] ?: '—',
                    'total'        => (float)$v['total'],
                    'pago_con'     => $v['pago_con'] !== null ? (float)$v['pago_con'] : null,
                    'estado'       => $v['estado'],
                    'motivo'       => $v['motivo_anulacion'],
                    'caja_abierta' => ($v['estado_caja'] ?? '') === 'abierta',
                    'items'        => array_map(fn($i) => [
                        'id'        => (int)$i['id'],
                        'nombre'    => $i['nombre'],
                        'cantidad'  => (float)$i['cantidad'],
                        'precio'    => (float)$i['precio_unitario'],
                        'total'     => (float)$i['total'],
                        'devuelto'  => $i['devuelto_en'] !== null,
                    ], $v['items']),
                    'pagos' => array_map(fn($p) => [
                        'medio' => $p['medio'], 'monto' => (float)$p['monto'],
                    ], $v['pagos']),
                ]]);
            }

            if ($accion === 'clientes') {
                \responder_json(['ok' => true, 'clientes' => Venta::clientesParaFiar($negocioId)]);
            }

            if (!$esPost) {
                \responder_json(['error' => 'Método no permitido.'], 405);
            }
            $datos = $this->cuerpoJson();

            // CSRF: solo acá, porque de este punto en adelante todo ESCRIBE.
            // Las lecturas de arriba son GET y no necesitan token.
            if (!Session::csrfValido($datos['_token'] ?? null)) {
                \responder_json(['error' => 'La sesión del navegador se venció. Recarga la página.'], 403);
            }

            // ---------- Cobrar ----------
            if ($accion === 'cobrar') {
                $uuid = trim((string)($datos['uuid'] ?? ''));
                if ($uuid === '') \responder_json(['error' => 'Falta el identificador de la venta.'], 400);

                $items = $datos['items'] ?? [];
                // Sin "efectivo" en la lista, cualquier intento de pagar en
                // efectivo cae solo en el "Medio de pago desconocido" de más
                // abajo — mismo mensaje, sin duplicar la validación.
                $MEDIOS = $esExpress
                        ? ['tarjeta', 'transferencia', 'credito']
                        : ['efectivo', 'tarjeta', 'transferencia', 'credito'];

                // Total CON promociones, igual que registrar_venta(): si se usara
                // el precio de lista, los pagos no cuadrarían con lo que el
                // cliente realmente debe pagar.
                $total = round(Promocion::totalDelCarrito($negocioId, $items), 2);

                $pagos = [];
                if (!empty($datos['pagos']) && is_array($datos['pagos'])) {
                    foreach ($datos['pagos'] as $p) {
                        $m = (string)($p['medio'] ?? '');
                        $mo = round((float)($p['monto'] ?? 0), 2);
                        if (!in_array($m, $MEDIOS, true)) {
                            \responder_json(['error' => $esExpress && $m === 'efectivo'
                                ? 'La Caja Express no acepta efectivo.'
                                : 'Medio de pago desconocido: ' . $m], 400);
                        }
                        if ($mo <= 0) continue;
                        $pagos[] = ['medio' => $m, 'monto' => $mo,
                                    'referencia' => trim((string)($p['referencia'] ?? ''))];
                    }
                } else {
                    $medio = (string)($datos['medio'] ?? ($esExpress ? 'tarjeta' : 'efectivo'));
                    if (!in_array($medio, $MEDIOS, true)) {
                        \responder_json(['error' => $esExpress && $medio === 'efectivo'
                            ? 'La Caja Express no acepta efectivo.'
                            : 'Medio de pago desconocido: ' . $medio], 400);
                    }
                    $pagos = [['medio' => $medio, 'monto' => $total]];
                }
                if (!$pagos) \responder_json(['error' => 'Indica cómo paga el cliente.'], 400);

                $sumaPagos = 0.0;
                $efectivo = 0.0;
                foreach ($pagos as $p) {
                    $sumaPagos += $p['monto'];
                    if ($p['medio'] === 'efectivo') $efectivo += $p['monto'];
                }
                if (abs($sumaPagos - $total) > 1) {
                    $falta = $total - $sumaPagos;
                    \responder_json(['error' => $falta > 0
                        ? 'Faltan ' . \clp($falta) . ' por cubrir.'
                        : 'Los pagos superan el total en ' . \clp(-$falta) . '.'], 400);
                }

                $pagoCon = isset($datos['pago_con']) && $datos['pago_con'] !== ''
                         ? (float)$datos['pago_con'] : null;
                if ($pagoCon !== null && $efectivo > 0 && $pagoCon + 1 < $efectivo) {
                    \responder_json(['error' => 'El efectivo recibido es menor que la parte en efectivo.'], 400);
                }
                if ($efectivo <= 0) $pagoCon = null;

                $clienteId = isset($datos['cliente_id']) && $datos['cliente_id']
                           ? (int)$datos['cliente_id'] : null;

                $r = Venta::registrar($usuario, $sesion, $uuid, $items, $pagos, $pagoCon, 'online', '', $clienteId);
                $v = $r['venta'];
                if (!$r['duplicada']) {
                    $desc = [];
                    foreach ($pagos as $p) $desc[] = $p['medio'] . ' ' . \clp($p['monto']);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'venta', $clienteId,
                        'Venta N° ' . $v['folio'] . ' por ' . \clp($v['total']) .
                        ' (' . implode(' + ', $desc) . ')', null, 'venta', (int)$v['id']);
                }
                \responder_json([
                    'ok' => true,
                    'duplicada' => $r['duplicada'],
                    'venta' => [
                        'id' => (int)$v['id'], 'folio' => (int)$v['folio'],
                        'total' => (float)$v['total'],
                        'pago_con' => $v['pago_con'] !== null ? (float)$v['pago_con'] : null,
                        'vuelto' => (float)$v['vuelto'],
                        'fiado'  => array_sum(array_map(
                            fn($p) => $p['medio'] === 'credito' ? $p['monto'] : 0, $pagos)),
                        'medios' => implode('+', array_map(fn($p) => $p['medio'], $pagos)),
                    ],
                ]);
            }

            // ---------- Anular una venta del turno ----------
            if ($accion === 'anular') {
                $r = Venta::anular($usuario, (int)($datos['id'] ?? 0), (string)($datos['motivo'] ?? ''));
                $v = $r['venta'];
                \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'anular_venta', null,
                    'Anuló la venta N° ' . $v['folio'] . ' de ' . \clp($v['total']) .
                    ' · Motivo: ' . trim((string)$datos['motivo']),
                    json_encode(['total' => $v['total'], 'fiado_revertido' => $r['fiado_revertido']]),
                    'venta', (int)$v['id']);
                \responder_json([
                    'ok' => true,
                    'folio' => (int)$v['folio'],
                    'fiado_revertido' => $r['fiado_revertido'],
                    'resumen' => CajaSesion::resumen((int)$sesion['id']),
                ]);
            }

            // ---------- Devolver UN artículo de una venta (no la venta completa) ----------
            if ($accion === 'devolver_articulo') {
                $r = Venta::devolverArticulo($usuario, (int)($datos['item_id'] ?? 0));
                \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'devolver_articulo', null,
                    'Devolvió "' . $r['nombre'] . '" de la venta N° ' . $r['folio']
                    . ' (' . \clp($r['monto']) . ')');
                \responder_json(['ok' => true, 'folio' => $r['folio'], 'monto' => $r['monto']]);
            }

            // ---------- Tickets en espera ----------
            if ($accion === 'espera_guardar') {
                $items = $datos['items'] ?? [];
                $total = Promocion::totalDelCarrito($negocioId, $items);
                $t = Venta::guardarEspera($usuario, $sesion, $items,
                    trim((string)($datos['etiqueta'] ?? '')), round($total, 2));
                \responder_json(['ok' => true, 'ticket' => $t, 'espera' => Venta::listarEspera((int)$sesion['id'])]);
            }

            if ($accion === 'espera_listar') {
                \responder_json(['ok' => true, 'espera' => Venta::listarEspera((int)$sesion['id'])]);
            }

            if ($accion === 'espera_tomar') {
                $t = Venta::tomarEspera((int)$sesion['id'], (int)($datos['id'] ?? 0));
                \responder_json(['ok' => true, 'ticket' => $t,
                    'espera' => Venta::listarEspera((int)$sesion['id'])]);
            }

            if ($accion === 'espera_descartar') {
                $desc = Venta::descartarEspera((int)$sesion['id'], (int)($datos['id'] ?? 0));
                \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'descartar_espera', null,
                    'Descartó un ticket en espera: ' . $desc);
                \responder_json(['ok' => true, 'espera' => Venta::listarEspera((int)$sesion['id'])]);
            }

            // ---------- Entrada / salida de efectivo (F7 / F8) ----------
            if ($accion === 'movimiento') {
                if ($esExpress) {
                    \responder_json(['error' => 'La Caja Express no maneja efectivo: no hay movimientos que registrar.'], 400);
                }
                $tipo = ($datos['tipo'] ?? '') === 'entrada' ? 'entrada' : 'salida';
                $monto = \parsear_monto((string)($datos['monto'] ?? ''));
                if ($monto === null || $monto <= 0) {
                    \responder_json(['error' => 'Indica un monto válido mayor que cero.'], 400);
                }
                $motivo = trim((string)($datos['motivo'] ?? ''));
                if ($tipo === 'salida' && $motivo === '') {
                    \responder_json(['error' => 'Escribe el motivo de la salida.'], 400);
                }
                CajaSesion::registrarMovimiento($usuario, (int)$sesion['id'], $tipo, $monto, $motivo);
                \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'movimiento_caja', null,
                    ucfirst($tipo) . ' de efectivo por ' . \clp($monto) . ($motivo !== '' ? " ($motivo)" : ''));
                \responder_json(['ok' => true, 'mensaje' => ucfirst($tipo) . ' registrada: ' . \clp($monto)]);
            }

            // ---------- Pago de cuenta (recaudación de un convenio) ----------
            if ($accion === 'recaudacion') {
                if ($esExpress) {
                    \responder_json(['error' => 'La Caja Express no puede registrar pagos de cuenta. Usa una caja Principal o Secundaria.'], 400);
                }
                $uuid = trim((string)($datos['uuid'] ?? ''));
                if ($uuid === '') \responder_json(['error' => 'Falta el identificador de la operación.'], 400);

                $monto = \parsear_monto((string)($datos['monto'] ?? ''));
                if ($monto === null || $monto <= 0) {
                    \responder_json(['error' => 'Escribe el monto de la cuenta.'], 400);
                }
                $convenioId = (int)($datos['convenio_id'] ?? 0) ?: (int)Convenio::porDefecto($negocioId)['id'];
                $comision = ($datos['comision'] ?? '') !== ''
                          ? \parsear_monto((string)$datos['comision']) : null;

                $r = Convenio::registrarRecaudacion(
                    $usuario, $sesion, $uuid, $convenioId,
                    trim((string)($datos['servicio'] ?? 'Otro servicio')), $monto,
                    (string)($datos['medio_pago'] ?? 'efectivo'),
                    trim((string)($datos['referencia'] ?? '')), $comision
                );
                $o = $r['operacion'];
                if (!$r['duplicada']) {
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'recaudacion', null,
                        'Pago de cuenta (' . $o['servicio'] . ') por ' . \clp($o['monto']) .
                        ' en ' . $o['medio_pago']);
                }
                $conv = Convenio::porId($convenioId);
                $k = $conv ? Convenio::saldo($conv) : null;

                \responder_json([
                    'ok' => true,
                    'duplicada' => $r['duplicada'],
                    'operacion' => [
                        'id' => (int)$o['id'], 'servicio' => $o['servicio'],
                        'monto' => (float)$o['monto'], 'medio_pago' => $o['medio_pago'],
                    ],
                    'saldo' => $k ? [
                        'convenio'   => $conv['nombre'],
                        'monto'      => $k['saldo'],
                        'bajo'       => $k['bajo'],
                        'es_prepago' => $k['es_prepago'],
                    ] : null,
                    'resumen' => CajaSesion::resumen((int)$sesion['id']),
                ]);
            }

            \responder_json(['error' => 'Acción desconocida.'], 400);
        } catch (\RuntimeException $e) {
            \responder_json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('pos_api (nuevo, corte 2b): ' . $e->getMessage());
            \responder_json(['error' => 'Ocurrió un error al procesar la operación.'], 500);
        }
    }

    /** Lee el cuerpo JSON de la petición (igual que pos_api.php). */
    private function cuerpoJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
}
