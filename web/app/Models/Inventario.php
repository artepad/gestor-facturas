<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Inventario opcional por negocio (existencias, ajustes, mermas, kardex).
 * Corte de la Fase 6 (migracion de arquitectura): envoltorio sobre
 * lib/pos.php, mismo criterio que el resto de los Modelos del POS — ver
 * web/docs/bitacora-migracion.md.
 */
final class Inventario
{
    private static function cargar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pos.php';
    }

    public static function controla(int $negocioId): bool
    {
        self::cargar();
        return \negocio_controla_stock(Database::pdo(), $negocioId);
    }

    public static function fijarControl(int $negocioId, bool $encender): void
    {
        Database::consultar('UPDATE negocios SET control_stock = ? WHERE id = ?', [$encender ? 1 : 0, $negocioId]);
    }

    public static function stockDe(int $productoId, int $negocioId): float
    {
        self::cargar();
        return \stock_de(Database::pdo(), $productoId, $negocioId);
    }

    public static function moverStock(
        array $usuario, int $negocioId, ?int $productoId, float $cantidad, string $tipo,
        ?string $refTipo = null, ?int $refId = null, string $motivo = ''
    ): ?float {
        self::cargar();
        return \mover_stock(Database::pdo(), $usuario, $negocioId, $productoId, $cantidad, $tipo, $refTipo, $refId, $motivo);
    }

    public static function resumen(int $negocioId): array
    {
        self::cargar();
        return \resumen_inventario(Database::pdo(), $negocioId);
    }

    public static function productosConStock(int $negocioId, string $filtro = '', string $solo = '', int $limite = 100): array
    {
        self::cargar();
        return \productos_con_stock(Database::pdo(), $negocioId, $filtro, $solo, $limite);
    }

    public static function fijarMinimo(int $productoId, int $negocioId, ?float $minimo): void
    {
        self::cargar();
        \fijar_stock_minimo(Database::pdo(), $productoId, $negocioId, $minimo);
    }

    public static function kardex(int $productoId, int $negocioId, int $limite = 60): array
    {
        self::cargar();
        return \kardex_producto(Database::pdo(), $productoId, $negocioId, $limite);
    }

    public static function productoPorId(int $id): ?array
    {
        return Database::uno('SELECT * FROM productos WHERE id = ?', [$id]);
    }

    public static function productoIdPorCodigo(string $codigo): ?int
    {
        $id = Database::valor('SELECT id FROM productos WHERE codigo = ?', [$codigo]);
        return $id !== false ? (int)$id : null;
    }
}
