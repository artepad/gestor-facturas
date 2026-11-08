<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;

/**
 * Base de los controladores del POS: exige sesion+permiso y centraliza el
 * CSRF (hallazgo H3 de la auditoria).
 *
 * Fase 9 (despliegue): cada pantalla del POS vive ahora en un archivo puente
 * en la raiz de web/ (ej. web/caja.php) que llama directo a su Controlador —
 * misma direccion de siempre, sin carpeta /public/ de por medio. Por eso las
 * redirecciones de aca son rutas simples ("caja.php"), igual que las usaba
 * el codigo viejo.
 */
abstract class BaseController
{
    /** Exige sesion y el permiso del modulo dado (misma matriz de siempre, lib/permisos.php). */
    protected function exigirPermiso(string $modulo): array
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/permisos.php';

        $usuario = \usuario_actual();
        if (!$usuario) {
            \redirigir('login.php');
        }
        if (!\puede($usuario, $modulo)) {
            \redirigir('home.php');
        }
        return $usuario;
    }

    /**
     * Verifica el token CSRF del POST actual. Si no es valido, deja un
     * mensaje flash y redirige (nunca deja seguir con un formulario viejo o
     * de otro origen). $volverA es la pantalla a la que volver (ej. "caja.php").
     */
    protected function verificarCsrf(string $volverA): void
    {
        if (!Session::csrfValido(Request::post('_token'))) {
            Session::flash('error', 'La página estuvo abierta demasiado tiempo. Inténtalo de nuevo.');
            \redirigir($volverA);
        }
    }
}
