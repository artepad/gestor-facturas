<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Ventas del POS. Corte 2a de la Fase 6: solo lo que necesita la PANTALLA
 * de venta para dibujarse (tickets en espera, última venta, historial del
 * turno). El resto de las funciones de venta (cobrar, anular, etc.) se
 * agrega en el corte 2b, cuando le toque a pos_api.php — mismo criterio que
 * CajaSesion: envoltorio sobre lib/pos.php, no una reimplementación (ver
 * web/docs/bitacora-migracion.md).
 */
final class Venta
{
    private static function cargar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pos.php';
    }

    public static function listarEspera(int $sesionId): array
    {
        self::cargar();
        return \listar_espera(Database::pdo(), $sesionId);
    }

    public static function ultimaDelTurno(int $sesionId): ?array
    {
        self::cargar();
        return \ultima_venta_del_turno(Database::pdo(), $sesionId);
    }

    public static function delDia(int $negocioId, string $fecha, int $limite = 300): array
    {
        self::cargar();
        return \ventas_del_dia(Database::pdo(), $negocioId, $fecha, $limite);
    }

    public static function detalle(int $negocioId, int $ventaId): ?array
    {
        self::cargar();
        return \venta_detalle(Database::pdo(), $negocioId, $ventaId);
    }

    // ---------- Corte 2b: lo que necesita pos_api.php ----------

    public static function firmaCatalogo(): string
    {
        self::cargar();
        return \firma_catalogo(Database::pdo());
    }

    public static function catalogoParaPos(): array
    {
        self::cargar();
        return \catalogo_para_pos(Database::pdo());
    }

    public static function buscarProducto(string $termino, int $limite = 20): array
    {
        self::cargar();
        return \buscar_producto_pos(Database::pdo(), $termino, $limite);
    }

    public static function clientesParaFiar(int $negocioId): array
    {
        self::cargar();
        return \clientes_para_pos(Database::pdo(), $negocioId);
    }

    public static function registrar(
        array $usuario, array $sesion, string $uuid, array $items, array $pagos,
        ?float $pagoCon, string $origen, string $nota, ?int $clienteId
    ): array {
        self::cargar();
        return \registrar_venta(Database::pdo(), $usuario, $sesion, $uuid, $items, $pagos,
            $pagoCon, $origen, $nota, $clienteId);
    }

    public static function anular(array $usuario, int $ventaId, string $motivo): array
    {
        self::cargar();
        return \anular_venta(Database::pdo(), $usuario, $ventaId, $motivo);
    }

    public static function devolverArticulo(array $usuario, int $itemId): array
    {
        self::cargar();
        return \devolver_articulo_venta(Database::pdo(), $usuario, $itemId);
    }

    public static function guardarEspera(array $usuario, array $sesion, array $items, string $etiqueta, float $total): array
    {
        self::cargar();
        return \guardar_espera(Database::pdo(), $usuario, $sesion, $items, $etiqueta, $total);
    }

    public static function tomarEspera(int $sesionId, int $esperaId): array
    {
        self::cargar();
        return \tomar_espera(Database::pdo(), $sesionId, $esperaId);
    }

    public static function descartarEspera(int $sesionId, int $esperaId): string
    {
        self::cargar();
        return \descartar_espera(Database::pdo(), $sesionId, $esperaId);
    }
}
