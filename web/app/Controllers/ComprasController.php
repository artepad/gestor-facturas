<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Compra;
use App\Models\Inventario;

/**
 * Compras: registrar la mercadería que llega (suma stock si el negocio lo
 * lleva) y proveedores.
 *
 * Fase 9 (despliegue): vive en web/compras.php (archivo puente que llama
 * directo a este Controlador) — misma direccion de siempre.
 */
final class ComprasController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('productos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';      // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // clp(), icono(), parsear_monto(), fecha_dmy()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php'; // registrar_auditoria()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

        $negocioId = Request::entero('negocio_id') ?: ($idsVisibles[0] ?? 0);
        if (!in_array($negocioId, $idsVisibles, true)) {
            $negocioId = $idsVisibles[0] ?? 0;
        }

        $error = '';
        $aviso = '';

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('compras.php');
            $accion = Request::post('accion');
            try {
                $neg = Request::entero('negocio_id');
                if (!in_array($neg, $idsVisibles, true)) {
                    throw new \RuntimeException('Negocio inválido.');
                }

                if ($accion === 'proveedor') {
                    $nombre = Request::post('nombre');
                    if ($nombre === '') throw new \RuntimeException('Escribe el nombre del proveedor.');
                    $id = Compra::crearProveedor(
                        (isset($_POST['ambos']) && $_POST['ambos']) ? null : $neg,
                        $nombre,
                        Request::post('rut') ?: null,
                        Request::post('telefono') ?: null,
                        Request::post('correo') ?: null
                    );
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'crear_proveedor', null,
                        'Creó el proveedor ' . $nombre, null, 'proveedor', $id);
                    \redirigir('compras.php?negocio_id=' . $neg . '&msg=proveedor');
                }

                if ($accion === 'compra') {
                    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', Request::post('fecha'))
                           ? Request::post('fecha') : date('Y-m-d');
                    if ($fecha > date('Y-m-d')) throw new \RuntimeException('La fecha no puede ser futura.');

                    $texto = trim(Request::post('detalle'));
                    if ($texto === '') throw new \RuntimeException('Pega lo que llegó (un producto por línea).');
                    $items = [];
                    foreach (preg_split('/\R/', $texto) as $linea) {
                        $linea = trim($linea);
                        if ($linea === '') continue;
                        $p = preg_split('/[\s;]+/', $linea, -1, PREG_SPLIT_NO_EMPTY);
                        if (count($p) < 2) continue;
                        $cod = $p[0];
                        $cant = \parsear_monto($p[1]);
                        $costo = isset($p[2]) ? \parsear_monto($p[2]) : null;
                        if ($cant === null || $cant <= 0) continue;
                        $items[] = ['codigo' => $cod, 'cantidad' => $cant, 'costo_unitario' => $costo];
                    }
                    if (!$items) throw new \RuntimeException('No se entendió ninguna línea. Usa: código cantidad [costo].');

                    $r = Compra::registrar($usuario, $neg,
                                          Request::entero('proveedor_id') ?: null,
                                          $fecha, $items, Request::post('nota'),
                                          Request::entero('factura_id') ?: null);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'compra', null,
                        'Registró una compra de ' . $r['cargados'] . ' producto(s)'
                        . ($r['total'] > 0 ? ' por ' . \clp($r['total']) : ''),
                        null, 'compra', $r['id']);
                    $aviso = 'Compra registrada: ' . $r['cargados'] . ' producto(s)'
                           . (Inventario::controla($neg) ? ' sumados al stock.' : ' (el inventario está apagado, no se movió stock).')
                           . ($r['no_encontrados'] ? ' No están en el catálogo: '
                               . implode(', ', array_slice($r['no_encontrados'], 0, 6))
                               . (count($r['no_encontrados']) > 6 ? ' y ' . (count($r['no_encontrados']) - 6) . ' más' : '') . '.' : '');
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        if (Request::get('msg') === 'proveedor') $aviso = 'Proveedor creado.';

        $controla = Inventario::controla($negocioId);
        $proveedores = Compra::proveedoresDe($negocioId);
        $compras = Compra::listadas($negocioId);
        $verId = Request::entero('ver');
        $detalle = $verId ? Compra::items($verId) : [];
        $facturas = Compra::facturasRecientes($negocioId);

        View::render('compras/index', compact(
            'usuario', 'negocios', 'negocioId', 'error', 'aviso', 'controla', 'proveedores',
            'compras', 'verId', 'detalle', 'facturas'
        ));
    }
}
