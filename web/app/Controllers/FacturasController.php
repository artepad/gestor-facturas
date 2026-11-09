<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Factura;

/**
 * Facturas (espejo de las sincronizadas desde la app de escritorio).
 *   panel_facturas.php     -> panel()    (listado + filtros)
 *   factura.php?id=N       -> detalle()  (cabecera + líneas de producto + Ver PDF)
 *
 * Ambas pantallas son de solo lectura (sin POST, sin formularios con
 * estado) — no hay nada que proteger con CSRF.
 */
final class FacturasController extends BaseController
{
    public function panel(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('facturas');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono(), fecha_dmy(), estado_factura()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

        $fNegocio = Request::entero('negocio');
        $fProveedor = Request::get('proveedor');
        $fDesde = Request::get('desde');
        $fHasta = Request::get('hasta');
        $fTexto = Request::get('q');

        $facturas = Factura::listado($idsVisibles, [
            'negocio' => $fNegocio, 'proveedor' => $fProveedor,
            'desde' => $fDesde, 'hasta' => $fHasta, 'texto' => $fTexto,
        ]);
        $proveedores = Factura::proveedoresDe($idsVisibles);

        $nombrePorId = [];
        foreach ($negocios as $n) { $nombrePorId[(int)$n['id']] = $n['nombre']; }
        if ($fNegocio && isset($nombrePorId[$fNegocio])) {
            $viendo = $nombrePorId[$fNegocio];
        } elseif (count($negocios) === 1) {
            $viendo = $negocios[0]['nombre'];
        } else {
            $viendo = 'Todos los negocios';
        }

        View::render('facturas/panel', compact(
            'usuario', 'negocios', 'fNegocio', 'fProveedor', 'fDesde', 'fHasta', 'fTexto',
            'facturas', 'proveedores', 'viendo'
        ));
    }

    public function detalle(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('facturas');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono(), fecha_dmy(), estado_factura()

        $id = Request::entero('id');
        if (!$id) {
            \redirigir('panel_facturas.php');
        }

        $f = Factura::porId($id);
        if (!$f || !\puede_ver_negocio($usuario, (int)$f['negocio_id'])) {
            \redirigir('panel_facturas.php');
        }

        $detalle = Factura::detalleDe($id);
        [$color, $txtEstado] = \estado_factura($f);

        View::render('facturas/detalle', compact('usuario', 'f', 'detalle', 'color', 'txtEstado'));
    }
}
