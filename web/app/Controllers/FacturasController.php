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
 *   factura.php?id=N       -> detalle()  (cabecera + líneas de producto + Ver PDF
 *                                          + Editar/Eliminar, desde 2026-11-13)
 *
 * panel() no tiene POST propio (el guardado de "Agregar factura" ocurre por
 * fetch() contra FacturaIaController, JSON) pero igual pasa un $csrfToken a
 * la vista (mismo criterio que PosController con pos_api.php). detalle() SÍ
 * tiene POST propio desde que se agregaron Editar/Eliminar — mismo patrón
 * que FiadosController::ficha(): un `accion` del POST, solo-admin
 * revalidado en el backend, auditoría de cada cambio.
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

        $filtros = [
            'negocio' => $fNegocio, 'proveedor' => $fProveedor,
            'desde' => $fDesde, 'hasta' => $fHasta, 'texto' => $fTexto,
        ];

        $pagina = max(1, Request::entero('p', 1));
        $porPag = 20;
        $total = Factura::contarListado($idsVisibles, $filtros);
        $paginas = max(1, (int)ceil($total / $porPag));
        $pagina = min($pagina, $paginas);

        $facturas = Factura::listado($idsVisibles, $filtros, $pagina, $porPag);
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
            'facturas', 'proveedores', 'viendo', 'csrfToken', 'pagina', 'porPag', 'total', 'paginas'
        ));
    }

    public function detalle(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('facturas');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';      // puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // clp(), icono(), fecha_dmy(), estado_factura(), parsear_monto()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php'; // registrar_auditoria(), cambios_json()

        $esAdmin = ($usuario['rol'] ?? '') === 'admin';

        $id = Request::entero('id');
        if (!$id) {
            \redirigir('panel_facturas.php');
        }

        $f = Factura::porId($id);
        if (!$f || !\puede_ver_negocio($usuario, (int)$f['negocio_id'])) {
            \redirigir('panel_facturas.php');
        }

        $error = '';
        $edicion = null; // valores enviados, para re-mostrar en el modal si falla la validación

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('factura.php?id=' . $id);

            // Editar/eliminar son solo para admin (mismo criterio que
            // Fiados/Gastos: información financiera). La UI ya oculta los
            // botones a un vendedor, pero se bloquea también en el backend.
            if (!$esAdmin) {
                \redirigir('factura.php?id=' . $id);
            }

            $accion = Request::post('accion');

            if ($accion === 'editar') {
                $edicion = [
                    'proveedor'      => Request::post('proveedor'),
                    'razon_social'   => Request::post('razon_social'),
                    'rut_emisor'     => Request::post('rut_emisor'),
                    'numero_factura' => Request::post('numero_factura'),
                    'fecha'          => Request::post('fecha'),
                    'total'          => Request::post('total'),
                    'notas'          => Request::post('notas'),
                ];
                $total = \parsear_monto($edicion['total']);

                if ($edicion['proveedor'] === '') {
                    $error = 'El proveedor no puede quedar vacío.';
                } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $edicion['fecha'])) {
                    $error = 'La fecha no es válida.';
                } elseif ($edicion['fecha'] > date('Y-m-d')) {
                    $error = 'La fecha no puede ser futura.';
                } elseif ($total === null || $total <= 0) {
                    $error = 'Ingresa un total válido, mayor a 0.';
                } else {
                    $campos = [
                        'proveedor'      => $edicion['proveedor'],
                        'razon_social'   => $edicion['razon_social'] !== '' ? $edicion['razon_social'] : null,
                        'rut_emisor'     => $edicion['rut_emisor'] !== '' ? $edicion['rut_emisor'] : null,
                        'numero_factura' => $edicion['numero_factura'] !== '' ? $edicion['numero_factura'] : null,
                        'fecha'          => $edicion['fecha'],
                        'total'          => $total,
                        'notas'          => $edicion['notas'] !== '' ? $edicion['notas'] : null,
                    ];
                    Factura::actualizar($id, $campos);
                    \registrar_auditoria(
                        \App\Core\Database::pdo(), $usuario, 'facturas', 'editar_factura', null,
                        "Editó la factura {$f['numero_factura']} de {$f['proveedor']}",
                        \cambios_json($f, $campos, array_keys($campos)), 'factura', $id
                    );
                    \redirigir('factura.php?id=' . $id);
                }
            } elseif ($accion === 'eliminar') {
                Factura::eliminar($id);
                \registrar_auditoria(
                    \App\Core\Database::pdo(), $usuario, 'facturas', 'eliminar_factura', null,
                    "Eliminó la factura {$f['numero_factura']} de {$f['proveedor']} (" . \clp((float)$f['total']) . ")",
                    json_encode($f, JSON_UNESCAPED_UNICODE), 'factura', $id
                );
                \redirigir('panel_facturas.php');
            }
        }

        $detalle = Factura::detalleDe($id);
        [$color, $txtEstado] = \estado_factura($f);

        View::render('facturas/detalle', compact(
            'usuario', 'f', 'detalle', 'color', 'txtEstado', 'esAdmin', 'error', 'edicion'
        ));
    }
}
