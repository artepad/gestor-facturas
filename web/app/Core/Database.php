<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Envoltorio sobre la MISMA conexion que ya usa todo el codigo viejo
 * (obtener_pdo() de lib/db.php, que es un singleton). No se crea una
 * segunda conexion: el codigo nuevo y el viejo comparten una sola.
 */
final class Database
{
    public static function pdo(): PDO
    {
        require_once dirname(__DIR__, 2) . '/lib/db.php';
        return obtener_pdo();
    }

    /** Una fila, o null si no hay resultado. */
    public static function uno(string $sql, array $parametros = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($parametros);
        $fila = $st->fetch();
        return $fila === false ? null : $fila;
    }

    /** Todas las filas. */
    public static function todos(string $sql, array $parametros = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($parametros);
        return $st->fetchAll();
    }

    /** Un solo valor (p. ej. un COUNT(*)). */
    public static function valor(string $sql, array $parametros = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($parametros);
        return $st->fetchColumn();
    }

    /** INSERT/UPDATE/DELETE preparado. Devuelve filas afectadas. */
    public static function consultar(string $sql, array $parametros = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($parametros);
        return $st->rowCount();
    }
}
