<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Renderiza un archivo de app/views/ con variables locales. Se usara desde
 * la Fase 6 en adelante, cuando cada pantalla se vaya migrando; por ahora
 * no hay vistas todavia.
 */
final class View
{
    public static function render(string $vista, array $datos = []): void
    {
        $archivo = dirname(__DIR__) . '/views/' . $vista . '.php';
        if (!is_file($archivo)) {
            throw new \RuntimeException("Vista no encontrada: $vista");
        }
        extract($datos, EXTR_SKIP);
        require $archivo;
    }
}
