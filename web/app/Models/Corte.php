<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Cortes de caja (módulo Ingresos): el dashboard y el detalle de un corte.
 * Hoy los genera el POS propio al cerrar turno (ver registrar_corte() en
 * lib/pos.php); hasta 2026-11-14 también llegaban por correo desde
 * Eleventa — ese flujo (parser, cron IMAP, registro manual pegando el
 * correo) se retiró por completo, ver web/CLAUDE.md. Los cortes históricos
 * que vinieron por correo y su texto crudo (`correos_corte`) se conservan
 * intactos: `correoDe()` sigue usándose en el detalle de esos cortes viejos.
 * Para lo demás, SQL propio: estas consultas vivían sueltas en
 * ingresos.php/corte.php.
 */
final class Corte
{
    // ---- Dashboard (ingresos.php) ----

    private static function condicion(array $ids, string $desde, string $hasta, int $cajeroId): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $cond = "c.negocio_id IN ($ph) AND DATE(c.cerrado_en) BETWEEN ? AND ?";
        $par = [...$ids, $desde, $hasta];
        if ($cajeroId) { $cond .= ' AND c.cajero_id = ?'; $par[] = $cajeroId; }
        return [$cond, $par];
    }

    /** [ventas, efectivo, tarjeta, transferencia, salidas, n, nventas] del período. */
    public static function listado(array $ids, string $desde, string $hasta, int $cajeroId = 0, int $limite = 200): array
    {
        if (!$ids) return [];
        [$cond, $par] = self::condicion($ids, $desde, $hasta, $cajeroId);
        return Database::todos(
            "SELECT c.*, n.nombre AS negocio, cj.nombre AS cajero
               FROM cortes c
               JOIN negocios n ON n.id = c.negocio_id
               LEFT JOIN cajeros cj ON cj.id = c.cajero_id
              WHERE $cond ORDER BY c.cerrado_en DESC LIMIT " . (int)$limite,
            $par
        );
    }

    public static function cajerosDe(array $idsVisibles): array
    {
        if (!$idsVisibles) return [];
        $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
        return Database::todos("SELECT id, nombre FROM cajeros WHERE negocio_id IN ($ph) ORDER BY nombre", $idsVisibles);
    }

    // ---- Detalle de un corte (corte.php) ----

    public static function porId(int $id): ?array
    {
        return Database::uno(
            "SELECT c.*, n.nombre AS negocio, cj.nombre AS cajero
               FROM cortes c
               JOIN negocios n ON n.id = c.negocio_id
               LEFT JOIN cajeros cj ON cj.id = c.cajero_id
              WHERE c.id = ?",
            [$id]
        );
    }

    public static function movimientosDe(int $corteId): array
    {
        return Database::todos('SELECT * FROM corte_movimientos WHERE corte_id = ? ORDER BY tipo, id', [$corteId]);
    }

    public static function departamentosDe(int $corteId): array
    {
        return Database::todos('SELECT * FROM corte_departamentos WHERE corte_id = ? ORDER BY monto DESC', [$corteId]);
    }

    /** Correo crudo de un corte histórico que llegó por Eleventa (ver docblock de la clase). */
    public static function correoDe(int $correoId): ?array
    {
        return Database::uno(
            'SELECT asunto, remitente, recibido_en, origen, cuerpo FROM correos_corte WHERE id = ?',
            [$correoId]
        );
    }
}
