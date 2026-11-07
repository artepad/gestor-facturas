<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Convenios (Klap y otros): pago de cuentas de terceros desde la caja. Ver
 * POS_DISENO.md 7.7 — la plata NO es venta propia, solo la comisión.
 * Corte 2b de la Fase 6: envoltorio sobre lib/pos.php (mismo criterio que
 * el resto de los Modelos del POS).
 */
final class Convenio
{
    private static function cargar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pos.php';
    }

    public static function porDefecto(int $negocioId): array
    {
        self::cargar();
        return \convenio_por_defecto(Database::pdo(), $negocioId);
    }

    public static function porId(int $id): ?array
    {
        self::cargar();
        $st = Database::pdo()->prepare('SELECT * FROM convenios WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function saldo(array $convenio): array
    {
        self::cargar();
        return \saldo_convenio(Database::pdo(), $convenio);
    }

    public static function registrarRecaudacion(
        array $usuario, array $sesion, string $uuid, int $convenioId, string $servicio,
        float $monto, string $medioPago, string $referencia = '', ?float $comision = null
    ): array {
        self::cargar();
        return \registrar_recaudacion(
            Database::pdo(), $usuario, $sesion, $uuid, $convenioId, $servicio,
            $monto, $medioPago, $referencia, $comision
        );
    }
}
