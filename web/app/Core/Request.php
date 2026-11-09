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

    /**
     * La ruta de la URL para el Router: sin query string y sin el prefijo de
     * carpeta donde vive index.php. Ese prefijo se calcula solo con
     * SCRIPT_NAME (en vez de configurarlo a mano): en local XAMPP es
     * "/minimark" (http://localhost/minimark/...), en producción es "" (el
     * dominio apunta directo a web/) — funciona igual en los dos sin tocar
     * nada acá cuando se suba al servidor.
     */
    public static function ruta(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $ruta = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($base !== '' && str_starts_with($ruta, $base)) {
            $ruta = substr($ruta, strlen($base));
        }
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
