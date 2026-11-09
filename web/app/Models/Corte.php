<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Cortes de caja de Eleventa (módulo Ingresos): el dashboard, el detalle de
 * un corte y el registro manual de uno pegando el correo. El parser del
 * correo vive en lib/eleventa.php y NO se toca (lo sigue usando también
 * procesar_cortes.php, el worker de cron — ver nota en el bridge file sobre
 * por qué esa pantalla quedó fuera de esta migración). Para lo demás, SQL
 * propio: estas consultas vivían sueltas en ingresos.php/corte.php.
 */
final class Corte
{
    private static function cargarEleventa(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/eleventa.php';
    }

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
    public static function resumenPeriodo(array $ids, string $desde, string $hasta, int $cajeroId = 0): array
    {
        if (!$ids) return ['ventas' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0, 'salidas' => 0, 'n' => 0, 'nventas' => 0];
        [$cond, $par] = self::condicion($ids, $desde, $hasta, $cajeroId);
        $st = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(c.ventas_totales),0), COALESCE(SUM(c.ventas_efectivo),0),
                    COALESCE(SUM(c.ventas_tarjeta),0), COALESCE(SUM(c.ventas_transferencia),0),
                    COALESCE(SUM(c.salidas_caja),0), COUNT(*), COALESCE(SUM(c.numero_ventas),0)
               FROM cortes c WHERE $cond"
        );
        $st->execute($par);
        [$ventas, $efectivo, $tarjeta, $transferencia, $salidas, $n, $nventas] = array_values($st->fetch(PDO::FETCH_NUM));
        return [
            'ventas' => (float)$ventas, 'efectivo' => (float)$efectivo, 'tarjeta' => (float)$tarjeta,
            'transferencia' => (float)$transferencia, 'salidas' => (float)$salidas,
            'n' => (int)$n, 'nventas' => (int)$nventas,
        ];
    }

    public static function ventasPorDia(array $ids, string $desde, string $hasta, int $cajeroId = 0): array
    {
        if (!$ids) return [];
        [$cond, $par] = self::condicion($ids, $desde, $hasta, $cajeroId);
        return Database::todos(
            "SELECT DATE(c.cerrado_en) d, COALESCE(SUM(c.ventas_totales),0) v
               FROM cortes c WHERE $cond GROUP BY DATE(c.cerrado_en) ORDER BY d",
            $par
        );
    }

    public static function porCajero(array $ids, string $desde, string $hasta, int $cajeroId = 0): array
    {
        if (!$ids) return [];
        [$cond, $par] = self::condicion($ids, $desde, $hasta, $cajeroId);
        return Database::todos(
            "SELECT cj.nombre, u.nombre AS usuario_web, COUNT(*) n,
                    COALESCE(SUM(c.ventas_totales),0) ventas,
                    COALESCE(SUM(c.ventas_efectivo),0) efectivo,
                    COALESCE(SUM(c.salidas_caja),0) salidas
               FROM cortes c
               LEFT JOIN cajeros cj ON cj.id = c.cajero_id
               LEFT JOIN usuarios u ON u.id = cj.usuario_id
              WHERE $cond GROUP BY c.cajero_id, cj.nombre, u.nombre ORDER BY ventas DESC",
            $par
        );
    }

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

    /** Correos que llegaron pero no se pudieron leer como un corte (estado 'error'). */
    public static function correosPendientes(array $idsVisibles, int $limite = 50): array
    {
        if (!$idsVisibles) return [];
        $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
        return Database::todos(
            "SELECT id, asunto, destinatario, recibido_en, origen, error
               FROM correos_corte
              WHERE estado = 'error' AND (negocio_id IS NULL OR negocio_id IN ($ph))
              ORDER BY id DESC LIMIT " . (int)$limite,
            $idsVisibles
        );
    }

    public static function correoNegocioId(int $correoId): ?int
    {
        $v = Database::valor('SELECT negocio_id FROM correos_corte WHERE id = ?', [$correoId]);
        return $v === false || $v === null ? null : (int)$v;
    }

    public static function descartarCorreo(int $correoId): void
    {
        Database::consultar("UPDATE correos_corte SET estado = 'descartado' WHERE id = ?", [$correoId]);
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

    public static function correoDe(int $correoId): ?array
    {
        return Database::uno(
            'SELECT asunto, remitente, recibido_en, origen, cuerpo FROM correos_corte WHERE id = ?',
            [$correoId]
        );
    }

    // ---- Registro manual pegando el correo (corte_pegar.php) ----

    public static function correoPorMessageId(string $msgId): ?int
    {
        $v = Database::valor('SELECT id FROM correos_corte WHERE message_id = ?', [$msgId]);
        return $v === false ? null : (int)$v;
    }

    public static function actualizarCorreoManual(int $correoId, ?string $asunto, string $cuerpo): void
    {
        Database::consultar(
            "UPDATE correos_corte SET asunto = ?, cuerpo = ?, estado = 'pendiente', error = NULL WHERE id = ?",
            [$asunto, $cuerpo, $correoId]
        );
    }

    public static function crearCorreoManual(int $negocioId, string $msgId, ?string $asunto, string $cuerpo): int
    {
        Database::consultar(
            "INSERT INTO correos_corte (negocio_id, message_id, asunto, cuerpo, origen, recibido_en)
             VALUES (?, ?, ?, ?, 'manual', NOW())",
            [$negocioId, $msgId, $asunto, $cuerpo]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function correoCompletoPorId(int $id): ?array
    {
        return Database::uno('SELECT * FROM correos_corte WHERE id = ?', [$id]);
    }

    public static function errorDeCorreo(int $id): ?string
    {
        $v = Database::valor('SELECT error FROM correos_corte WHERE id = ?', [$id]);
        return $v === false ? null : $v;
    }

    /** Envoltorio de lib/eleventa.php: parsea el correo y registra el corte (o deja el error en correos_corte). */
    public static function procesarCorreo(array $correo): ?int
    {
        self::cargarEleventa();
        return \procesar_correo_corte(Database::pdo(), $correo);
    }
}
