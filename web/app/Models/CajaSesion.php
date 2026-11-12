<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Turno de caja: abrir, movimientos de efectivo, arqueo y cierre.
 *
 * Corte 1 de la Fase 6 (migracion de arquitectura): esta clase es a
 * PROPOSITO un envoltorio sobre las funciones que ya existen en
 * web/lib/pos.php, no una reimplementacion. Mover 1500 lineas de logica de
 * negocio de una sola vez es el tipo de cambio grande que este proyecto
 * decidio evitar (ver web/docs/bitacora-migracion.md). Lo que gana esta
 * clase es la ESTRUCTURA nueva (Modelo -> Controlador -> Vista) probada de
 * punta a punta sobre una pantalla real; el dia que la pantalla vieja
 * (web/caja.php) se retire, el SQL se puede mover aca adentro sin que nadie
 * mas tenga que cambiar — es el mismo patron que ya usan Core/Database y
 * Core/Session con lib/db.php y lib/auth.php.
 */
final class CajaSesion
{
    private static function cargar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pos.php';
    }

    public static function abiertaDe(int $usuarioId): ?array
    {
        self::cargar();
        return \sesion_abierta_de(Database::pdo(), $usuarioId);
    }

    public static function abiertasEn(array $idsNegocios): array
    {
        self::cargar();
        return \sesiones_abiertas(Database::pdo(), $idsNegocios);
    }

    public static function porId(int $sesionId): ?array
    {
        self::cargar();
        return \sesion_por_id(Database::pdo(), $sesionId);
    }

    public static function corteEsPropio(int $corteId, int $usuarioId): bool
    {
        self::cargar();
        return \corte_es_propio(Database::pdo(), $corteId, $usuarioId);
    }

    public static function cerradasEn(array $idsNegocios, int $limite = 30, ?int $usuarioId = null): array
    {
        self::cargar();
        return \sesiones_cerradas(Database::pdo(), $idsNegocios, $limite, $usuarioId);
    }

    public static function abrir(array $usuario, int $negocioId, string $caja, float $fondo,
                                 string $tipo = 'fisica'): int
    {
        self::cargar();
        return \abrir_caja(Database::pdo(), $usuario, $negocioId, $caja, $fondo, $tipo);
    }

    public static function registrarMovimiento(array $usuario, int $sesionId, string $tipo, float $monto, string $motivo): void
    {
        self::cargar();
        \registrar_movimiento_caja(Database::pdo(), $usuario, $sesionId, $tipo, $monto, $motivo);
    }

    public static function movimientos(int $sesionId): array
    {
        self::cargar();
        return \movimientos_caja(Database::pdo(), $sesionId);
    }

    public static function ticketsEnEspera(int $sesionId): int
    {
        self::cargar();
        return \tickets_en_espera(Database::pdo(), $sesionId);
    }

    public static function resumen(int $sesionId): array
    {
        self::cargar();
        return \resumen_caja(Database::pdo(), $sesionId);
    }

    public static function recaudacionesDelTurno(int $sesionId, int $limite = 50): array
    {
        self::cargar();
        return \recaudaciones_del_turno(Database::pdo(), $sesionId, $limite);
    }

    public static function cerrar(array $usuario, int $sesionId, float $contado, string $nota = ''): array
    {
        self::cargar();
        return \cerrar_caja(Database::pdo(), $usuario, $sesionId, $contado, $nota);
    }

    public static function avisoSiLlevaMuchoAbierta(array $sesion): ?string
    {
        self::cargar();
        return \aviso_caja_larga($sesion);
    }
}
