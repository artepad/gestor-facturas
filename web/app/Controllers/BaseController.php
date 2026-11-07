<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;

/**
 * Base de los controladores del POS nuevo: exige sesion+permiso y centraliza
 * el CSRF (hallazgo H3 de la auditoria — se agrega pantalla por pantalla, a
 * medida que cada una se migra, no de una sola vez sobre el sitio viejo).
 */
abstract class BaseController
{
    /**
     * Exige sesion y el permiso del modulo dado (misma matriz de siempre,
     * lib/permisos.php). Si falta algo, redirige y termina la ejecucion.
     *
     * TRANSICION (quitar en la Fase 9): el sitio viejo todavia vive en la
     * raiz de web/ y el nuevo en web/public/. Mientras los dos convivan, la
     * redireccion a login.php/home.php tiene que apuntar un nivel arriba de
     * donde corre esta pantalla nueva.
     */
    protected function exigirPermiso(string $modulo): array
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/permisos.php';

        $usuario = \usuario_actual();
        if (!$usuario) {
            \redirigir($this->baseSitioAntiguo() . '/login.php');
        }
        if (!\puede($usuario, $modulo)) {
            \redirigir($this->baseSitioAntiguo() . '/home.php');
        }
        return $usuario;
    }

    /** Ver docblock de exigirPermiso(): transicional, se retira en la Fase 9. */
    protected function baseSitioAntiguo(): string
    {
        $basePublic = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/index.php')), '/');
        return str_ends_with($basePublic, '/public') ? substr($basePublic, 0, -strlen('/public')) : '';
    }

    /**
     * Verifica el token CSRF del POST actual. Si no es valido, deja un
     * mensaje flash y redirige (nunca deja seguir con un formulario viejo o
     * de otro origen).
     */
    /**
     * $volverA es una ruta de la app NUEVA ("/caja/"), no del sitio viejo:
     * se le antepone Request::base() automaticamente (ver Core/Request).
     */
    protected function verificarCsrf(string $volverA): void
    {
        if (!Session::csrfValido(Request::post('_token'))) {
            Session::flash('error', 'La página estuvo abierta demasiado tiempo. Inténtalo de nuevo.');
            \redirigir(Request::base() . $volverA);
        }
    }
}
