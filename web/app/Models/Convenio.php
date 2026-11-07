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

    /** Convenios del negocio con su saldo ya calculado (corte de servicios.php). */
    public static function conSaldo(int $negocioId, bool $soloActivos = true): array
    {
        self::cargar();
        return \convenios_con_saldo(Database::pdo(), $negocioId, $soloActivos);
    }

    public static function crear(int $negocioId, string $nombre, string $modalidad, ?float $saldoMinimo): int
    {
        try {
            Database::consultar(
                'INSERT INTO convenios (negocio_id, nombre, modalidad, saldo_minimo) VALUES (?,?,?,?)',
                [$negocioId, $nombre, $modalidad, $saldoMinimo]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \RuntimeException('Ya existe un convenio con ese nombre en este negocio.');
            }
            throw $e;
        }
        return (int)Database::pdo()->lastInsertId();
    }

    public static function operaciones(int $convenioId, int $limite = 60): array
    {
        self::cargar();
        return \operaciones_convenio(Database::pdo(), $convenioId, $limite);
    }

    public static function registrarOperacion(
        array $usuario, int $convenioId, string $tipo, float $monto, string $origenFondos = 'banco',
        string $referencia = '', string $nota = '', ?array $sesion = null
    ): array {
        self::cargar();
        return \registrar_operacion_convenio(
            Database::pdo(), $usuario, $convenioId, $tipo, $monto, $origenFondos, $referencia, $nota, $sesion
        );
    }

    /** Cuadra el saldo calculado con lo que muestra el portal del proveedor. */
    public static function ajustarSaldo(int $convenioId, int $negocioId, float $saldoReal): array
    {
        self::cargar();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM convenios WHERE id = ? AND negocio_id = ?');
        $st->execute([$convenioId, $negocioId]);
        $c = $st->fetch();
        if (!$c) throw new \RuntimeException('Convenio inválido.');

        $calc = \saldo_convenio($pdo, $c);
        $nuevoAjuste = round((float)$c['saldo_ajuste'] + ($saldoReal - $calc['saldo']), 2);
        $pdo->prepare('UPDATE convenios SET saldo_ajuste = ? WHERE id = ?')->execute([$nuevoAjuste, $convenioId]);
        return ['convenio' => $c, 'saldo_anterior' => $calc['saldo']];
    }
}
