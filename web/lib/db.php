<?php
/**
 * Conexion a la base de datos via PDO.
 * Devuelve una instancia PDO configurada con prepared statements seguros.
 */

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
