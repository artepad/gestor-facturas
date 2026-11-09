<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Negocios (almacenes): perfil, máquinas/tokens de sincronización, y el
 * panel de análisis (ventas, compras, gastos, fiados de un negocio). SQL
 * propio — sin equivalente previo en ningún lib/*.php. Donde ya existe un
 * Modelo para esa métrica (Gasto, Home) se reutiliza en vez de duplicar la
 * consulta.
 */
final class Negocio
{
    // ---- Listado y perfil (negocios.php, negocio_form.php) ----

    public static function listadoConConteos(): array
    {
        return Database::todos(
            "SELECT n.*,
                    (SELECT COUNT(*) FROM facturas f
                      WHERE f.negocio_id = n.id AND f.eliminada_en IS NULL) AS n_facturas,
                    (SELECT COUNT(*) FROM maquinas m WHERE m.negocio_id = n.id) AS n_maquinas
               FROM negocios n
              ORDER BY n.activo DESC, n.nombre"
        );
    }

    public static function porId(int $id): ?array
    {
        return Database::uno('SELECT * FROM negocios WHERE id = ?', [$id]);
    }

    /** Slug único a partir del nombre (quita tildes/ñ, minúsculas, guiones; agrega -2, -3... si choca). */
    public static function generarSlug(string $nombre, int $excluirId = 0): string
    {
        $base = strtolower(trim($nombre));
        $base = preg_replace('/[áàä]/u', 'a', $base);
        $base = preg_replace('/[éèë]/u', 'e', $base);
        $base = preg_replace('/[íìï]/u', 'i', $base);
        $base = preg_replace('/[óòö]/u', 'o', $base);
        $base = preg_replace('/[úùü]/u', 'u', $base);
        $base = preg_replace('/ñ/u', 'n', $base);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim($base, '-') ?: 'negocio';

        $slug = $base;
        $i = 2;
        $pdo = Database::pdo();
        while (true) {
            $st = $pdo->prepare('SELECT id FROM negocios WHERE slug = ? AND id <> ?');
            $st->execute([$slug, $excluirId]);
            if (!$st->fetch()) return $slug;
            $slug = "$base-$i";
            $i++;
        }
    }

    public static function crear(array $d): int
    {
        $slug = self::generarSlug($d['nombre']);
        Database::consultar(
            "INSERT INTO negocios (slug, nombre, rut, telefono, direccion, correo, activo)
             VALUES (?,?,?,?,?,?,?)",
            [$slug, $d['nombre'], $d['rut'], $d['telefono'], $d['direccion'], $d['correo'], $d['activo']]
        );
        return (int)Database::pdo()->lastInsertId();
    }

    public static function actualizar(int $id, array $d): void
    {
        Database::consultar(
            "UPDATE negocios SET nombre=?, rut=?, telefono=?, direccion=?, correo=?, activo=? WHERE id=?",
            [$d['nombre'], $d['rut'], $d['telefono'], $d['direccion'], $d['correo'], $d['activo'], $id]
        );
    }

    // ---- Máquinas y tokens (negocio_tokens.php) ----

    public static function maquinasDe(int $negocioId): array
    {
        return Database::todos('SELECT * FROM maquinas WHERE negocio_id = ? ORDER BY id', [$negocioId]);
    }

    public static function crearMaquina(int $negocioId, string $nombre, string $tokenHash): void
    {
        Database::consultar(
            'INSERT INTO maquinas (negocio_id, nombre, token_hash) VALUES (?,?,?)',
            [$negocioId, $nombre, $tokenHash]
        );
    }

    public static function eliminarMaquina(int $maquinaId, int $negocioId): void
    {
        Database::consultar('DELETE FROM maquinas WHERE id = ? AND negocio_id = ?', [$maquinaId, $negocioId]);
    }

    // ---- Panel de análisis de un negocio (negocio_panel.php) ----

    /** Resumen de ventas del período (cortes): total, por medio de pago, salidas y n° de ventas. */
    public static function ventasResumen(int $negocioId, string $desde, string $hasta): array
    {
        $st = Database::pdo()->prepare(
            "SELECT COALESCE(SUM(ventas_totales),0), COALESCE(SUM(ventas_efectivo),0),
                    COALESCE(SUM(ventas_tarjeta),0), COALESCE(SUM(ventas_transferencia),0),
                    COALESCE(SUM(ventas_credito),0), COALESCE(SUM(ventas_vales),0),
                    COALESCE(SUM(salidas_caja),0), COALESCE(SUM(numero_ventas),0)
               FROM cortes WHERE negocio_id = ? AND DATE(cerrado_en) BETWEEN ? AND ?"
        );
        $st->execute([$negocioId, $desde, $hasta]);
        [$total, $efectivo, $tarjeta, $transferencia, $credito, $vales, $salidas, $nventas] =
            array_map('floatval', array_values($st->fetch(PDO::FETCH_NUM)));
        return [
            'total' => $total, 'efectivo' => $efectivo, 'tarjeta' => $tarjeta,
            'transferencia' => $transferencia, 'credito' => $credito, 'vales' => $vales,
            'salidas' => $salidas, 'nventas' => (int)$nventas,
        ];
    }

    /** Ventas por departamento del período (top 8). */
    public static function ventasPorDepartamento(int $negocioId, string $desde, string $hasta): array
    {
        return Database::todos(
            "SELECT cd.departamento, COALESCE(SUM(cd.monto),0) total
               FROM corte_departamentos cd JOIN cortes c ON c.id = cd.corte_id
              WHERE c.negocio_id = ? AND DATE(c.cerrado_en) BETWEEN ? AND ?
              GROUP BY cd.departamento ORDER BY total DESC LIMIT 8",
            [$negocioId, $desde, $hasta]
        );
    }

    /** Productos más comprados del período, por monto (top 8). */
    public static function topComprados(int $negocioId, string $desde, string $hasta): array
    {
        return Database::todos(
            "SELECT df.descripcion, COALESCE(SUM(df.monto),0) total
               FROM detalle_factura df JOIN facturas f ON f.id = df.factura_id
              WHERE f.negocio_id = ? AND f.eliminada_en IS NULL AND f.fecha BETWEEN ? AND ?
                AND df.descripcion IS NOT NULL AND df.descripcion <> ''
              GROUP BY df.descripcion ORDER BY total DESC LIMIT 8",
            [$negocioId, $desde, $hasta]
        );
    }

    /** Deuda vigente (Σ saldos positivos de los clientes activos del negocio; no depende del período). */
    public static function deudaVigente(int $negocioId): float
    {
        return (float)Database::valor(
            "SELECT COALESCE(SUM(GREATEST(0,
                    (SELECT COALESCE(SUM(f.monto),0) FROM fiados f WHERE f.cliente_id = c.id)
                  - (SELECT COALESCE(SUM(a.monto),0) FROM abonos a WHERE a.cliente_id = c.id))),0)
               FROM clientes c WHERE c.negocio_id = ? AND c.activo = 1",
            [$negocioId]
        );
    }
}
