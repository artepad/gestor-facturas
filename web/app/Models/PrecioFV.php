<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Frutas y Verduras: lista de precios compartida entre los dos negocios (sin
 * negocio_id — la compra la hace una sola persona para ambos).
 *
 * A diferencia del resto de los Modelos del POS, este NO envuelve una función
 * vieja de lib/pos.php: la pantalla se reescribió desde cero (decisión del
 * dueño, 2026-11-08) al mudarla de su propio ítem de menú a un botón dentro
 * de la pantalla de Venta. El SQL vive directo acá, como ya hacían Convenio y
 * Promocion para lo que no tenía equivalente viejo.
 */
final class PrecioFV
{
    /** Productos visibles, opcionalmente filtrados por nombre. */
    public static function activos(string $filtro = ''): array
    {
        $sql = "SELECT p.*, u.nombre AS actualizado_por_nombre
                  FROM precios_fv p
                  LEFT JOIN usuarios u ON u.id = p.actualizado_por
                 WHERE p.activo = 1";
        $args = [];
        if ($filtro !== '') {
            $sql .= " AND p.nombre LIKE ?";
            $args[] = '%' . $filtro . '%';
        }
        $sql .= " ORDER BY p.nombre";
        return Database::todos($sql, $args);
    }

    public static function porId(int $id): ?array
    {
        return Database::uno(
            "SELECT p.*, u.nombre AS actualizado_por_nombre
               FROM precios_fv p
               LEFT JOIN usuarios u ON u.id = p.actualizado_por
              WHERE p.id = ?",
            [$id]
        );
    }

    public static function crear(array $d, int $actualizadoPor): int
    {
        Database::consultar(
            "INSERT INTO precios_fv (nombre, precio, unidad, imagen, observacion, activo, actualizado_por)
             VALUES (?,?,?,?,?,?,?)",
            [$d['nombre'], $d['precio'], $d['unidad'], $d['imagen'], $d['observacion'], $d['activo'], $actualizadoPor]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizar(int $id, array $d, int $actualizadoPor): void
    {
        Database::consultar(
            "UPDATE precios_fv SET nombre=?, precio=?, unidad=?, imagen=?, observacion=?, activo=?,
                    actualizado_por=?, actualizado_en=CURRENT_TIMESTAMP WHERE id=?",
            [$d['nombre'], $d['precio'], $d['unidad'], $d['imagen'], $d['observacion'], $d['activo'],
             $actualizadoPor, $id]
        );
    }

    public static function eliminar(int $id): void
    {
        Database::consultar('DELETE FROM precios_fv WHERE id = ?', [$id]);
    }
}
