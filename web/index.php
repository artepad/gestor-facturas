<?php
declare(strict_types=1);

/**
 * Punto de entrada único de todo el panel (Fase 19 de la migración, ver
 * web/docs/bitacora-migracion.md). El .htaccess manda acá cualquier
 * dirección que no sea un archivo real que ya exista (css, imágenes,
 * api/facturas.php, migrar.php, procesar_cortes.php se siguen sirviendo
 * directo, sin pasar por acá). Antes cada pantalla tenía su propio archivo
 * "puente" en esta carpeta; ahora un solo Router (app/routes.php) decide a
 * qué Controlador mandar cada dirección — las direcciones mismas NO
 * cambiaron, ni una URL se mueve.
 */

require __DIR__ . '/app/bootstrap.php';

use App\Core\Request;
use App\Core\Router;

$router = new Router();
require RAIZ_APP . '/routes.php';

$metodo = Request::metodo();
if ($metodo === 'HEAD') {
    $metodo = 'GET'; // un archivo .php viejo respondía igual a HEAD que a GET
}

if (!$router->despachar($metodo, Request::ruta())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 - página no encontrada";
}
