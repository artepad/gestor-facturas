<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Promocion;

/**
 * Promociones automáticas: las ofertas que el POS aplica solo al vender.
 *   promociones.php            -> listado
 *   promociones.php?id=N       -> editar / eliminar
 *   promociones.php?nueva=1    -> crear
 *
 * Fase 9 (despliegue): vive en web/promociones.php (archivo puente que llama
 * directo a este Controlador) — misma direccion de siempre.
 */
final class PromocionesController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('productos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';      // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // clp(), icono(), parsear_monto(), fecha_dmy()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php'; // registrar_auditoria(), cambios_json()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

        $id = Request::entero('id');
        $nueva = Request::get('nueva') !== '';
        $error = '';

        $promo = [
            'negocio_id' => '', 'nombre' => '', 'tipo' => 'pack', 'producto_id' => '',
            'codigo' => '', 'departamento' => '', 'cantidad' => '', 'valor' => '',
            'desde' => '', 'hasta' => '', 'activo' => 1,
        ];
        $original = null;

        if ($id) {
            $promo = Promocion::porId($id);
            if (!$promo) \redirigir('promociones.php');
            $original = $promo;
            $promo['valor'] = $promo['valor'] !== null ? number_format((float)$promo['valor'], 0, ',', '.') : '';
            $promo['cantidad'] = $promo['cantidad'] !== null
                ? rtrim(rtrim(number_format((float)$promo['cantidad'], 3, ',', '.'), '0'), ',') : '';
        }

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('promociones.php');
            $accion = Request::post('accion') ?: 'guardar';

            if ($accion === 'eliminar' && $id) {
                Promocion::eliminar($id);
                \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'eliminar_promocion', null,
                    'Eliminó la promoción ' . $original['nombre'], null, 'promocion', $id);
                \redirigir('promociones.php?msg=eliminada');
            }

            $d = [
                'negocio_id'   => Request::post('negocio_id') !== '' ? (int)Request::post('negocio_id') : null,
                'nombre'       => Request::post('nombre'),
                'tipo'         => Request::post('tipo') ?: 'pack',
                'codigo'       => Request::post('codigo'),
                'departamento' => Request::post('departamento'),
                'cantidad'     => \parsear_monto(Request::post('cantidad')),
                'valor'        => \parsear_monto(Request::post('valor')),
                'desde'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', Request::post('desde')) ? Request::post('desde') : null,
                'hasta'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', Request::post('hasta')) ? Request::post('hasta') : null,
                'activo'       => isset($_POST['activo']) ? 1 : 0,
            ];

            // El producto se identifica por su código (es lo que la vendedora conoce).
            $productoId = null;
            if ($d['codigo'] !== '') {
                $p = Promocion::productoPorCodigo($d['codigo']);
                if ($p) { $productoId = (int)$p['id']; }
                else { $error = 'No existe ningún producto con el código ' . $d['codigo'] . '.'; }
            }

            if ($error === '') {
                if ($d['nombre'] === '')                        $error = 'Ponle un nombre a la promoción.';
                elseif (!isset(Promocion::TIPOS[$d['tipo']]))   $error = 'Tipo de promoción inválido.';
                elseif (!$productoId && $d['departamento'] === '')
                    $error = 'Indica el código del producto o el departamento al que aplica.';
                elseif ($d['valor'] === null || $d['valor'] <= 0)
                    $error = 'Indica el valor de la promoción.';
                elseif (in_array($d['tipo'], ['pack', 'nxm'], true) && ($d['cantidad'] === null || $d['cantidad'] < 2))
                    $error = 'Para este tipo, la cantidad debe ser 2 o más.';
                elseif ($d['tipo'] === 'nxm' && $d['valor'] >= $d['cantidad'])
                    $error = 'En "lleve N pague M", M tiene que ser menor que N.';
                elseif ($d['tipo'] === 'porcentaje' && $d['valor'] >= 100)
                    $error = 'El descuento tiene que ser menor que 100%.';
                elseif ($d['negocio_id'] !== null && !in_array($d['negocio_id'], $idsVisibles, true))
                    $error = 'Negocio inválido.';
                elseif ($d['desde'] && $d['hasta'] && $d['desde'] > $d['hasta'])
                    $error = 'La fecha "desde" no puede ser posterior a "hasta".';
            }

            if ($error === '') {
                $dep = ($productoId === null && $d['departamento'] !== '') ? $d['departamento'] : null;
                $cant = in_array($d['tipo'], ['pack', 'nxm'], true) ? $d['cantidad'] : null;
                $datos = $d;
                $datos['producto_id'] = $productoId;
                $datos['departamento'] = $dep;
                $datos['cantidad'] = $cant;
                if ($id) {
                    Promocion::actualizar($id, $datos);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'editar_promocion', null,
                        'Editó la promoción ' . $d['nombre'],
                        \cambios_json($original, $datos, ['nombre','tipo','cantidad','valor','activo','desde','hasta']),
                        'promocion', $id);
                } else {
                    $id = Promocion::crear($datos, (int)$usuario['id']);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'crear_promocion', null,
                        'Creó la promoción ' . $d['nombre'], null, 'promocion', $id);
                }
                \redirigir('promociones.php?msg=guardada');
            }
            $promo = array_merge($promo, $_POST);
            $nueva = !$id;
        }

        $lista = Promocion::todas();
        $deptos = array_column(Promocion::departamentosDisponibles(), 'departamento');

        $msg = Request::get('msg');
        $hoy = date('Y-m-d');
        $editando = $id || $nueva;

        View::render('promociones/index', compact(
            'usuario', 'negocios', 'id', 'nueva', 'error', 'promo', 'lista', 'deptos',
            'msg', 'hoy', 'editando'
        ));
    }
}
