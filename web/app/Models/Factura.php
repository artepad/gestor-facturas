<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Facturas (espejo de las sincronizadas desde la app de escritorio — la
 * fuente de verdad es la BD local de cada PC, ver CONTRATO.md). SQL propio,
 * sin equivalente previo en ningún lib/*.php (vivía suelto en
 * panel_facturas.php y factura.php) — mismo criterio que App\Models\Cliente.
 */
final class Factura
{
    public static function paraPdf(int $id): ?array
    {
        return Database::uno(
            'SELECT negocio_id, ruta_pdf, numero_factura FROM facturas WHERE id = ?',
            [$id]
        );
    }

    /**
     * Listado filtrado del panel. $idsVisibles ya viene acotado a los
     * negocios del usuario (0=1 si viene vacío: sucursal sin negocios no ve nada).
     */
    public static function listado(array $idsVisibles, array $filtros): array
    {
        [$where, $params] = self::condicionListado($idsVisibles, $filtros);
        return Database::todos(
            "SELECT f.*, n.nombre AS negocio_nombre
               FROM facturas f JOIN negocios n ON n.id = f.negocio_id
              WHERE $where
              ORDER BY f.fecha DESC, f.id DESC LIMIT 500",
            $params
        );
    }

    private static function condicionListado(array $idsVisibles, array $filtros): array
    {
        $where = [];
        $params = [];

        if (empty($idsVisibles)) {
            $where[] = '0=1';
        } else {
            $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
            $where[] = "f.negocio_id IN ($ph)";
            array_push($params, ...$idsVisibles);
        }
        if (!empty($filtros['negocio']) && in_array($filtros['negocio'], $idsVisibles, true)) {
            $where[] = 'f.negocio_id = ?';
            $params[] = $filtros['negocio'];
        }
        if (!empty($filtros['proveedor'])) {
            $where[] = 'f.proveedor = ?'; // viene de un combo: coincidencia exacta
            $params[] = $filtros['proveedor'];
        }
        if (!empty($filtros['desde'])) { $where[] = 'f.fecha >= ?'; $params[] = $filtros['desde']; }
        if (!empty($filtros['hasta'])) { $where[] = 'f.fecha <= ?'; $params[] = $filtros['hasta']; }
        if (!empty($filtros['texto'])) {
            $where[] = '(f.proveedor LIKE ? OR f.razon_social LIKE ? OR f.numero_factura LIKE ?)';
            $t = '%' . $filtros['texto'] . '%';
            $params[] = $t; $params[] = $t; $params[] = $t;
        }
        $where[] = 'f.eliminada_en IS NULL';

        return [implode(' AND ', $where), $params];
    }

    /** Proveedores con facturas en los negocios visibles (para el combo de filtro). */
    public static function proveedoresDe(array $idsVisibles): array
    {
        if (!$idsVisibles) return [];
        $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
        $st = Database::pdo()->prepare(
            "SELECT DISTINCT proveedor FROM facturas
              WHERE negocio_id IN ($ph) AND eliminada_en IS NULL
                AND proveedor IS NOT NULL AND proveedor <> ''
              ORDER BY proveedor"
        );
        $st->execute($idsVisibles);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function porId(int $id): ?array
    {
        return Database::uno(
            "SELECT f.*, n.nombre AS negocio_nombre
               FROM facturas f JOIN negocios n ON n.id = f.negocio_id
              WHERE f.id = ? AND f.eliminada_en IS NULL",
            [$id]
        );
    }

    public static function detalleDe(int $facturaId): array
    {
        return Database::todos('SELECT * FROM detalle_factura WHERE factura_id = ? ORDER BY id', [$facturaId]);
    }
}
