<?php
declare(strict_types=1);

/**
 * Punto de entrada de la arquitectura NUEVA (Fase 4 de la migracion).
 *
 * Hoy esto NO es lo que sirve admin.minimark.cl — eso lo sigue siendo la
 * raiz de web/ (login.php, pos.php, etc.), sin ningun cambio. Este archivo
 * convive al lado, para ir probando el esqueleto antes de mover trafico
 * real. El cambio de "que carpeta ve el navegador" es la Fase 9, y se avisa
 * y se aprueba aparte.
 *
 * Unica ruta por ahora: "/" -> confirma que el autoload, la config, la base
 * de datos y la sesion del esqueleto nuevo funcionan. Cada pantalla real se
 * agrega recien cuando le toca su corte (Fase 6).
 *
 * La ruta se calcula relativa a donde vive ESTE archivo (SCRIPT_NAME), no al
 * dominio completo: asi funciona igual en local (XAMPP sirve esto en
 * /minimark/public/) y en produccion (Fase 9: el hosting apuntara el
 * dominio directo a esta carpeta, sin prefijo). Ningun cambio de ruta real
 * cuando eso pase.
 */

define('RUTA_PUBLICA', __DIR__);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\CajaController;
use App\Controllers\ComprasController;
use App\Controllers\InventarioController;
use App\Controllers\PosApiController;
use App\Controllers\PosController;
use App\Controllers\PromocionesController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
Request::fijarBase($base);   // para que las vistas armen sus propios links/forms

$ruta = Request::ruta();
if ($base !== '' && str_starts_with($ruta, $base)) {
    $ruta = substr($ruta, strlen($base));
}
if ($ruta === '' || $ruta === '/index.php') {
    $ruta = '/';
}

$router = new Router();

$router->get('/', function (): void {
    header('Content-Type: text/plain; charset=utf-8');
    $negocios = (int)Database::valor('SELECT COUNT(*) FROM negocios');
    echo "Esqueleto nuevo OK\n";
    echo "Base de datos OK (negocios registrados: $negocios)\n";
    echo "Sesion OK (token CSRF: " . substr(\App\Core\Session::tokenCsrf(), 0, 8) . "...)\n";
});

// --- Corte 1 de la Fase 6: Caja (ver web/docs/01-plan-migracion.md) ---
$router->get('/caja/', [CajaController::class, 'index']);
$router->post('/caja/abrir', [CajaController::class, 'abrir']);
$router->post('/caja/movimiento', [CajaController::class, 'movimiento']);
$router->post('/caja/cerrar', [CajaController::class, 'cerrar']);

// --- Corte 2a de la Fase 6: pantalla de venta (pos_api.php sigue sin migrar) ---
$router->get('/pos/', [PosController::class, 'index']);

// --- Corte 2b: motor de cobro. La vista NO le habla todavia a esto (sigue
// llamando a pos_api.php tal cual) hasta que se verifiquen las 13 acciones. ---
$router->get('/pos/api', [PosApiController::class, 'index']);
$router->post('/pos/api', [PosApiController::class, 'index']);

// --- Corte de la Fase 6: inventario/compras/promociones (menor riesgo,
// inventario nace apagado) ---
$router->get('/inventario/', [InventarioController::class, 'index']);
$router->post('/inventario/', [InventarioController::class, 'index']);
$router->get('/compras/', [ComprasController::class, 'index']);
$router->post('/compras/', [ComprasController::class, 'index']);
$router->get('/promociones/', [PromocionesController::class, 'index']);
$router->post('/promociones/', [PromocionesController::class, 'index']);

if (!$router->despachar(Request::metodo(), $ruta)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 - no encontrado (esqueleto nuevo, sin esa ruta todavia)";
}
