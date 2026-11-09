<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Producto;

/**
 * Base de Datos de Productos: administración del catálogo compartido (carga
 * del Excel de Eleventa, búsqueda/edición puntual) + su historial de
 * auditoría.
 *   herramienta_productos.php -> panel()      (cargar Excel, vaciar, buscar)
 *   producto_form.php[?id=N]  -> formulario() (crear/editar/eliminar un producto)
 *   historial_productos.php   -> historial()  (solo admin)
 *
 * El código viejo no tenía CSRF en "cargar Excel" / "vaciar catálogo" /
 * guardar o eliminar un producto: se agrega acá, igual que en el resto de
 * los módulos migrados.
 */
final class ProductosController extends BaseController
{
    private const CAMPOS_AUDITADOS = ['codigo', 'nombre', 'precio_costo', 'precio_venta',
        'precio_mayoreo', 'departamento', 'tipo_venta', 'activo', 'afecto_iva'];

    public function panel(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('herramientas');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // icono(), clp()

        $negocios = \negocios_visibles($usuario);

        // Negocio seleccionado: del POST (al subir) o del GET (al cambiar en el selector).
        $negocioId = Request::entero('negocio_id') ?: Request::entero('negocio');
        if ($negocioId && !\puede_ver_negocio($usuario, $negocioId)) {
            $negocioId = 0;
        }
        if (!$negocioId && $negocios) {
            $negocioId = (int)$negocios[0]['id'];
        }

        $mensaje = null; // ['tipo' => 'ok'|'error', 'texto' => ...]

        if (Request::metodo() === 'POST' && $negocioId) {
            $this->verificarCsrf('herramienta_productos.php?negocio=' . $negocioId);
            $accion = Request::post('accion');

            if ($accion === 'cargar') {
                try {
                    $f = $_FILES['archivo'] ?? null;
                    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                        throw new \RuntimeException('Elige un archivo Excel (.xlsx) para subir.');
                    }
                    if ($f['error'] !== UPLOAD_ERR_OK) {
                        throw new \RuntimeException('El archivo no se subió correctamente (¿muy grande?). Intenta de nuevo.');
                    }
                    if (!is_uploaded_file($f['tmp_name'])) {
                        throw new \RuntimeException('Archivo inválido.');
                    }
                    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                        throw new \RuntimeException('El archivo debe ser un Excel .xlsx (exportado desde Eleventa).');
                    }
                    if ($f['size'] > 8 * 1024 * 1024) {
                        throw new \RuntimeException('El archivo es demasiado grande (máximo 8 MB).');
                    }

                    $parse = Producto::parsearExcel($f['tmp_name']);
                    Producto::reemplazarCatalogo($negocioId, $parse, [
                        'archivo'    => $f['name'],
                        'usuario_id' => $usuario['id'],
                    ]);
                    $omit = $parse['omitidos'] > 0 ? " · {$parse['omitidos']} fila(s) sin código/nombre omitida(s)" : '';
                    $mensaje = ['tipo' => 'ok',
                                'texto' => "Base de datos actualizada: {$parse['total']} productos cargados$omit."];
                } catch (\Throwable $e) {
                    $mensaje = ['tipo' => 'error', 'texto' => $e->getMessage()];
                }
            } elseif ($accion === 'vaciar') {
                if (trim(Request::post('confirmar')) !== 'ELIMINAR') {
                    $mensaje = ['tipo' => 'error', 'texto' => 'Para vaciar el catálogo debes escribir ELIMINAR en mayúsculas.'];
                } else {
                    Producto::vaciarCatalogo();
                    $mensaje = ['tipo' => 'ok',
                                'texto' => 'La base de datos fue eliminada. Ahora no hay productos cargados; '
                                         . 'sube un Excel cuando quieras crear el catálogo de nuevo.'];
                }
            }
        }

        $ultima = Producto::ultimaCarga();
        $totalP = Producto::contar();

        // El estado depende de si HAY productos cargados ahora mismo: si el catálogo se
        // vació, no decimos "Actualizada hoy" (la fecha de carga existe pero no hay base).
        $estado = $totalP > 0
            ? Producto::estadoActualizacion($ultima['cargado_en'] ?? null)
            : ['nivel' => 'vacio', 'dias' => null, 'texto' => 'No hay una base de datos disponible'];

        $historial = Producto::historialCargas(8);

        $q = Request::get('q');
        $encontrados = $q !== '' ? Producto::buscarParaEditar($q) : [];
        $msg = Request::get('msg');

        $nombreNegocio = '';
        foreach ($negocios as $n) if ((int)$n['id'] === $negocioId) $nombreNegocio = $n['nombre'];

        View::render('productos/panel', compact(
            'usuario', 'negocios', 'negocioId', 'mensaje', 'ultima', 'totalP', 'estado',
            'historial', 'q', 'encontrados', 'msg', 'nombreNegocio'
        ));
    }

    public function formulario(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('productos');

        $id = Request::entero('id');
        $prod = [
            'codigo' => '', 'nombre' => '', 'precio_costo' => '', 'precio_venta' => '',
            'precio_mayoreo' => '', 'departamento' => '', 'tipo_venta' => '',
            'activo' => 1, 'afecto_iva' => 1,
        ];
        $original = null;

        if ($id) {
            $prod = Producto::porId($id);
            if (!$prod) \redirigir('herramienta_productos.php');
            $original = $prod;
            foreach (['precio_costo', 'precio_venta', 'precio_mayoreo'] as $c) {
                $prod[$c] = $prod[$c] !== null ? number_format((float)$prod[$c], 0, ',', '.') : '';
            }
        }

        $deptos = Producto::departamentosUsados();

        $error = '';
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf($id ? 'producto_form.php?id=' . $id : 'producto_form.php');
            $accion = Request::post('accion') ?: 'guardar';

            if ($accion === 'eliminar' && $id) {
                Producto::eliminar($id);
                Producto::auditar($usuario, 'eliminar_producto',
                    'Eliminó el producto ' . $original['nombre'] . ' (' . $original['codigo'] . ')',
                    json_encode($original, JSON_UNESCAPED_UNICODE), $id);
                \redirigir('herramienta_productos.php?msg=eliminado');
            }

            require_once dirname(__DIR__, 2) . '/lib/ui.php'; // parsear_monto(), clp()
            $datos = [
                'codigo'         => Request::post('codigo'),
                'nombre'         => Request::post('nombre'),
                'precio_costo'   => \parsear_monto(Request::post('precio_costo')),
                'precio_venta'   => \parsear_monto(Request::post('precio_venta')),
                'precio_mayoreo' => \parsear_monto(Request::post('precio_mayoreo')),
                'departamento'   => Request::post('departamento'),
                'tipo_venta'     => Request::post('tipo_venta'),
                'activo'         => isset($_POST['activo']) ? 1 : 0,
                'afecto_iva'     => isset($_POST['afecto_iva']) ? 1 : 0,
            ];

            if ($datos['codigo'] === '') {
                $error = 'El código es obligatorio (es lo que lee el escáner).';
            } elseif ($datos['nombre'] === '') {
                $error = 'El nombre del producto es obligatorio.';
            } elseif ($datos['precio_venta'] === null || $datos['precio_venta'] < 0) {
                $error = 'Ingresa un precio de venta válido.';
            } else {
                // El código identifica al producto en todo el sistema: no se repite.
                if ($otro = Producto::otroConCodigo($datos['codigo'], $id)) {
                    $error = 'Ya existe otro producto con el código ' . $datos['codigo']
                           . ' (' . $otro['nombre'] . ').';
                }
            }

            if ($error === '') {
                $dep = $datos['departamento'] !== '' ? $datos['departamento'] : null;
                $tip = $datos['tipo_venta']   !== '' ? $datos['tipo_venta']   : null;
                $datosGuardar = $datos;
                $datosGuardar['departamento'] = $dep;
                $datosGuardar['tipo_venta'] = $tip;

                if ($id) {
                    Producto::actualizar($id, $datosGuardar, (int)$usuario['id']);
                    if ($cambios = Producto::cambios($original, $datos, self::CAMPOS_AUDITADOS)) {
                        Producto::auditar($usuario, 'editar_producto',
                            'Editó el producto ' . $datos['nombre'] . ' (' . $datos['codigo'] . ')',
                            $cambios, $id);
                    }
                } else {
                    $id = Producto::crear($datosGuardar, (int)$usuario['id']);
                    Producto::auditar($usuario, 'crear_producto',
                        'Creó el producto ' . $datos['nombre'] . ' (' . $datos['codigo'] . ') a '
                        . \clp($datos['precio_venta']),
                        json_encode($datos, JSON_UNESCAPED_UNICODE), $id);
                }
                \redirigir('herramienta_productos.php?msg=guardado');
            }
            $prod = array_merge($prod, $_POST);
        }

        $autor = ($id && $original) ? Producto::autorDe($id) : null;
        $titulo = $id ? 'Editar producto' : 'Nuevo producto';

        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()
        View::render('productos/formulario', compact(
            'usuario', 'id', 'prod', 'deptos', 'error', 'autor', 'titulo'
        ));
    }

    public function historial(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('usuarios'); // 'usuarios' = solo administrador
        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()

        $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : '';
        $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : '';
        if ($desde && $hasta && $desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }

        $filtros = [
            'q'       => Request::get('q'),
            'accion'  => Request::get('accion'),
            'usuario' => Request::entero('usuario'),
            'desde'   => $desde,
            'hasta'   => $hasta,
        ];

        $pagina = max(1, Request::entero('p', 1));
        $porPag = 40;

        $total = Producto::contarHistorial($filtros);
        $paginas = max(1, (int)ceil($total / $porPag));
        $pagina = min($pagina, $paginas);

        $filas = Producto::historial($filtros, $pagina, $porPag);
        $autores = Producto::autoresHistorial();

        View::render('productos/historial', compact(
            'usuario', 'filtros', 'pagina', 'porPag', 'total', 'paginas', 'filas', 'autores'
        ));
    }
}
