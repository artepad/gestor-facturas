<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Factura;

/**
 * Facturas: espejo de las sincronizadas desde la app de escritorio, más
 * (desde 2026-11-12) la subida directa con IA, integrada en la misma
 * pantalla del listado (sin navegar a otra página — ver "Agregar factura"
 * en app/views/facturas/panel.php).
 *   panel_facturas.php     -> panel()    (listado + filtros + agregar factura)
 *   factura.php?id=N       -> detalle()  (cabecera + líneas de producto + Ver PDF)
 *
 * Las dos son de solo lectura en el sentido de no tener un POST propio (sin
 * formulario con estado que vuelva a esta misma pantalla) — el guardado real
 * de "Agregar factura" ocurre por fetch() contra FacturaIaController (JSON),
 * por eso panel() igual pasa un $csrfToken a la vista (mismo criterio que
 * PosController con pos_api.php).
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

        $csrfToken = Session::tokenCsrf();

        View::render('facturas/panel', compact(
            'usuario', 'negocios', 'fNegocio', 'fProveedor', 'fDesde', 'fHasta', 'fTexto',
            'facturas', 'proveedores', 'viendo', 'csrfToken'
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
