<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Compras: mercadería recibida (suma stock si el negocio lo lleva) y
 * proveedores. Corte de la Fase 6: envoltorio sobre lib/pos.php, mismo
 * criterio que el resto de los Modelos del POS — ver web/docs/01-plan-migracion.md.
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
