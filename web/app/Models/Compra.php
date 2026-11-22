<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Compras: mercadería recibida (suma stock si el negocio lo lleva) y
 * proveedores. Corte de la Fase 6: envoltorio sobre lib/pos.php, mismo
 * criterio que el resto de los Modelos del POS — ver web/docs/bitacora-migracion.md.
 */
final class Compra
{
    private static function cargar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pos.php';
    }

    public static function proveedoresDe(int $negocioId, bool $soloActivos = true): array
    {
        self::cargar();
        return \proveedores_de(Database::pdo(), $negocioId, $soloActivos);
    }

    public static function proveedorPorId(int $id): ?array
    {
        return Database::uno('SELECT * FROM proveedores WHERE id = ?', [$id]);
    }

    /** Productos del catálogo que distribuye este proveedor (Etapa 1 de Proveedores). */
    public static function productosDe(int $proveedorId): array
    {
        return Database::todos(
            "SELECT p.id, p.codigo, p.nombre, p.departamento, pp.codigo_proveedor
               FROM producto_proveedor pp JOIN productos p ON p.id = pp.producto_id
              WHERE pp.proveedor_id = ? ORDER BY p.nombre",
            [$proveedorId]
        );
    }

    /** Asocia un producto a un proveedor (o actualiza su código de proveedor si ya estaba asociado). */
    public static function asignarProducto(int $proveedorId, int $productoId, ?string $codigoProveedor): void
    {
        Database::consultar(
            "INSERT INTO producto_proveedor (producto_id, proveedor_id, codigo_proveedor) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE codigo_proveedor = VALUES(codigo_proveedor)",
            [$productoId, $proveedorId, $codigoProveedor]
        );
    }

    public static function quitarProducto(int $proveedorId, int $productoId): void
    {
        Database::consultar(
            'DELETE FROM producto_proveedor WHERE proveedor_id = ? AND producto_id = ?',
            [$proveedorId, $productoId]
        );
    }

    public static function crearProveedor(
        ?int $negocioId, string $nombre, ?string $rut, ?string $telefono, ?string $correo
    ): int {
        Database::consultar(
            'INSERT INTO proveedores (negocio_id, nombre, rut, telefono, correo) VALUES (?,?,?,?,?)',
            [$negocioId, $nombre, $rut, $telefono, $correo]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function registrar(
        array $usuario, int $negocioId, ?int $proveedorId, string $fecha, array $items,
        string $nota = '', ?int $facturaId = null
    ): array {
        self::cargar();
        return \registrar_compra(Database::pdo(), $usuario, $negocioId, $proveedorId, $fecha, $items, $nota, $facturaId);
    }

    public static function listadas(int $negocioId, int $limite = 30): array
    {
        self::cargar();
        return \compras_de(Database::pdo(), $negocioId, $limite);
    }

    public static function items(int $compraId): array
    {
        self::cargar();
        return \items_de_compra(Database::pdo(), $compraId);
    }

    /** Facturas recientes del negocio, para enlazar la compra con la que llegó. */
    public static function facturasRecientes(int $negocioId, int $limite = 25): array
    {
        return Database::todos(
            "SELECT id, proveedor, numero_factura, fecha, total FROM facturas
              WHERE negocio_id = ? AND eliminada_en IS NULL
              ORDER BY fecha DESC, id DESC LIMIT " . (int)$limite,
            [$negocioId]
        );
    }
}
