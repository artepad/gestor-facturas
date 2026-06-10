<?php
/**
 * Sirve el PDF de una factura, validando que el usuario tenga acceso al
 * negocio de esa factura. Los PDF estan fuera del acceso web directo
 * (almacen_pdf con .htaccess deny); este script es la unica via.
 */

require __DIR__ . '/lib/auth.php';

$usuario = exigir_permiso('facturas');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { http_response_code(400); exit('Falta id.'); }

$pdo = obtener_pdo();
$st = $pdo->prepare("SELECT negocio_id, ruta_pdf, numero_factura FROM facturas WHERE id = ?");
$st->execute([$id]);
$f = $st->fetch();

if (!$f || !$f['ruta_pdf']) { http_response_code(404); exit('Factura o PDF no encontrado.'); }
if (!puede_ver_negocio($usuario, (int)$f['negocio_id'])) {
    http_response_code(403); exit('Sin permiso para ver esta factura.');
}

$cfg = cargar_config();
$ruta = $cfg['carpeta_pdf'] . '/' . $f['ruta_pdf'];
// Proteccion contra path traversal
$real = realpath($ruta);
$base = realpath($cfg['carpeta_pdf']);
if ($real === false || $base === false || strpos($real, $base) !== 0) {
    http_response_code(404); exit('Archivo no encontrado.');
}

$nombre = 'factura_' . ($f['numero_factura'] ?: $id) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($real));
readfile($real);
