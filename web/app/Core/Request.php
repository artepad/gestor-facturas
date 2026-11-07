<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lectura de la peticion HTTP. Solo strings (ignora arrays inesperados de
 * $_GET/$_POST) y recorta espacios — los datos del usuario nunca son de
 * fiar, asi que esta es la unica puerta de entrada.
 */
final class Request
{
    public static function metodo(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** La ruta de la URL, sin query string (para el Router). */
    public static function ruta(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $ruta = parse_url($uri, PHP_URL_PATH) ?: '/';
        return $ruta === '' ? '/' : $ruta;
    }

    public static function get(string $clave, string $defecto = ''): string
    {
        $v = $_GET[$clave] ?? $defecto;
        return is_string($v) ? trim($v) : $defecto;
    }

    public static function post(string $clave, string $defecto = ''): string
    {
        $v = $_POST[$clave] ?? $defecto;
        return is_string($v) ? trim($v) : $defecto;
    }

    public static function entero(string $clave, int $defecto = 0): int
    {
        $v = $_GET[$clave] ?? $_POST[$clave] ?? null;
        return is_numeric($v) ? (int)$v : $defecto;
    }
}
