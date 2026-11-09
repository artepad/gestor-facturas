<?php
/**
 * Respaldo automatico de documentos/facturas/ (hallazgo H7 de la auditoria:
 * la base de datos ya tiene respaldo diario de cPanel, pero los PDF
 * escaneados no tenian ninguno — si se pierde el servidor, se pierden las
 * facturas).
 *
 * Comprime documentos/facturas/ completo (carpeta_pdf en config.php) en un
 * .zip con fecha, guardado en documentos/respaldos/ (bloqueados los dos por
 * el .htaccess de documentos/, Fase 21 de la migracion). Conserva los
 * ultimos 14 (mismo criterio que usa cPanel para la base de datos) y borra
 * los mas viejos.
 *
 * Se ejecuta de dos formas, mismo patron que procesar_cortes.php:
 *   - Cron de cPanel (una vez al dia):  php /home/.../scripts/respaldo_pdfs.php
 *   - A mano en el navegador:           respaldo_pdfs.php?clave=SETUP_KEY
 */

require __DIR__ . '/../lib/db.php';

$config = cargar_config();
$esCli = (PHP_SAPI === 'cli');

if (!$esCli) {
    $clave = $_GET['clave'] ?? '';
    if (!hash_equals((string)($config['setup_key'] ?? ''), $clave) || $clave === '') {
        http_response_code(403);
        exit('Acceso denegado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$carpetaPdf = rtrim((string)$config['carpeta_pdf'], '/');
$carpetaRespaldos = dirname(__DIR__) . '/documentos/respaldos';
const RETENCION_DIAS = 14;

function log_linea(string $msg): void
{
    echo $msg . "\n";
}

if (!is_dir($carpetaPdf)) {
    log_linea("No existe $carpetaPdf — nada que respaldar.");
    exit;
}

if (!is_dir($carpetaRespaldos)) {
    mkdir($carpetaRespaldos, 0755, true);
    // Ya queda bloqueada por el .htaccess de documentos/ (su carpeta padre);
    // esta es una segunda capa por si alguna vez esta carpeta se usa suelta.
    file_put_contents($carpetaRespaldos . '/.htaccess', "Require all denied\n");
}

$nombreZip = $carpetaRespaldos . '/pdfs_' . date('Y-m-d_His') . '.zip';
$zip = new ZipArchive();
if ($zip->open($nombreZip, ZipArchive::CREATE) !== true) {
    log_linea("ERROR: no se pudo crear $nombreZip");
    exit(1);
}

$total = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($carpetaPdf, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $archivo) {
    if ($archivo->isFile()) {
        $rutaRelativa = substr($archivo->getPathname(), strlen($carpetaPdf) + 1);
        $zip->addFile($archivo->getPathname(), $rutaRelativa);
        $total++;
    }
}
$zip->close();
log_linea("Respaldo creado: " . basename($nombreZip) . " ($total archivos, "
    . round(filesize($nombreZip) / 1024 / 1024, 1) . " MB)");

// --- Limpieza: conserva solo los ultimos 14 respaldos ---
$viejos = glob($carpetaRespaldos . '/pdfs_*.zip');
sort($viejos);   // el nombre trae la fecha, orden alfabetico = orden cronologico
$sobran = count($viejos) - RETENCION_DIAS;
for ($i = 0; $i < $sobran; $i++) {
    unlink($viejos[$i]);
    log_linea("Respaldo viejo borrado: " . basename($viejos[$i]));
}

log_linea('Listo.');
