<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Gasto;

/**
 * Gastos operacionales del negocio (agua, luz, gas, sueldos, arriendo...).
 * Solo administrador.
 *   gastos.php              -> panel()       (dashboard del período + generar fijos del mes)
 *   gasto_form.php[?id=N]   -> formulario()  (crear/editar/eliminar un gasto)
 *   categorias_gasto.php    -> categorias()  (CRUD de categorías)
 *   gastos_fijos.php        -> fijos()       (CRUD de plantillas de gasto fijo mensual)
 *
 * Igual que Fiados: el código viejo no tenía CSRF en estos formularios, acá
 * se agrega. lib/gastos.php NO se toca (lo comparten home.php y
 * negocio_panel.php) — este Controlador solo envuelve esas funciones.
 */
final class GastosController extends BaseController
{
    public function panel(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('gastos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
        $mismoNegocio = count($negocios) === 1;

        $fNegocio = Request::entero('negocio');
        if ($fNegocio && !in_array($fNegocio, $idsVisibles, true)) $fNegocio = 0;
        $hoy = date('Y-m-d');
        $fDesde = Request::get('desde');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)) $fDesde = date('Y-m-01');
        $fHasta = Request::get('hasta');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)) $fHasta = $hoy;
        $fCategoria = Request::entero('categoria');

        $ids = $fNegocio ? [$fNegocio] : $idsVisibles;

        $flash = '';
        if (Request::metodo() === 'POST' && Request::post('accion') === 'generar_fijos') {
            $this->verificarCsrf('gastos.php');
            $creados = 0;
            foreach ($ids as $nid) {
                $creados += Gasto::generarFijosDelMes($nid, date('Y-m-01'), (int)$usuario['id']);
            }
            $flash = $creados > 0
                ? "Se generaron $creados gasto(s) fijo(s) de este mes."
                : 'No había gastos fijos pendientes de generar este mes.';
        }

        $totalGastos = 0.0; $nGastos = 0; $porCategoria = []; $gastos = []; $gastosAnt = 0.0;
        $categorias = Gasto::categorias(false);

        if ($ids) {
            [$totalGastos, $nGastos] = Gasto::totalYCantidad($ids, $fDesde, $fHasta, $fCategoria);
            $porCategoria = Gasto::porCategoria($ids, $fDesde, $fHasta);

            // Gasto del período anterior equivalente (mismos días hacia atrás), para la variación
            $diasP = max(1, (int)((strtotime($fHasta) - strtotime($fDesde)) / 86400) + 1);
            $antHasta = date('Y-m-d', strtotime("$fDesde -1 day"));
            $antDesde = date('Y-m-d', strtotime("$antHasta -" . ($diasP - 1) . " day"));
            [$gastosAnt] = Gasto::totalYCantidad($ids, $antDesde, $antHasta, $fCategoria);

            $gastos = Gasto::listado($ids, $fDesde, $fHasta, $fCategoria);
        }

        $dias = max(1, (int)((strtotime($fHasta) - strtotime($fDesde)) / 86400) + 1);
        $promedioDiario = $totalGastos / $dias;
        $varGastos = $gastosAnt > 0 ? (($totalGastos - $gastosAnt) / $gastosAnt * 100) : null;
        $topCat = $porCategoria[0] ?? null;
        $maxCat = $porCategoria ? max(array_map(fn($c) => (float)$c['total'], $porCategoria)) : 0;

        View::render('gastos/panel', compact(
            'usuario', 'negocios', 'mismoNegocio', 'fNegocio', 'fDesde', 'fHasta', 'fCategoria',
            'categorias', 'flash', 'totalGastos', 'nGastos', 'porCategoria', 'gastos',
            'promedioDiario', 'varGastos', 'topCat', 'maxCat'
        ));
    }

    public function formulario(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('gastos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // icono(), parsear_monto()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
        $categorias = Gasto::categorias(true);

        $id = Request::entero('id');
        $gasto = [
            'negocio_id'   => $idsVisibles[0] ?? 0,
            'categoria_id' => $categorias[0]['id'] ?? 0,
            'fecha'        => date('Y-m-d'),
            'monto'        => '',
            'descripcion'  => '',
        ];

        if ($id) {
            $gasto = Gasto::porId($id);
            if (!$gasto || !\puede_ver_negocio($usuario, (int)$gasto['negocio_id'])) {
                \redirigir('gastos.php');
            }
            $gasto['monto'] = number_format((float)$gasto['monto'], 0, ',', '.');
        }

        $error = '';
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf($id ? 'gasto_form.php?id=' . $id : 'gasto_form.php');
            $accion = Request::post('accion') ?: 'guardar';

            if ($accion === 'eliminar' && $id) {
                Gasto::eliminar($id);
                \redirigir('gastos.php');
            }

            $datos = [
                'negocio_id'   => Request::entero('negocio_id'),
                'categoria_id' => Request::entero('categoria_id'),
                'fecha'        => Request::post('fecha'),
                'monto'        => \parsear_monto(Request::post('monto')),
                'descripcion'  => Request::post('descripcion'),
            ];
            $catValida = in_array($datos['categoria_id'], array_map(fn($c) => (int)$c['id'], $categorias), true);

            if (!in_array($datos['negocio_id'], $idsVisibles, true)) {
                $error = 'Selecciona un negocio válido.';
            } elseif (!$catValida) {
                $error = 'Selecciona una categoría válida.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['fecha'])) {
                $error = 'La fecha no es válida.';
            } elseif ($datos['monto'] === null || $datos['monto'] <= 0) {
                $error = 'Ingresa un monto válido mayor a 0.';
            } else {
                $datos['descripcion'] = $datos['descripcion'] !== '' ? $datos['descripcion'] : null;
                if ($id) {
                    Gasto::actualizar($id, $datos);
                } else {
                    Gasto::crear($datos, (int)$usuario['id']);
                }
                \redirigir('gastos.php');
            }
            $gasto = array_merge($gasto, $datos);
            $gasto['monto'] = Request::post('monto');
        }

        $titulo = $id ? 'Editar gasto' : 'Nuevo gasto';

        View::render('gastos/formulario', compact(
            'usuario', 'negocios', 'id', 'gasto', 'categorias', 'error', 'titulo'
        ));
    }

    public function categorias(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('gastos');
        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()

        $error = '';
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('categorias_gasto.php');
            $accion = Request::post('accion');
            $cid = Request::entero('id');
            $nombre = Request::post('nombre');

            if ($accion === 'crear' || $accion === 'renombrar') {
                if ($nombre === '') {
                    $error = 'El nombre de la categoría es obligatorio.';
                } else {
                    try {
                        if ($accion === 'crear') {
                            Gasto::crearCategoria($nombre);
                        } else {
                            Gasto::renombrarCategoria($cid, $nombre);
                        }
                    } catch (\PDOException $e) {
                        $error = 'Ya existe una categoría con ese nombre.';
                    }
                }
            } elseif ($accion === 'toggle') {
                Gasto::toggleCategoria($cid);
            } elseif ($accion === 'eliminar') {
                if (Gasto::usosDeCategoria($cid) > 0) {
                    $error = 'Esa categoría tiene gastos asociados; desactívala en lugar de borrarla.';
                } else {
                    Gasto::eliminarCategoria($cid);
                }
            }
            if ($error === '') \redirigir('categorias_gasto.php');
        }

        $categorias = Gasto::categorias(false);
        $usos = Gasto::usosPorCategoria();
        $editId = Request::entero('editar');

        View::render('gastos/categorias', compact('usuario', 'categorias', 'usos', 'editId', 'error'));
    }

    public function fijos(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('gastos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // icono(), parsear_monto()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
        $categorias = Gasto::categorias(true);
        $idsCat = array_map(fn($c) => (int)$c['id'], $categorias);

        $error = '';
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('gastos_fijos.php');
            $accion = Request::post('accion');
            $gid = Request::entero('id');
            $duenoOk = $gid && ($f = Gasto::fijoPorId($gid)) && \puede_ver_negocio($usuario, (int)$f['negocio_id']);

            if ($accion === 'toggle' && $duenoOk) {
                Gasto::toggleFijo($gid);
                \redirigir('gastos_fijos.php');
            } elseif ($accion === 'eliminar' && $duenoOk) {
                Gasto::eliminarFijo($gid);
                \redirigir('gastos_fijos.php');
            } elseif ($accion === 'crear' || $accion === 'editar') {
                if ($accion === 'editar' && !$duenoOk) {
                    \redirigir('gastos_fijos.php');
                }
                $diaMes = Request::entero('dia_mes');
                $datos = [
                    'negocio_id'     => Request::entero('negocio_id'),
                    'categoria_id'   => Request::entero('categoria_id'),
                    'descripcion'    => Request::post('descripcion'),
                    'monto_estimado' => \parsear_monto(Request::post('monto_estimado')),
                    'dia_mes'        => ($diaMes >= 1 && $diaMes <= 31) ? $diaMes : null,
                ];

                if (!in_array($datos['negocio_id'], $idsVisibles, true)) {
                    $error = 'Selecciona un negocio válido.';
                } elseif (!in_array($datos['categoria_id'], $idsCat, true)) {
                    $error = 'Selecciona una categoría válida.';
                } elseif ($datos['monto_estimado'] === null || $datos['monto_estimado'] <= 0) {
                    $error = 'Ingresa un monto estimado válido.';
                } else {
                    $datos['descripcion'] = $datos['descripcion'] !== '' ? $datos['descripcion'] : null;
                    if ($accion === 'crear') {
                        Gasto::crearFijo($datos);
                    } else {
                        Gasto::actualizarFijo($gid, $datos);
                    }
                    \redirigir('gastos_fijos.php');
                }
            }
        }

        $edit = null;
        $editId = Request::entero('editar');
        if ($editId) {
            $edit = Gasto::fijoPorId($editId);
            if (!$edit || !\puede_ver_negocio($usuario, (int)$edit['negocio_id'])) $edit = null;
        }

        $fijos = Gasto::fijosDe($idsVisibles);
        $mismoNegocio = count($negocios) === 1;
        $totalMensual = 0.0;
        foreach ($fijos as $f) { if ($f['activo']) $totalMensual += (float)$f['monto_estimado']; }

        $valMonto = $edit ? number_format((float)$edit['monto_estimado'], 0, ',', '.') : '';

        View::render('gastos/fijos', compact(
            'usuario', 'negocios', 'idsVisibles', 'categorias', 'error', 'edit', 'fijos',
            'mismoNegocio', 'totalMensual', 'valMonto'
        ));
    }
}
