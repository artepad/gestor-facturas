<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Datos del tablero de inicio: cruza Ingresos (cortes), Facturas y Fiados
 * por negocio. Consultas propias (sin equivalente previo en ningún lib/*.php
 * — vivían sueltas dentro de home.php); los gastos se piden a
 * App\Models\Gasto, que sí envuelve lib/gastos.php.
 */
final class Home
{
    /** Mapa negocio_id => total vendido (de `cortes`) en el período. */
    public static function ventasPorNegocio(array $ids, string $desde, string $hasta): array
    {
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $mapa = [];
        foreach (Database::todos(
            "SELECT negocio_id, COALESCE(SUM(ventas_totales),0) v
               FROM cortes WHERE negocio_id IN ($ph) AND DATE(cerrado_en) BETWEEN ? AND ?
              GROUP BY negocio_id",
            [...$ids, $desde, $hasta]
        ) as $r) {
            $mapa[(int)$r['negocio_id']] = (float)$r['v'];
        }
        return $mapa;
    }

    /** Total vendido (sin desglosar por negocio) en el período. */
    public static function ventasTotal(array $ids, string $desde, string $hasta): float
    {
        if (!$ids) return 0.0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return (float)Database::valor(
            "SELECT COALESCE(SUM(ventas_totales),0) FROM cortes
              WHERE negocio_id IN ($ph) AND DATE(cerrado_en) BETWEEN ? AND ?",
            [...$ids, $desde, $hasta]
        );
    }

    /** Ventas día a día del período (para el gráfico de barras). */
    public static function ventasPorDia(array $ids, string $desde, string $hasta): array
    {
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Database::todos(
            "SELECT DATE(cerrado_en) d, COALESCE(SUM(ventas_totales),0) v
               FROM cortes WHERE negocio_id IN ($ph) AND DATE(cerrado_en) BETWEEN ? AND ?
              GROUP BY DATE(cerrado_en) ORDER BY d",
            [...$ids, $desde, $hasta]
        );
    }

    /** Mapa negocio_id => ['m' => monto comprado, 'n' => cantidad de facturas] en el período. */
    public static function comprasPorNegocio(array $ids, string $desde, string $hasta): array
    {
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $mapa = [];
        foreach (Database::todos(
            "SELECT negocio_id, COALESCE(SUM(total),0) m, COUNT(*) n
               FROM facturas WHERE negocio_id IN ($ph) AND eliminada_en IS NULL AND fecha BETWEEN ? AND ?
              GROUP BY negocio_id",
            [...$ids, $desde, $hasta]
        ) as $r) {
            $mapa[(int)$r['negocio_id']] = ['m' => (float)$r['m'], 'n' => (int)$r['n']];
        }
        return $mapa;
    }

    /** Mapa negocio_id => cantidad de facturas con confianza baja (< 0.7), pendientes de revisar. */
    public static function facturasPorRevisarPorNegocio(array $ids): array
    {
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $mapa = [];
        foreach (Database::todos(
            "SELECT negocio_id, COUNT(*) n FROM facturas
              WHERE negocio_id IN ($ph) AND eliminada_en IS NULL
                AND confianza IS NOT NULL AND confianza < 0.7
              GROUP BY negocio_id",
            $ids
        ) as $r) {
            $mapa[(int)$r['negocio_id']] = (int)$r['n'];
        }
        return $mapa;
    }

    /** Cortes de Eleventa que llegaron por correo pero no se pudieron leer. */
    public static function correosErrorCount(array $ids): int
    {
        if (!$ids) return 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return (int)Database::valor(
            "SELECT COUNT(*) FROM correos_corte
              WHERE estado = 'error' AND (negocio_id IS NULL OR negocio_id IN ($ph))",
            $ids
        );
    }

    /** Mapa negocio_id => ['n' => clientes con deuda, 'monto' => total por cobrar]. */
    public static function fiadosConDeudaPorNegocio(array $ids): array
    {
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $mapa = [];
        foreach (Database::todos(
            "SELECT t.negocio_id, COUNT(*) n, COALESCE(SUM(t.saldo),0) monto
               FROM (
                   SELECT c.id, c.negocio_id,
                       (SELECT COALESCE(SUM(f.monto),0) FROM fiados f WHERE f.cliente_id = c.id)
                     - (SELECT COALESCE(SUM(a.monto),0) FROM abonos a WHERE a.cliente_id = c.id) AS saldo
                   FROM clientes c WHERE c.negocio_id IN ($ph) AND c.activo = 1
               ) t
              WHERE t.saldo > 0
              GROUP BY t.negocio_id",
            $ids
        ) as $r) {
            $mapa[(int)$r['negocio_id']] = ['n' => (int)$r['n'], 'monto' => (float)$r['monto']];
        }
        return $mapa;
    }
}
