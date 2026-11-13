<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Facturas (espejo de las sincronizadas desde la app de escritorio — la
 * fuente de verdad es la BD local de cada PC, ver docs/CONTRATO.md). SQL propio,
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

    /**
     * Busca una posible factura duplicada por folio + RUT del emisor dentro
     * de un negocio — misma regla que usa el programa de escritorio
     * (db.buscar_duplicado), ahora comparando contra TODO el negocio en la
     * web, no solo lo escaneado en un PC. Null si falta folio o RUT (no hay
     * forma confiable de comparar).
     */
    public static function buscarDuplicada(int $negocioId, ?string $numeroFactura, ?string $rutEmisor): ?array
    {
        $numeroFactura = trim((string)$numeroFactura);
        $rutEmisor = trim((string)$rutEmisor);
        if ($numeroFactura === '' || $rutEmisor === '') {
            return null;
        }
        return Database::uno(
            "SELECT id, proveedor, fecha, total FROM facturas
              WHERE negocio_id = ? AND numero_factura = ? AND rut_emisor = ? AND eliminada_en IS NULL
              ORDER BY id DESC LIMIT 1",
            [$negocioId, $numeroFactura, $rutEmisor]
        );
    }

    /**
     * Crea una factura subida directo desde la web (origen='web'), con un
     * uuid propio (no viene de ningún PC) para mantener el mismo esquema que
     * las sincronizadas. Devuelve el id nuevo.
     */
    public static function crearDesdeWeb(array $campos, int $negocioId, int $creadaPor): int
    {
        $pdo = Database::pdo();
        $uuid = self::generarUuid4();
        $st = $pdo->prepare(
            "INSERT INTO facturas
               (uuid_local, negocio_id, proveedor, razon_social, rut_emisor, numero_factura,
                fecha, total, moneda, confianza, notas, ruta_pdf, origen, creada_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'web', ?)"
        );
        $st->execute([
            $uuid, $negocioId,
            $campos['proveedor'], $campos['razon_social'], $campos['rut_emisor'], $campos['numero_factura'],
            $campos['fecha'], $campos['total'], $campos['moneda'], $campos['confianza'], $campos['notas'],
            $campos['ruta_pdf'], $creadaPor,
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function generarUuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
