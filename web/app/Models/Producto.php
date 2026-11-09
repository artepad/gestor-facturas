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

    /** Departamentos ya usados en el catálogo (para sugerir en el formulario). */
    public static function departamentosUsados(): array
    {
        return array_column(Database::todos(
            "SELECT DISTINCT departamento FROM productos
              WHERE departamento IS NOT NULL AND departamento <> '' ORDER BY departamento"
        ), 'departamento');
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
        return Database::todos(
            "SELECT * FROM auditoria WHERE $where ORDER BY id DESC LIMIT $porPag OFFSET "
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
        if (in_array($f['accion'] ?? '', ['crear_producto', 'editar_producto', 'eliminar_producto'], true)) {
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
