<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Gastos operacionales del negocio: categorías, gastos fijos mensuales
 * (plantillas) y los gastos reales. Los totales/desgloses/generación de
 * fijos envuelven lib/gastos.php (compartido con home.php y
 * negocio_panel.php — NO se toca ese archivo). El CRUD de gastos,
 * categorías y plantillas no tenía equivalente ahí, así que usa SQL
 * directo — mismo criterio que App\Models\Cliente y App\Models\PrecioFV.
 */
final class Gasto
{
    private static function cargar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/gastos.php';
    }

    // ---- Totales, desglose y generación de fijos (envoltorio de lib/gastos.php) ----

    public static function categorias(bool $soloActivas = true): array
    {
        self::cargar();
        return \categorias_gasto(Database::pdo(), $soloActivas);
    }

    public static function totalPeriodo(array $ids, string $desde, string $hasta): float
    {
        self::cargar();
        return \gastos_total(Database::pdo(), $ids, $desde, $hasta);
    }

    public static function porCategoria(array $ids, string $desde, string $hasta): array
    {
        self::cargar();
        return \gastos_por_categoria(Database::pdo(), $ids, $desde, $hasta);
    }

    /** Mapa negocio_id => total de gastos del período (usado por Home). */
    public static function porNegocio(array $ids, string $desde, string $hasta): array
    {
        self::cargar();
        return \gastos_por_negocio(Database::pdo(), $ids, $desde, $hasta);
    }

    public static function generarFijosDelMes(int $negocioId, string $mes, ?int $usuarioId): int
    {
        self::cargar();
        return \registrar_gastos_fijos(Database::pdo(), $negocioId, $mes, $usuarioId);
    }

    // ---- Gastos (CRUD directo; sin equivalente previo en lib/gastos.php) ----

    /** [total, cantidad] del período, con filtro de categoría opcional. */
    public static function totalYCantidad(array $ids, string $desde, string $hasta, int $categoriaId = 0): array
    {
        if (!$ids) return [0.0, 0];
        [$cond, $par] = self::condicionPeriodo('negocio_id', $ids, $desde, $hasta, $categoriaId);
        $st = Database::pdo()->prepare("SELECT COALESCE(SUM(monto),0), COUNT(*) FROM gastos WHERE $cond");
        $st->execute($par);
        [$total, $n] = array_values($st->fetch(PDO::FETCH_NUM));
        return [(float)$total, (int)$n];
    }

    public static function listado(array $ids, string $desde, string $hasta, int $categoriaId = 0, int $limite = 300): array
    {
        if (!$ids) return [];
        [$cond, $par] = self::condicionPeriodo('g.negocio_id', $ids, $desde, $hasta, $categoriaId, 'g.categoria_id');
        return Database::todos(
            "SELECT g.*, cg.nombre AS categoria, n.nombre AS negocio
               FROM gastos g
               JOIN categorias_gasto cg ON cg.id = g.categoria_id
               JOIN negocios n ON n.id = g.negocio_id
              WHERE $cond ORDER BY g.fecha DESC, g.id DESC LIMIT " . (int)$limite,
            $par
        );
    }

    private static function condicionPeriodo(
        string $campoNegocio, array $ids, string $desde, string $hasta, int $categoriaId, string $campoCategoria = 'categoria_id'
    ): array {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $cond = "$campoNegocio IN ($ph) AND fecha BETWEEN ? AND ?";
        $par = [...$ids, $desde, $hasta];
        if ($categoriaId) { $cond .= " AND $campoCategoria = ?"; $par[] = $categoriaId; }
        return [$cond, $par];
    }

    public static function porId(int $id): ?array
    {
        return Database::uno('SELECT * FROM gastos WHERE id = ?', [$id]);
    }

    public static function crear(array $d, int $creadoPor): int
    {
        Database::consultar(
            "INSERT INTO gastos (negocio_id, categoria_id, fecha, monto, descripcion, creado_por)
             VALUES (?,?,?,?,?,?)",
            [$d['negocio_id'], $d['categoria_id'], $d['fecha'], $d['monto'], $d['descripcion'], $creadoPor]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    /** Al editar no se cambia de negocio (evita fugas entre negocios). */
    public static function actualizar(int $id, array $d): void
    {
        Database::consultar(
            "UPDATE gastos SET categoria_id=?, fecha=?, monto=?, descripcion=? WHERE id=?",
            [$d['categoria_id'], $d['fecha'], $d['monto'], $d['descripcion'], $id]
        );
    }

    public static function eliminar(int $id): void
    {
        Database::consultar('DELETE FROM gastos WHERE id = ?', [$id]);
    }

    // ---- Categorías de gasto (CRUD directo) ----

    public static function usosPorCategoria(): array
    {
        $mapa = [];
        foreach (Database::todos('SELECT categoria_id, COUNT(*) n FROM gastos GROUP BY categoria_id') as $r) {
            $mapa[(int)$r['categoria_id']] = (int)$r['n'];
        }
        return $mapa;
    }

    public static function crearCategoria(string $nombre): void
    {
        Database::consultar('INSERT INTO categorias_gasto (nombre, orden) VALUES (?, 100)', [$nombre]);
    }

    public static function renombrarCategoria(int $id, string $nombre): void
    {
        Database::consultar('UPDATE categorias_gasto SET nombre = ? WHERE id = ?', [$nombre, $id]);
    }

    public static function toggleCategoria(int $id): void
    {
        Database::consultar('UPDATE categorias_gasto SET activo = 1 - activo WHERE id = ?', [$id]);
    }

    /** Gastos + plantillas de esa categoría (0 => se puede borrar). */
    public static function usosDeCategoria(int $id): int
    {
        $n1 = (int)Database::valor('SELECT COUNT(*) FROM gastos WHERE categoria_id = ?', [$id]);
        $n2 = (int)Database::valor('SELECT COUNT(*) FROM gastos_fijos WHERE categoria_id = ?', [$id]);
        return $n1 + $n2;
    }

    public static function eliminarCategoria(int $id): void
    {
        Database::consultar('DELETE FROM categorias_gasto WHERE id = ?', [$id]);
    }

    // ---- Gastos fijos: plantillas mensuales (CRUD directo) ----

    public static function fijosDe(array $idsVisibles): array
    {
        if (!$idsVisibles) return [];
        $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
        return Database::todos(
            "SELECT gf.*, cg.nombre AS categoria, n.nombre AS negocio
               FROM gastos_fijos gf
               JOIN categorias_gasto cg ON cg.id = gf.categoria_id
               JOIN negocios n ON n.id = gf.negocio_id
              WHERE gf.negocio_id IN ($ph)
              ORDER BY n.nombre, cg.nombre",
            $idsVisibles
        );
    }

    public static function fijoPorId(int $id): ?array
    {
        return Database::uno('SELECT * FROM gastos_fijos WHERE id = ?', [$id]);
    }

    public static function crearFijo(array $d): int
    {
        Database::consultar(
            "INSERT INTO gastos_fijos (negocio_id, categoria_id, descripcion, monto_estimado, dia_mes)
             VALUES (?,?,?,?,?)",
            [$d['negocio_id'], $d['categoria_id'], $d['descripcion'], $d['monto_estimado'], $d['dia_mes']]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    /** Al editar no se cambia de negocio (evita fugas entre negocios). */
    public static function actualizarFijo(int $id, array $d): void
    {
        Database::consultar(
            "UPDATE gastos_fijos SET categoria_id=?, descripcion=?, monto_estimado=?, dia_mes=? WHERE id=?",
            [$d['categoria_id'], $d['descripcion'], $d['monto_estimado'], $d['dia_mes'], $id]
        );
    }

    public static function toggleFijo(int $id): void
    {
        Database::consultar('UPDATE gastos_fijos SET activo = 1 - activo WHERE id = ?', [$id]);
    }

    public static function eliminarFijo(int $id): void
    {
        Database::consultar('DELETE FROM gastos_fijos WHERE id = ?', [$id]);
    }
}
