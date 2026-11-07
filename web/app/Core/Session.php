<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Envoltorio sobre la sesion que ya inicia lib/auth.php (misma cookie,
 * mismos 30 dias, mismo httponly/samesite — no se duplica nada). Lo nuevo
 * que agrega esta clase es el token CSRF (hallazgo H3 de la auditoria), que
 * el codigo viejo todavia no tiene.
 */
final class Session
{
    public static function iniciar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        iniciar_sesion();
    }

    public static function get(string $clave, mixed $defecto = null): mixed
    {
        self::iniciar();
        return $_SESSION[$clave] ?? $defecto;
    }

    public static function set(string $clave, mixed $valor): void
    {
        self::iniciar();
        $_SESSION[$clave] = $valor;
    }

    /** Mensaje de un solo uso (flash): se lee una vez y se borra solo. */
    public static function flash(string $clave, ?string $valor = null): ?string
    {
        self::iniciar();
        if ($valor !== null) {
            $_SESSION['_flash'][$clave] = $valor;
            return null;
        }
        $v = $_SESSION['_flash'][$clave] ?? null;
        unset($_SESSION['_flash'][$clave]);
        return $v;
    }

    /** Token CSRF de la sesion (se crea una vez y se reusa). */
    public static function tokenCsrf(): string
    {
        self::iniciar();
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    /** Compara con hash_equals (a prueba de timing attack). */
    public static function csrfValido(?string $recibido): bool
    {
        self::iniciar();
        $guardado = $_SESSION['_csrf'] ?? null;
        return is_string($guardado) && is_string($recibido) && $recibido !== ''
            && hash_equals($guardado, $recibido);
    }
}
