<?php
/**
 * Conexion a la base de datos via PDO.
 * Devuelve una instancia PDO configurada con prepared statements seguros.
 */

// --- Fase 5 de la migracion (hallazgo H4 de la auditoria) -----------------
// db.php lo requiere practicamente toda pagina del sitio (directo o via
// lib/auth.php), asi que es el lugar mas chico para fijar esto UNA vez en
// vez de que cada pagina dependa de lo que traiga el hosting por defecto:
// antes, un error no controlado podia mostrar en pantalla detalles tecnicos
// (rutas del servidor, fragmentos de consultas) si cPanel tenia
// display_errors en On. Ahora: errores siempre al log, en pantalla solo en
// local/XAMPP.
if (!defined('MINIMARK_ERRORES_CONFIGURADOS')) {
    define('MINIMARK_ERRORES_CONFIGURADOS', true);
    $esProduccion = !in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost'], true)
        && !str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/app.log');
    ini_set('display_errors', $esProduccion ? '0' : '1');
    error_reporting(E_ALL);

    // Red de seguridad: si algo igual se cuela sin manejar (ej. una
    // excepcion de PDO que nadie atrapo), nunca se le muestra el detalle
    // tecnico al usuario — se registra en el log y se ve una pagina simple.
    set_exception_handler(function (Throwable $e) use ($esProduccion): void {
        error_log('Excepcion no capturada: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
        }
        if ($esProduccion) {
            echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
               . '<body style="font-family:sans-serif;text-align:center;padding:60px">'
               . '<h1>Algo salió mal</h1><p>El error ya quedó registrado. Intenta de nuevo '
               . 'en un momento.</p></body>';
        } else {
            echo '<pre>' . htmlspecialchars((string)$e) . '</pre>';   // detalle solo en local
        }
    });
}

function cargar_config(): array
{
    $ruta = __DIR__ . '/../config.php';
    if (!file_exists($ruta)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Falta config.php en el servidor.']);
        exit;
    }
    return require $ruta;
}

function obtener_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $cfg = cargar_config()['db'];
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['nombre']};charset={$cfg['charset']}";
    try {
        $pdo = new PDO($dsn, $cfg['usuario'], $cfg['clave'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No se pudo conectar a la base de datos.']);
        exit;
    }
    return $pdo;
}

/** Respuesta JSON uniforme y fin del script. */
function responder_json($datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}
