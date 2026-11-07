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
 * web/docs/01-plan-migracion.md).
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

    public static function delTurno(int $sesionId, int $limite = 50): array
    {
        self::cargar();
        return \ventas_del_turno(Database::pdo(), $sesionId, $limite);
    }
}
