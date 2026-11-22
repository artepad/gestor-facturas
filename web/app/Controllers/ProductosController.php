<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Producto;

/**
 * Productos: desde 2026-11-18 es un módulo propio del menú (antes vivía
 * escondido dentro de Herramientas). `listado()` es la puerta de entrada
 * (tabla con búsqueda/filtros/orden/paginación + ventas + auditoría);
 * `herramienta_productos.php` (carga de Excel) y las demás siguen existiendo
 * tal cual, solo que ahora se llega a ellas DESDE el listado.
 *   productos.php                        -> listado()    (tabla principal: puerta de entrada del módulo)
 *   herramienta_productos.php            -> panel()      (cargar Excel, vaciar catálogo)
 *   producto_form.php[?id=N]             -> formulario() (crear/editar/eliminar un producto)
 *   historial_productos.php              -> historial()  (solo admin)
 *   herramienta_unificar_productos.php   -> unificar()    (fusionar los Excel de dos
 *                                           negocios antes de que se pisen entre sí; solo admin)
 *   producto_exportar.php                -> exportar()    (descarga el catálogo completo
 *                                           en un .xlsx, formato Eleventa, para subirlo
 *                                           tal cual en otro ambiente)
 *
 * El código viejo no tenía CSRF en "cargar Excel" / "vaciar catálogo" /
 * guardar o eliminar un producto: se agrega acá, igual que en el resto de
 * los módulos migrados.
 */
final class ProductosController extends BaseController
{
    private const CAMPOS_AUDITADOS = ['codigo', 'nombre', 'precio_costo', 'precio_venta',
        'precio_mayoreo', 'departamento', 'tipo_venta', 'activo', 'afecto_iva'];

    // Límites del formulario Nuevo/Editar producto (ver formulario(), tanto en la
    // validación del servidor como en los atributos maxlength de la vista, para que
    // nunca queden desincronizados). Elegidos revisando los datos reales del catálogo:
    // el código más largo que existe hoy tiene 20 caracteres, el nombre más largo 80,
    // el departamento más largo 20 — estos topes dejan harto margen sin permitir
    // basura. MAX_MONTO (~$99.999.999) es un tope de sentido común para CLP: muy por
    // encima de cualquier precio real del catálogo (el más alto hoy es $55.000), pero
    // bloquea el típico error de tipeo de agregar varios ceros de más.
    private const MAX_LARGO_CODIGO = 20;
    private const MAX_LARGO_NOMBRE = 150;
    private const MAX_LARGO_DEPTO  = 60;
    private const MAX_MONTO        = 99999999;

    /**
     * Puerta de entrada del módulo Productos: tabla con búsqueda, filtros
     * (departamento, activo/inactivo, sin movimiento por franja de días,
     * negocio), orden por columna y paginación. También resuelve la acción
     * rápida "Marcar inactivo/activo" de cada fila (POST).
     */
    public function listado(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('productos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';     // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';       // icono(), clp(), hace_tiempo()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php';

        $qs = http_build_query($_GET);
        $volverConFiltros = 'productos.php' . ($qs !== '' ? '?' . $qs : '');

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf($volverConFiltros);
            if (Request::post('accion') === 'toggle_activo') {
                try {
                    $id = Request::entero('id');
                    $nuevoEstado = Request::post('nuevo') === '1';
                    $antes = Producto::toggleActivo($id, $nuevoEstado, (int)$usuario['id']);
                    \registrar_auditoria(
                        Database::pdo(), $usuario, 'productos',
                        $nuevoEstado ? 'activar_producto' : 'desactivar_producto', null,
                        ($nuevoEstado ? 'Reactivó' : 'Marcó como inactivo') . ' el producto '
                        . $antes['nombre'] . ' (' . $antes['codigo'] . ')',
                        null, 'producto', $id
                    );
                } catch (\Throwable $e) {
                    Session::flash('error', $e->getMessage());
                }
            }
            \redirigir($volverConFiltros);
        }

        $negocios = \negocios_visibles($usuario);
        $filtros = [
            'q'            => Request::get('q'),
            'departamento' => Request::get('departamento'),
            'estado'       => Request::get('estado'),
            'movimiento'   => Request::get('movimiento'),
            'negocio'      => Request::entero('negocio'),
            'orden'        => Request::get('orden'),
            'dir'          => Request::get('dir'),
        ];

        $pagina = max(1, Request::entero('p', 1));
        $porPag = 30;

        $total = Producto::contarListado($filtros);
        $paginas = max(1, (int)ceil($total / $porPag));
        $pagina = min($pagina, $paginas);

        $filas = Producto::listado($filtros, $pagina, $porPag);
        $deptos = Producto::departamentosUsados();
        $error = Session::flash('error') ?? '';
        $msg = Request::get('msg');

        View::render('productos/listado', compact(
            'usuario', 'negocios', 'filtros', 'pagina', 'porPag', 'total', 'paginas', 'filas', 'deptos', 'error', 'msg'
        ));
    }

    /**
     * Cargar / actualizar la base de datos desde un Excel, o vaciar el
     * catálogo: solo admin. Las vendedoras pueden ver y descargar el
     * catálogo (exportar()), pero no reemplazarlo masivamente — eso lo
     * decidió el dueño junto con el color de identificación de usuarios.
     */
    public function panel(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('usuarios');
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

        $nombreNegocio = '';
        foreach ($negocios as $n) if ((int)$n['id'] === $negocioId) $nombreNegocio = $n['nombre'];

        View::render('productos/panel', compact(
            'usuario', 'negocios', 'negocioId', 'mensaje', 'ultima', 'totalP', 'estado',
            'historial', 'nombreNegocio'
        ));
    }

    public function formulario(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('productos');

        // Para la vista: mismos límites que valida el servidor más abajo, en
        // los atributos maxlength/del input, así nunca quedan desincronizados.
        $maxCodigo = self::MAX_LARGO_CODIGO;
        $maxNombre = self::MAX_LARGO_NOMBRE;
        $maxDepto  = self::MAX_LARGO_DEPTO;
        $maxMonto  = self::MAX_MONTO;

        $id = Request::entero('id');
        $prod = [
            'codigo' => '', 'nombre' => '', 'precio_costo' => '', 'precio_venta' => '',
            'precio_mayoreo' => '', 'departamento' => '', 'tipo_venta' => '',
            'activo' => 1, 'afecto_iva' => 1,
        ];
        $original = null;

        if ($id) {
            $prod = Producto::porId($id);
            if (!$prod) \redirigir('productos.php');
            $original = $prod;
            foreach (['precio_costo', 'precio_venta', 'precio_mayoreo'] as $c) {
                $prod[$c] = $prod[$c] !== null ? number_format((float)$prod[$c], 0, ',', '.') : '';
            }
        }

        $deptos = Producto::departamentosUsados();

        $error = '';
        $errores = [];
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf($id ? 'producto_form.php?id=' . $id : 'producto_form.php');
            $accion = Request::post('accion') ?: 'guardar';

            if ($accion === 'eliminar' && $id) {
                Producto::eliminar($id);
                Producto::auditar($usuario, 'eliminar_producto',
                    'Eliminó el producto ' . $original['nombre'] . ' (' . $original['codigo'] . ')',
                    json_encode($original, JSON_UNESCAPED_UNICODE), $id);
                \redirigir('productos.php?msg=eliminado');
            }

            require_once dirname(__DIR__, 2) . '/lib/ui.php'; // parsear_monto(), clp()
            $codigoPost = trim(Request::post('codigo'));
            $nombrePost = trim(Request::post('nombre'));
            $deptoPost  = trim(Request::post('departamento'));
            $ventaRaw   = trim(Request::post('precio_venta'));
            $costoRaw   = trim(Request::post('precio_costo'));
            $mayorRaw   = trim(Request::post('precio_mayoreo'));

            $precioVenta   = $ventaRaw !== '' ? \parsear_monto($ventaRaw) : null;
            $precioCosto   = $costoRaw !== '' ? \parsear_monto($costoRaw) : null;
            $precioMayoreo = $mayorRaw !== '' ? \parsear_monto($mayorRaw) : null;

            $datos = [
                'codigo'         => $codigoPost,
                'nombre'         => $nombrePost,
                'precio_costo'   => $precioCosto,
                'precio_venta'   => $precioVenta,
                'precio_mayoreo' => $precioMayoreo,
                'departamento'   => $deptoPost,
                // El formulario ya no tiene un campo para esto (sin uso real:
                // no interviene en ningún cálculo de precio ni del POS, solo
                // se mostraba como dato suelto). Se preserva tal cual venía
                // del Excel para no perder esa información al editar.
                'tipo_venta'     => $id ? ($original['tipo_venta'] ?? '') : '',
                'activo'         => isset($_POST['activo']) ? 1 : 0,
                // Ídem tipo_venta: el formulario ya no pide esto (no afecta precios,
                // ventas ni ningún reporte — ver el análisis previo a este cambio).
                // Se preserva el valor que ya tenía el producto; los nuevos quedan
                // en 1 (afecto), el mismo default que ya tenía la columna.
                'afecto_iva'     => $id ? (int)($original['afecto_iva'] ?? 1) : 1,
            ];

            // --- Código de barras: obligatorio, solo números, largo razonable ---
            // Excepción: si no cambió respecto al valor ya guardado, no se exige que
            // sea solo números — algunos productos del catálogo (cargados por Excel
            // hace tiempo) tienen códigos internos con letras o espacios (ej.
            // "SRT5000", "MARV 120 GRS"); no hay que romperlos solo por abrir y
            // guardar el producto sin tocar ese campo.
            if ($codigoPost === '') {
                $errores['codigo'] = 'El código es obligatorio (es lo que lee el escáner).';
            } elseif (mb_strlen($codigoPost) > self::MAX_LARGO_CODIGO) {
                $errores['codigo'] = 'El código no puede superar los ' . self::MAX_LARGO_CODIGO . ' caracteres.';
            } elseif (!ctype_digit($codigoPost) && !($original !== null && $codigoPost === $original['codigo'])) {
                $errores['codigo'] = 'El código debe contener solo números (es lo que lee el escáner).';
            } elseif ($otro = Producto::otroConCodigo($codigoPost, $id)) {
                // El código identifica al producto en todo el sistema: no se repite.
                $errores['codigo'] = 'Ya existe otro producto con el código ' . $codigoPost
                                    . ' (' . $otro['nombre'] . ').';
            }

            if ($nombrePost === '') {
                $errores['nombre'] = 'El nombre del producto es obligatorio.';
            } elseif (mb_strlen($nombrePost) > self::MAX_LARGO_NOMBRE) {
                $errores['nombre'] = 'El nombre no puede superar los ' . self::MAX_LARGO_NOMBRE . ' caracteres.';
            }

            if ($ventaRaw === '') {
                $errores['precio_venta'] = 'Ingresa un precio de venta.';
            } elseif ($precioVenta === null) {
                $errores['precio_venta'] = 'El precio de venta no es un monto válido.';
            } elseif ($precioVenta < 0) {
                $errores['precio_venta'] = 'El precio de venta no puede ser negativo.';
            } elseif ($precioVenta > self::MAX_MONTO) {
                $errores['precio_venta'] = 'El precio de venta es demasiado alto.';
            }

            if ($costoRaw !== '') {
                if ($precioCosto === null) {
                    $errores['precio_costo'] = 'El precio de costo no es un monto válido.';
                } elseif ($precioCosto < 0) {
                    $errores['precio_costo'] = 'El precio de costo no puede ser negativo.';
                } elseif ($precioCosto > self::MAX_MONTO) {
                    $errores['precio_costo'] = 'El precio de costo es demasiado alto.';
                }
            }

            if ($mayorRaw !== '') {
                if ($precioMayoreo === null) {
                    $errores['precio_mayoreo'] = 'El precio mayoreo no es un monto válido.';
                } elseif ($precioMayoreo < 0) {
                    $errores['precio_mayoreo'] = 'El precio mayoreo no puede ser negativo.';
                } elseif ($precioMayoreo > self::MAX_MONTO) {
                    $errores['precio_mayoreo'] = 'El precio mayoreo es demasiado alto.';
                }
            }

            if (mb_strlen($deptoPost) > self::MAX_LARGO_DEPTO) {
                $errores['departamento'] = 'El departamento no puede superar los ' . self::MAX_LARGO_DEPTO . ' caracteres.';
            }

            if (!$errores) {
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
                \redirigir('productos.php?msg=guardado');
            }
            $error = 'Revisa los campos marcados en rojo.';
            $prod = array_merge($prod, $_POST);
        }

        $autor = ($id && $original) ? Producto::autorDe($id) : null;
        $titulo = $id ? 'Editar producto' : 'Nuevo producto';
        // Solo lectura aquí: la relación se administra desde Compras → Proveedores
        // (ver ComprasController::index(), acciones 'asignar_producto'/'quitar_producto').
        $proveedoresProducto = $id ? Producto::proveedoresDe($id) : [];

        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()
        View::render('productos/formulario', compact(
            'usuario', 'id', 'prod', 'deptos', 'error', 'errores', 'autor', 'titulo',
            'maxCodigo', 'maxNombre', 'maxDepto', 'maxMonto', 'proveedoresProducto'
        ));
    }

    public function historial(array $parametros = []): void
    {
        // Mismo permiso que el listado ('productos', ambos roles): es de solo
        // lectura, útil para que la vendedora consulte quién cambió qué sin
        // poder modificar ni eliminar nada del historial.
        $usuario = $this->exigirPermiso('productos');
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

    /**
     * Unificar catálogos: compara los Excel de dos negocios antes de
     * fusionarlos en el catálogo compartido (ver lib/productos.php). Solo
     * admin — es una fusión masiva que afecta a los dos negocios a la vez,
     * mismo criterio que "Vaciar catálogo" y el historial de auditoría.
     *   sin ?id      -> formulario para elegir negocios + subir los 2 Excel
     *   ?id=N        -> pantalla de revisión de esa comparación (o su resultado, si ya se aplicó)
     */
    public function unificar(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('usuarios');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';     // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';       // icono(), clp(), parsear_monto()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php';

        $id = Request::entero('id');
        $error = Session::flash('error') ?? '';

        if (Request::metodo() === 'POST') {
            $volverA = $id ? 'herramienta_unificar_productos.php?id=' . $id : 'herramienta_unificar_productos.php';
            $this->verificarCsrf($volverA);
            $accion = Request::post('accion');

            if ($accion === 'comparar') {
                try {
                    $negocioAId = Request::entero('negocio_a_id');
                    $negocioBId = Request::entero('negocio_b_id');
                    if (!$negocioAId || !$negocioBId || $negocioAId === $negocioBId) {
                        throw new \RuntimeException('Elige dos negocios distintos.');
                    }
                    $negocios = array_column(\negocios_visibles($usuario), 'nombre', 'id');
                    if (!isset($negocios[$negocioAId], $negocios[$negocioBId])) {
                        throw new \RuntimeException('Negocio no válido.');
                    }

                    $fa = $this->archivoExcel('archivo_a');
                    $fb = $this->archivoExcel('archivo_b');
                    $filasA = Producto::parsearExcel($fa['tmp_name'])['filas'];
                    $filasB = Producto::parsearExcel($fb['tmp_name'])['filas'];
                    $comparacion = Producto::comparar($filasA, $filasB);

                    $nuevoId = Producto::crearUnificacion($negocioAId, $negocioBId, $comparacion, [
                        'archivo_a' => $fa['name'], 'archivo_b' => $fb['name'], 'usuario_id' => $usuario['id'],
                    ]);
                    \redirigir('herramienta_unificar_productos.php?id=' . $nuevoId);
                } catch (\Throwable $e) {
                    Session::flash('error', $e->getMessage());
                    \redirigir('herramienta_unificar_productos.php');
                }
            } elseif ($accion === 'resolver' && $id) {
                try {
                    $itemId = Request::entero('item_id');
                    $personalizado = [
                        'nombre'       => Request::post('nombre_personalizado'),
                        'precio_venta' => \parsear_monto(Request::post('precio_personalizado')),
                    ];
                    Producto::resolverItemUnificacion($itemId, (string)Request::post('decision'), $personalizado);
                } catch (\Throwable $e) {
                    Session::flash('error', $e->getMessage());
                }
                \redirigir('herramienta_unificar_productos.php?id=' . $id);
            } elseif ($accion === 'aplicar' && $id) {
                try {
                    $unifAntes = Producto::unificacion($id);
                    $resumen = Producto::aplicarUnificacion($id, ['usuario_id' => $usuario['id']]);
                    if ($unifAntes) {
                        \registrar_auditoria(Database::pdo(), $usuario, 'productos', 'unificar_catalogo', null,
                            "Unificó los catálogos de {$unifAntes['negocio_a_nombre']} y {$unifAntes['negocio_b_nombre']}: "
                            . "{$resumen['nuevos']} producto(s) nuevo(s), {$resumen['actualizados']} actualizado(s).",
                            null, 'producto_unificacion', $id);
                    }
                } catch (\Throwable $e) {
                    Session::flash('error', $e->getMessage());
                }
                \redirigir('herramienta_unificar_productos.php?id=' . $id);
            } elseif ($accion === 'descartar' && $id) {
                Producto::descartarUnificacion($id);
                \redirigir('herramienta_unificar_productos.php');
            } else {
                \redirigir('herramienta_unificar_productos.php');
            }
            return;
        }

        if ($id) {
            $unif = Producto::unificacion($id);
            if (!$unif) \redirigir('herramienta_unificar_productos.php');
            $items = [
                'igual'             => Producto::itemsUnificacion($id, 'igual'),
                'conflicto_precio'  => Producto::itemsUnificacion($id, 'conflicto_precio'),
                'conflicto_nombre'  => Producto::itemsUnificacion($id, 'conflicto_nombre'),
                'solo_a'            => Producto::itemsUnificacion($id, 'solo_a'),
                'solo_b'            => Producto::itemsUnificacion($id, 'solo_b'),
                'revisar'           => Producto::itemsUnificacion($id, 'revisar'),
            ];
            $pendientes = Producto::pendientesUnificacion($id);
            View::render('productos/unificar_revision', compact('usuario', 'unif', 'items', 'pendientes', 'error'));
            return;
        }

        $negocios = \negocios_visibles($usuario);
        $historial = Producto::historialUnificaciones(8);
        View::render('productos/unificar', compact('usuario', 'negocios', 'historial', 'error'));
    }

    /**
     * Descarga el catálogo completo en un .xlsx con las mismas columnas que
     * exporta Eleventa — pensado para subirlo tal cual en otro ambiente (ej.
     * llevar a producción un catálogo ya unificado en local) con la
     * herramienta de "Cargar / actualizar base de datos" de siempre, sin
     * tener que repetir ningún trabajo manual. Mismo permiso que el listado:
     * cualquiera que puede ver/editar el catálogo puede descargarlo.
     */
    public function exportar(array $parametros = []): void
    {
        $this->exigirPermiso('productos');

        $filas = Producto::catalogoCompleto();
        $bytes = Producto::generarExcel($filas);
        $nombre = 'catalogo_productos_' . date('Y-m-d') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    /** Valida un archivo subido como Excel .xlsx (mismas reglas que "Cargar / actualizar" en panel()). */
    private function archivoExcel(string $campo): array
    {
        $f = $_FILES[$campo] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Elige los dos archivos Excel (.xlsx) para comparar.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uno de los archivos no se subió correctamente (¿muy grande?). Intenta de nuevo.');
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            throw new \RuntimeException('Archivo inválido.');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \RuntimeException('Los archivos deben ser Excel .xlsx (exportados desde Eleventa).');
        }
        if ($f['size'] > 8 * 1024 * 1024) {
            throw new \RuntimeException('El archivo es demasiado grande (máximo 8 MB).');
        }
        return $f;
    }
}
