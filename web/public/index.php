<?php
declare(strict_types=1);

/**
 * Punto de entrada de la arquitectura nueva (Fase 4 de la migracion).
 *
 * Fase 9 (despliegue): las 7 pantallas del POS ya se retiraron de aca — cada
 * una vive ahora en un archivo puente en la raiz de web/ (ej. web/caja.php)
 * que llama directo a su Controlador, con las mismas direcciones de
 * siempre. Esto ya NO es una vista previa de nada: queda solo como
 * diagnostico rapido de que el esqueleto (autoload, config, base de datos,
 * sesion) sigue funcionando, util si mas adelante se migra otra pantalla
 * del panel.
 */

define('RUTA_PUBLICA', __DIR__);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;

$router = new Router();

$router->get('/', function (): void {
    header('Content-Type: text/plain; charset=utf-8');
    $negocios = (int)Database::valor('SELECT COUNT(*) FROM negocios');
    echo "Esqueleto OK\n";
    echo "Base de datos OK (negocios registrados: $negocios)\n";
    echo "Sesion OK (token CSRF: " . substr(\App\Core\Session::tokenCsrf(), 0, 8) . "...)\n";
});

if (!$router->despachar(Request::metodo(), Request::ruta())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 - no encontrado";
}
