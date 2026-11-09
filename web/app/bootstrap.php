<?php
declare(strict_types=1);

/**
 * Arranque del codigo nuevo (carpeta app/). Lo incluye public/index.php.
 *
 * Sin Composer a proposito: el hosting es compartido y se despliega
 * subiendo archivos a mano por cPanel (ver web/CLAUDE.md, "Que NO hacer
 * aqui"); hoy ninguna libreria lo justifica. En su lugar, un autoload
 * manual: "App\Core\Database" -> app/Core/Database.php. Si en el futuro
 * hace falta una libreria de verdad (ej. un generador de PDF), se evalua
 * agregar Composer recien ahi, de forma puntual y acordada.
 */

define('RAIZ_APP', __DIR__);
if (!defined('RUTA_PUBLICA')) {
    define('RUTA_PUBLICA', dirname(__DIR__) . '/public');   // scripts de consola
}

spl_autoload_register(function (string $clase): void {
    // "App\Core\Database" -> RAIZ_APP/../Core/Database.php (App\ == app/)
    if (!str_starts_with($clase, 'App\\')) {
        return;
    }
    $relativo = substr($clase, strlen('App\\'));
    $archivo = RAIZ_APP . '/' . str_replace('\\', '/', $relativo) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});
require_once RAIZ_APP . '/Core/helpers.php';

// Errores: registrados en un log propio, nunca mostrados en pantalla en
// produccion (hallazgo H4 de la auditoria — el codigo viejo depende de lo
// que traiga el hosting por defecto; este bootstrap lo fija explicito).
ini_set('log_errors', '1');
ini_set('error_log', dirname(RAIZ_APP) . '/logs/app.log');   // fuera de Git (ver .gitignore)
ini_set('display_errors', \App\Core\Config::esProduccion() ? '0' : '1');
error_reporting(E_ALL);

date_default_timezone_set('America/Santiago');

\App\Core\Session::iniciar();
