<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Catálogo de productos (compartido por los dos negocios, alimenta el
 * autocompletar del Gestor de Etiquetas y Consultar Catálogo). La carga del
 * Excel de Eleventa, el estado de actualización y las búsquedas envuelven
 * lib/productos.php (NO se toca: son funciones puras, sin equivalente OOP
 * previo); el CRUD de un producto puntual y el historial de auditoría usan
 * SQL propio — mismo criterio que App\Models\Gasto y App\Models\Corte.
 */
final class Producto
{
    private static function cargarLibProductos(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/productos.php';
    }

    private static function cargarLibAuditoria(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php';
    }

    // ---- Carga del Excel y estado (envoltorio de lib/productos.php) ----

    public static function parsearExcel(string $ruta): array
    {
        self::cargarLibProductos();
        return \parsear_excel_productos($ruta);
    }

    public static function reemplazarCatalogo(int $negocioId, array $parse, array $meta): int
    {
        self::cargarLibProductos();
        return \reemplazar_catalogo(Database::pdo(), $negocioId, $parse, $meta);
    }

    public static function vaciarCatalogo(): int
    {
        self::cargarLibProductos();
        return \vaciar_catalogo(Database::pdo());
    }

    public static function ultimaCarga(): ?array
    {
        self::cargarLibProductos();
        return \ultima_carga(Database::pdo());
    }

    public static function contar(): int
    {
        self::cargarLibProductos();
        return \contar_productos(Database::pdo());
    }

    public static function estadoActualizacion(?string $cargadoEn): array
    {
        self::cargarLibProductos();
        return \estado_actualizacion($cargadoEn);
    }

    public static function buscar(string $q, int $limite = 20): array
    {
        self::cargarLibProductos();
        return \buscar_productos(Database::pdo(), $q, $limite);
    }

    public static function departamentos(): array
    {
        self::cargarLibProductos();
        return \departamentos_de_negocio(Database::pdo());
    }

    public static function consultarCatalogo(string $q, string $departamento = '', int $limite = 30): array
    {
        self::cargarLibProductos();
        return \consultar_catalogo(Database::pdo(), $q, $departamento, $limite);
    }

    /** Todo el catálogo (cualquier estado), para exportar a Excel. */
    public static function catalogoCompleto(): array
    {
        self::cargarLibProductos();
        return \catalogo_completo(Database::pdo());
    }

    /** Arma los bytes de un .xlsx (formato Eleventa) a partir de filas del catálogo. */
    public static function generarExcel(array $filas): string
    {
        self::cargarLibProductos();
        return \generar_excel_productos($filas);
    }

    /** Últimas N cargas de Excel (historial global: el catálogo es compartido). */
    public static function historialCargas(int $limite = 8): array
    {
        return Database::todos(
            "SELECT c.*, u.nombre AS usuario_nombre, n.nombre AS negocio_nombre
               FROM producto_cargas c
               LEFT JOIN usuarios u ON u.id = c.cargado_por
               LEFT JOIN negocios n ON n.id = c.negocio_id
              ORDER BY c.cargado_en DESC, c.id DESC LIMIT $limite"
        );
    }

    // ---- Unificar catálogos de dos negocios (envoltorio de lib/productos.php) ----

    public static function comparar(array $filasA, array $filasB): array
    {
        self::cargarLibProductos();
        return \comparar_catalogos($filasA, $filasB);
    }

    public static function crearUnificacion(int $negocioAId, int $negocioBId, array $comparacion, array $meta): int
    {
        self::cargarLibProductos();
        return \crear_unificacion(Database::pdo(), $negocioAId, $negocioBId, $comparacion, $meta);
    }

    public static function unificacion(int $id): ?array
    {
        self::cargarLibProductos();
        return \obtener_unificacion(Database::pdo(), $id);
    }

    public static function itemsUnificacion(int $id, ?string $grupo = null): array
    {
        self::cargarLibProductos();
        return \items_unificacion(Database::pdo(), $id, $grupo);
    }

    public static function pendientesUnificacion(int $id): int
    {
        self::cargarLibProductos();
        return \contar_pendientes_unificacion(Database::pdo(), $id);
    }

    public static function resolverItemUnificacion(int $itemId, string $decision, array $personalizado = []): void
    {
        self::cargarLibProductos();
        \resolver_item_unificacion(Database::pdo(), $itemId, $decision, $personalizado);
    }

    public static function aplicarUnificacion(int $id, array $meta): array
    {
        self::cargarLibProductos();
        return \aplicar_unificacion(Database::pdo(), $id, $meta);
    }

    public static function descartarUnificacion(int $id): void
    {
        self::cargarLibProductos();
        \descartar_unificacion(Database::pdo(), $id);
    }

    public static function historialUnificaciones(int $limite = 8): array
    {
        self::cargarLibProductos();
        return \historial_unificaciones(Database::pdo(), $limite);
    }

    // ---- CRUD de un producto puntual (sin equivalente previo en lib/productos.php) ----

    /** Busca productos por código o nombre para el panel "Buscar y editar" (con el nombre de quién editó). */
    public static function buscarParaEditar(string $q, int $limite = 40): array
    {
        return Database::todos(
            "SELECT p.*, u.nombre AS editor
               FROM productos p LEFT JOIN usuarios u ON u.id = p.actualizado_por
              WHERE p.codigo LIKE ? OR p.nombre LIKE ?
              ORDER BY (p.codigo = ?) DESC, p.nombre LIMIT $limite",
            [$q . '%', '%' . $q . '%', $q]
        );
    }

    /** Autocompletar de productos para asignarlos a un proveedor (necesita el id, a diferencia de buscar_productos()). */
    public static function buscarParaAsignar(string $q, int $limite = 15): array
    {
        return Database::todos(
            "SELECT id, codigo, nombre
               FROM productos
              WHERE codigo LIKE ? OR nombre LIKE ?
              ORDER BY (codigo = ?) DESC, nombre LIMIT $limite",
            [$q . '%', '%' . $q . '%', $q]
        );
    }

    public static function porId(int $id): ?array
    {
        return Database::uno("SELECT * FROM productos WHERE id = ?", [$id]);
    }

    /** Otro producto con el mismo código (para validar unicidad al guardar). */
    public static function otroConCodigo(string $codigo, int $excluirId): ?array
    {
        return Database::uno(
            "SELECT id, nombre FROM productos WHERE codigo = ? AND id <> ?",
            [$codigo, $excluirId]
        );
    }

    /** Proveedores asignados a este producto (solo lectura, se gestiona desde Compras → Proveedores). */
    public static function proveedoresDe(int $productoId): array
    {
        return Database::todos(
            "SELECT pr.id, pr.nombre, pp.codigo_proveedor
               FROM producto_proveedor pp JOIN proveedores pr ON pr.id = pp.proveedor_id
              WHERE pp.producto_id = ? ORDER BY pr.nombre",
            [$productoId]
        );
    }

    /** Departamentos ya usados en el catálogo (para sugerir en el formulario). */
    public static function departamentosUsados(): array
    {
        return array_column(Database::todos(
            "SELECT DISTINCT departamento FROM productos
              WHERE departamento IS NOT NULL AND departamento <> '' ORDER BY departamento"
        ), 'departamento');
    }

    // ---- Listado principal del módulo Productos (tabla con ventas + auditoría) ----

    /**
     * Catálogo paginado con quién/cuándo lo modificó por última vez y sus
     * ventas (unidades vendidas + fecha de la última venta). Las ventas se
     * calculan con un LEFT JOIN a un resumen por producto (venta_items +
     * ventas, solo 'completada'), nunca tocan las tablas del POS.
     */
    public static function listado(array $filtros, int $pagina = 1, int $porPag = 30): array
    {
        [$subSql, $subArgs] = self::ventasSubquery((int)($filtros['negocio'] ?? 0));
        [$where, $whereArgs] = self::condicionListado($filtros);
        $orden = self::columnaOrden((string)($filtros['orden'] ?? ''), (string)($filtros['dir'] ?? ''));
        $sql = "SELECT p.*, u.nombre AS editor, u.color AS editor_color,
                       COALESCE(v.unidades, 0) AS unidades_vendidas, v.ultima_venta
                  FROM productos p
                  LEFT JOIN usuarios u ON u.id = p.actualizado_por
                  LEFT JOIN ($subSql) v ON v.producto_id = p.id
                 WHERE $where
                 ORDER BY $orden
                 LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag);
        return Database::todos($sql, array_merge($subArgs, $whereArgs));
    }

    /** Cantidad total de productos que calzan con los filtros (para la paginación). */
    public static function contarListado(array $filtros): int
    {
        [$subSql, $subArgs] = self::ventasSubquery((int)($filtros['negocio'] ?? 0));
        [$where, $whereArgs] = self::condicionListado($filtros);
        $sql = "SELECT COUNT(*) FROM productos p
                  LEFT JOIN ($subSql) v ON v.producto_id = p.id
                 WHERE $where";
        return (int)Database::valor($sql, array_merge($subArgs, $whereArgs));
    }

    /** Resumen de ventas por producto (unidades + última venta), opcionalmente de un solo negocio. */
    private static function ventasSubquery(int $negocioId): array
    {
        $cond = "ve.estado = 'completada'";
        $args = [];
        if ($negocioId > 0) {
            $cond .= " AND ve.negocio_id = ?";
            $args[] = $negocioId;
        }
        $sql = "SELECT vi.producto_id, SUM(vi.cantidad) AS unidades, MAX(ve.fecha) AS ultima_venta
                  FROM venta_items vi
                  JOIN ventas ve ON ve.id = vi.venta_id
                 WHERE vi.producto_id IS NOT NULL AND $cond
                 GROUP BY vi.producto_id";
        return [$sql, $args];
    }

    private static function condicionListado(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if (($f['q'] ?? '') !== '') {
            $where[] = '(p.codigo LIKE ? OR p.nombre LIKE ?)';
            $args[] = $f['q'] . '%';
            $args[] = '%' . $f['q'] . '%';
        }
        if (($f['departamento'] ?? '') !== '') {
            $where[] = 'p.departamento = ?';
            $args[] = $f['departamento'];
        }
        if (($f['estado'] ?? '') === 'activo')        { $where[] = 'p.activo = 1'; }
        elseif (($f['estado'] ?? '') === 'inactivo')  { $where[] = 'p.activo = 0'; }

        $mov = (string)($f['movimiento'] ?? '');
        if ($mov === 'nunca') {
            $where[] = 'v.producto_id IS NULL';
        } elseif (ctype_digit($mov) && (int)$mov > 0) {
            $where[] = '(v.ultima_venta IS NULL OR v.ultima_venta <= DATE_SUB(NOW(), INTERVAL ? DAY))';
            $args[] = (int)$mov;
        }
        return [implode(' AND ', $where), $args];
    }

    /** Columna/dirección de orden, siempre de una lista fija (nunca el texto del usuario directo al SQL). */
    private static function columnaOrden(string $campo, string $dir): string
    {
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
        $columnas = [
            'nombre'         => 'p.nombre',
            'codigo'         => 'p.codigo',
            'precio_venta'   => 'p.precio_venta',
            'actualizado_en' => 'p.actualizado_en',
            'unidades'       => 'unidades_vendidas',
            'ultima_venta'   => 'v.ultima_venta',
        ];
        $col = $columnas[$campo] ?? 'p.nombre';
        return "$col $dir, p.nombre ASC";
    }

    /** Activa o desactiva un producto (reutiliza el campo "activo" que ya existía). Devuelve el producto antes del cambio. */
    public static function toggleActivo(int $id, bool $activo, int $usuarioId): array
    {
        $prod = self::porId($id);
        if (!$prod) throw new \RuntimeException('El producto no existe.');
        Database::consultar(
            "UPDATE productos SET activo = ?, actualizado_por = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?",
            [$activo ? 1 : 0, $usuarioId, $id]
        );
        return $prod;
    }

    /** Quién creó y quién editó por última vez un producto (se muestra al editar). */
    public static function autorDe(int $id): ?array
    {
        return Database::uno(
            "SELECT (SELECT nombre FROM usuarios WHERE id = p.creado_por) AS creador,
                    (SELECT nombre FROM usuarios WHERE id = p.actualizado_por) AS editor,
                    p.actualizado_en
               FROM productos p WHERE p.id = ?",
            [$id]
        );
    }

    public static function crear(array $datos, int $usuarioId): int
    {
        Database::consultar(
            "INSERT INTO productos (codigo, nombre, precio_costo, precio_venta, precio_mayoreo,
                                    departamento, tipo_venta, activo, afecto_iva,
                                    creado_por, actualizado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$datos['codigo'], $datos['nombre'], $datos['precio_costo'], $datos['precio_venta'],
             $datos['precio_mayoreo'], $datos['departamento'], $datos['tipo_venta'],
             $datos['activo'], $datos['afecto_iva'], $usuarioId, $usuarioId]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizar(int $id, array $datos, int $usuarioId): void
    {
        Database::consultar(
            "UPDATE productos SET codigo=?, nombre=?, precio_costo=?, precio_venta=?,
                    precio_mayoreo=?, departamento=?, tipo_venta=?, activo=?, afecto_iva=?,
                    actualizado_por=?, actualizado_en=CURRENT_TIMESTAMP
              WHERE id=?",
            [$datos['codigo'], $datos['nombre'], $datos['precio_costo'], $datos['precio_venta'],
             $datos['precio_mayoreo'], $datos['departamento'], $datos['tipo_venta'],
             $datos['activo'], $datos['afecto_iva'], $usuarioId, $id]
        );
    }

    public static function eliminar(int $id): void
    {
        // Las ventas ya hechas conservan el nombre y el precio en sus líneas
        // (venta_items guarda una copia): borrar no altera el historial.
        Database::consultar("DELETE FROM productos WHERE id = ?", [$id]);
    }

    /** Registra un evento de auditoría del módulo 'productos' (crear/editar/eliminar). */
    public static function auditar(array $usuario, string $accion, string $descripcion,
                                    ?string $detalle, int $refId): void
    {
        self::cargarLibAuditoria();
        \registrar_auditoria(Database::pdo(), $usuario, 'productos', $accion, null,
            $descripcion, $detalle, 'producto', $refId);
    }

    /** Qué campos cambiaron entre el producto original y los datos nuevos (para auditar una edición). */
    public static function cambios(array $antes, array $despues, array $campos): ?string
    {
        self::cargarLibAuditoria();
        return \cambios_json($antes, $despues, $campos);
    }

    // ---- Historial de auditoría (historial_productos.php) ----

    public static function historial(array $filtros, int $pagina, int $porPag): array
    {
        [$where, $args] = self::condicionHistorial($filtros);
        // u.color: el chip de "Quién" reusa el color de identificación del usuario
        // (ver Usuario::PALETA); LEFT JOIN porque usuario_id queda NULL si el
        // usuario ya se eliminó (el nombre snapshot de auditoria igual se conserva).
        return Database::todos(
            "SELECT a.*, u.color AS usuario_color
               FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE $where ORDER BY a.id DESC LIMIT $porPag OFFSET "
            . (($pagina - 1) * $porPag),
            $args
        );
    }

    public static function contarHistorial(array $filtros): int
    {
        [$where, $args] = self::condicionHistorial($filtros);
        return (int)Database::valor("SELECT COUNT(*) FROM auditoria WHERE $where", $args);
    }

    /** Quiénes han tocado el catálogo (para el filtro por persona). */
    public static function autoresHistorial(): array
    {
        return Database::todos(
            "SELECT DISTINCT a.usuario_id, a.usuario_nombre
               FROM auditoria a
              WHERE a.modulo = 'productos' AND a.usuario_id IS NOT NULL
              ORDER BY a.usuario_nombre"
        );
    }

    private static function condicionHistorial(array $f): array
    {
        $where = ["modulo = 'productos'"];
        $args = [];
        if (($f['q'] ?? '') !== '') {
            $where[] = "(descripcion LIKE ? OR detalle LIKE ?)";
            $args[] = '%' . $f['q'] . '%';
            $args[] = '%' . $f['q'] . '%';
        }
        if (in_array($f['accion'] ?? '', ['crear_producto', 'editar_producto', 'eliminar_producto',
                'desactivar_producto', 'activar_producto'], true)) {
            $where[] = "accion = ?";
            $args[] = $f['accion'];
        }
        if (!empty($f['usuario'])) {
            $where[] = "usuario_id = ?";
            $args[] = $f['usuario'];
        }
        if (($f['desde'] ?? '') !== '') {
            $where[] = "creado_en >= ?";
            $args[] = $f['desde'] . ' 00:00:00';
        }
        if (($f['hasta'] ?? '') !== '') {
            $where[] = "creado_en <= ?";
            $args[] = $f['hasta'] . ' 23:59:59';
        }
        return [implode(' AND ', $where), $args];
    }
}
