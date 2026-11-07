<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Acceso de solo lectura al config.php que ya existe en la raiz de web/.
 *
 * No se cambia la forma de config.php (seria tocar algo que ya funciona sin
 * necesidad). Esta clase solo le agrega una forma comoda de leerlo:
 * Config::get('db.host'), Config::get('setup_key'), etc.
 */
final class Config
{
    private static ?array $datos = null;

    private static function cargar(): array
    {
        if (self::$datos === null) {
            require_once dirname(__DIR__, 2) . '/lib/db.php';
            self::$datos = cargar_config();   // la funcion de lib/db.php, reutilizada
        }
        return self::$datos;
    }

    /** Config::get('db.host') o Config::get('setup_key'). $defecto si no existe. */
    public static function get(string $clave, mixed $defecto = null): mixed
    {
        $valor = self::cargar();
        foreach (explode('.', $clave) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return $defecto;
            }
            $valor = $valor[$parte];
        }
        return $valor;
    }

    /** true en admin.minimark.cl real; false en localhost/XAMPP. */
    public static function esProduccion(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return $host !== '' && $host !== 'localhost' && !str_starts_with($host, '127.0.0.1');
    }
}
