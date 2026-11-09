<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;

/**
 * Ingreso y salida de sesión.
 *   login.php  -> login()
 *   logout.php -> logout()
 *
 * No extiende BaseController: acá es al revés de las demás pantallas (si
 * HAY sesión, se redirige a home.php en vez de exigirla). Toda la lógica de
 * autenticación real sigue en lib/auth.php (login(), cerrar_sesion(),
 * motivo_bloqueo_login()) — no se duplica.
 */
final class AuthController
{
    private const COOKIE_RECORDAR = 'mm_recordar';

    public function login(array $parametros = []): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()

        if (\usuario_actual()) {
            \redirigir('home.php');
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');

        // "Usar otro correo": olvida el correo recordado y recarga limpio.
        if (Request::get('olvidar') !== '') {
            setcookie(self::COOKIE_RECORDAR, '', time() - 3600, '/', '', $https, true);
            \redirigir('login.php');
        }

        $error = '';
        $emailPrefill = $_COOKIE[self::COOKIE_RECORDAR] ?? '';

        if (Request::metodo() === 'POST') {
            $email = Request::post('email');
            $clave = (string)($_POST['clave'] ?? ''); // nunca se trimea una clave
            $recordar = isset($_POST['recordar']);

            // Recordar (u olvidar) el correo según el checkbox. Nunca se guarda la clave.
            if ($recordar && $email !== '') {
                setcookie(self::COOKIE_RECORDAR, $email, time() + 60 * 60 * 24 * 30, '/', '', $https, true);
            } else {
                setcookie(self::COOKIE_RECORDAR, '', time() - 3600, '/', '', $https, true);
            }

            if ($motivo = \motivo_bloqueo_login($email)) {
                $error = $motivo;
            } elseif (\login($email, $clave)) {
                \redirigir('home.php');
            } else {
                $error = 'Correo o contraseña incorrectos.';
            }
            $emailPrefill = $email;
        }

        $recordado = $emailPrefill !== '';

        View::render('auth/login', compact('error', 'emailPrefill', 'recordado'));
    }

    public function logout(array $parametros = []): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        \cerrar_sesion();
        \redirigir('login.php');
    }
}
