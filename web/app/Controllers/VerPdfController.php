<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Models\Factura;

/**
 * Sirve el PDF de una factura, validando que el usuario tenga acceso al
 * negocio de esa factura. Los PDF están fuera del acceso web directo
 * (almacen_pdf con .htaccess deny); este Controlador es la única vía.
 *   ver_pdf.php?id=N -> index()
 *
 * Responde el binario directo (sin Vista), igual que PosApiController
 * responde JSON directo — no todo controlador termina en View::render().
 */
final class VerPdfController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('facturas');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // puede_ver_negocio()

        $id = Request::entero('id');
        if (!$id) {
            http_response_code(400);
            exit('Falta id.');
        }

        $f = Factura::paraPdf($id);
        if (!$f || !$f['ruta_pdf']) {
            http_response_code(404);
            exit('Factura o PDF no encontrado.');
        }
        if (!\puede_ver_negocio($usuario, (int)$f['negocio_id'])) {
            http_response_code(403);
            exit('Sin permiso para ver esta factura.');
        }

        $ruta = Config::get('carpeta_pdf') . '/' . $f['ruta_pdf'];
        // Protección contra path traversal: el archivo real debe quedar dentro de carpeta_pdf.
        $real = realpath($ruta);
        $base = realpath(Config::get('carpeta_pdf'));
        if ($real === false || $base === false || strpos($real, $base) !== 0) {
            http_response_code(404);
            exit('Archivo no encontrado.');
        }

        $nombre = 'factura_' . ($f['numero_factura'] ?: $id) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
    }
}
