<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Gasto;

/**
 * Gastos operacionales del negocio (agua, luz, gas, sueldos, arriendo...).
 * panel() y categorias() son solo administrador ('gastos'). formulario() la
 * comparte con las vendedoras, que solo pueden CREAR un gasto nuevo
 * ('gastos_registrar', ver lib/permisos.php) — ni ven el listado, ni editan
 * ni eliminan uno existente.
 *   gastos.php              -> panel()       (dashboard del período, solo admin)
 *   gasto_form.php[?id=N]   -> formulario()  (crear siempre; editar/eliminar solo admin)
 *   categorias_gasto.php    -> categorias()  (CRUD de categorías, solo admin)
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

        $pagina = max(1, Request::entero('p', 1));
        $porPag = 20;
        $total = Gasto::contarListado($ids, $fDesde, $fHasta, $fCategoria);
        $paginas = max(1, (int)ceil($total / $porPag));
        $pagina = min($pagina, $paginas);

        $flash = '';
        $gastos = [];
        $categorias = Gasto::categorias(false);
        if ($ids) {
            $gastos = Gasto::listado($ids, $fDesde, $fHasta, $fCategoria, $pagina, $porPag);
        }

        if ($fNegocio) {
            foreach ($negocios as $n) { if ((int)$n['id'] === $fNegocio) $viendo = $n['nombre']; }
        } elseif ($mismoNegocio) {
            $viendo = $negocios[0]['nombre'];
        }
        $viendo = $viendo ?? 'Todos los negocios';

        View::render('gastos/panel', compact(
            'usuario', 'negocios', 'mismoNegocio', 'fNegocio', 'fDesde', 'fHasta', 'fCategoria',
            'categorias', 'flash', 'gastos', 'viendo', 'pagina', 'porPag', 'total', 'paginas'
        ));
    }

    public function formulario(array $parametros = []): void
    {
        // 'gastos' = acceso completo (admin): ve el listado, edita, elimina.
        // 'gastos_registrar' = solo vendedoras: únicamente pueden crear un
        // gasto nuevo, nunca ver, editar ni eliminar uno existente (ver
        // lib/permisos.php). $puedeGestionar distingue cuál es cuál adentro.
        $usuario = $this->exigirPermisoAlguno(['gastos', 'gastos_registrar']);
        $puedeGestionar = \puede($usuario, 'gastos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // icono(), parsear_monto()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
        $categorias = Gasto::categorias(true);
        $volverA = $puedeGestionar ? 'gastos.php' : 'home.php';

        $id = Request::entero('id');
        if ($id && !$puedeGestionar) {
            // Con permiso limitado no se puede ver/editar un gasto que ya
            // existe, ni siquiera el propio: solo se registra uno nuevo.
            \redirigir($volverA);
        }
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
                \redirigir($volverA);
            }
            $gasto['monto'] = number_format((float)$gasto['monto'], 0, ',', '.');
        }

        $error = '';
        $aviso = Request::get('guardado') === '1' ? 'Gasto registrado correctamente.' : '';
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf($id ? 'gasto_form.php?id=' . $id : 'gasto_form.php');
            $accion = Request::post('accion') ?: 'guardar';

            if ($accion === 'eliminar' && $id && $puedeGestionar) {
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
                if ($id && $puedeGestionar) {
                    Gasto::actualizar($id, $datos);
                    \redirigir('gastos.php');
                } else {
                    Gasto::crear($datos, (int)$usuario['id']);
                    \redirigir($puedeGestionar ? 'gastos.php' : 'gasto_form.php?guardado=1');
                }
            }
            $gasto = array_merge($gasto, $datos);
            $gasto['monto'] = Request::post('monto');
        }

        $titulo = $id ? 'Editar gasto' : 'Nuevo gasto';

        View::render('gastos/formulario', compact(
            'usuario', 'negocios', 'id', 'gasto', 'categorias', 'error', 'aviso', 'titulo',
            'puedeGestionar', 'volverA'
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
}
