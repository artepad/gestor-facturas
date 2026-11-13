<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Models\Factura;

/**
 * "Nueva factura": subida directa en la web, procesada con IA (ver
 * web/lib/factura_ia.php). Endpoint JSON (mismo criterio que
 * PreciosFvController) — no extiende BaseController: cualquier falla de
 * sesión/permiso/CSRF responde JSON con su código HTTP.
 *
 * Flujo en dos pasos, llamado desde app/views/facturas/nueva.php:
 *   1. accion=analizar — sube el PDF, lo guarda en una carpeta temporal y le
 *      pide a la IA los datos de cabecera. No toca la tabla `facturas`.
 *   2. accion=guardar  — recibe los datos ya revisados/corregidos por la
 *      vendedora + el token del PDF temporal, y ahí sí inserta la factura.
 *
 * El PDF nunca se confía ciega mente entre un paso y el otro: `guardar`
 * vuelve a validar que el archivo temporal siga siendo un PDF real antes de
 * moverlo a su carpeta final (mismo criterio que api/facturas.php).
 */
final class FacturaIaController
{
    /** Mismo patrón que uuid_local: el token del archivo temporal tiene que tener esta forma. */
    private const RE_TOKEN = '/^[a-f0-9]{32}$/';

    public function index(array $parametros = []): void
    {
        require_once dirname(__DIR__, 2) . '/lib/db.php';
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/permisos.php';
        require_once dirname(__DIR__, 2) . '/lib/factura_ia.php';

        $usuario = \usuario_actual();
        if (!$usuario || !\puede($usuario, 'facturas')) {
            \responder_json(['error' => 'Sin permiso.'], 403);
        }
        if (Request::metodo() !== 'POST') {
            \responder_json(['error' => 'Método no permitido.'], 405);
        }
        if (!Session::csrfValido(Request::post('_token'))) {
            \responder_json(['error' => 'La sesión del navegador se venció. Recarga la página.'], 403);
        }

        $negocioId = Request::entero('negocio_id');
        if (!$negocioId || !\puede_ver_negocio($usuario, $negocioId)) {
            \responder_json(['error' => 'Elige un negocio válido.'], 400);
        }

        $this->limpiarTemporalesViejos();

        $accion = Request::post('accion');
        try {
            if ($accion === 'analizar') {
                $this->analizar($negocioId);
            } elseif ($accion === 'guardar') {
                $this->guardar($usuario, $negocioId);
            } else {
                \responder_json(['error' => 'Acción desconocida.'], 400);
            }
        } catch (\RuntimeException $e) {
            \responder_json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('factura_ia: ' . $e->getMessage());
            \responder_json(['error' => 'Ocurrió un error al procesar la factura. Intenta de nuevo.'], 500);
        }
    }

    private function analizar(int $negocioId): void
    {
        $archivo = $_FILES['pdf'] ?? null;
        if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('No se pudo subir el archivo (¿muy grande?). Intenta de nuevo.');
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            throw new \RuntimeException('Archivo inválido.');
        }
        if ($archivo['size'] <= 0 || $archivo['size'] > \FACTURA_IA_MAX_BYTES) {
            throw new \RuntimeException('El PDF es demasiado grande (máximo 15 MB).');
        }
        if (!str_starts_with((string)file_get_contents($archivo['tmp_name'], false, null, 0, 5), '%PDF-')) {
            throw new \RuntimeException('El archivo no es un PDF válido.');
        }

        $carpetaTmp = $this->carpetaTemporal();
        $token = bin2hex(random_bytes(16));
        $destino = $carpetaTmp . '/' . $token . '.pdf';
        if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
            throw new \RuntimeException('No se pudo guardar el archivo subido.');
        }

        $cfgIa = \cargar_config()['anthropic'] ?? null;
        if (!$cfgIa || !($cfgIa['api_key'] ?? '')) {
            @unlink($destino);
            throw new \RuntimeException('Falta configurar la clave de la IA en el servidor.');
        }

        try {
            $datos = \procesar_factura_con_ia($cfgIa, $destino);
        } catch (\Throwable $e) {
            @unlink($destino);
            throw new \RuntimeException('No se pudo leer la factura con la IA: ' . $e->getMessage());
        }

        $duplicada = null;
        if ($datos['es_factura']) {
            $duplicada = Factura::buscarDuplicada($negocioId, $datos['numero_factura'], $datos['rut_emisor']);
        }

        \responder_json([
            'ok' => true,
            'token' => $token,
            'datos' => $datos,
            'duplicada' => $duplicada,
        ]);
    }

    private function guardar(array $usuario, int $negocioId): void
    {
        $token = Request::post('token');
        if (!preg_match(self::RE_TOKEN, $token)) {
            throw new \RuntimeException('La sesión de subida venció. Vuelve a adjuntar el PDF.');
        }
        $rutaTmp = $this->carpetaTemporal() . '/' . $token . '.pdf';
        if (!is_file($rutaTmp) || !str_starts_with((string)file_get_contents($rutaTmp, false, null, 0, 5), '%PDF-')) {
            throw new \RuntimeException('La sesión de subida venció. Vuelve a adjuntar el PDF.');
        }

        $proveedor = Request::post('proveedor');
        if ($proveedor === '') {
            throw new \RuntimeException('Escribe el proveedor.');
        }
        $fecha = Request::post('fecha'); // viene como YYYY-MM-DD desde <input type="date">
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new \RuntimeException('Elige una fecha válida.');
        }
        require_once dirname(__DIR__, 2) . '/lib/factura_ia.php';
        if (!\fecha_factura_es_razonable($fecha)) {
            throw new \RuntimeException('La fecha no puede ser futura.');
        }
        $total = \parsear_monto_clp(Request::post('total'));
        if ($total === null || $total <= 0) {
            throw new \RuntimeException('Escribe un total válido mayor a 0.');
        }
        $numeroFactura = Request::post('numero_factura') ?: null;
        $rutEmisor = Request::post('rut_emisor') ?: null;
        $razonSocial = Request::post('razon_social') ?: null;
        $moneda = Request::post('moneda') ?: 'CLP';
        $notas = Request::post('notas') ?: null;

        $confirmarDuplicada = Request::post('confirmar_duplicada') === '1';
        if (!$confirmarDuplicada) {
            $duplicada = Factura::buscarDuplicada($negocioId, $numeroFactura, $rutEmisor);
            if ($duplicada) {
                \responder_json(['ok' => false, 'duplicada' => $duplicada]);
            }
        }

        $cfg = \cargar_config();
        $slug = \App\Core\Database::valor('SELECT slug FROM negocios WHERE id = ?', [$negocioId]);
        if (!$slug) {
            throw new \RuntimeException('Negocio no válido.');
        }

        $anio = substr($fecha, 0, 4);
        $mes = substr($fecha, 5, 2);
        $carpetaFinal = $cfg['carpeta_pdf'] . "/$slug/$anio/$mes";
        if (!is_dir($carpetaFinal)) {
            mkdir($carpetaFinal, 0755, true);
        }
        $uuidArchivo = bin2hex(random_bytes(16));
        $rutaRelativa = "$slug/$anio/$mes/$uuidArchivo.pdf";
        $destinoFinal = $cfg['carpeta_pdf'] . '/' . $rutaRelativa;
        if (!rename($rutaTmp, $destinoFinal)) {
            throw new \RuntimeException('No se pudo guardar el PDF en su carpeta final.');
        }

        $facturaId = Factura::crearDesdeWeb([
            'proveedor' => $proveedor,
            'razon_social' => $razonSocial,
            'rut_emisor' => $rutEmisor,
            'numero_factura' => $numeroFactura,
            'fecha' => $fecha,
            'total' => $total,
            'moneda' => $moneda,
            'confianza' => Request::post('confianza') !== '' ? (float)Request::post('confianza') : null,
            'notas' => $notas,
            'ruta_pdf' => $rutaRelativa,
        ], $negocioId, (int)$usuario['id']);

        \responder_json(['ok' => true, 'factura_id' => $facturaId]);
    }

    private function carpetaTemporal(): string
    {
        $cfg = \cargar_config();
        $carpeta = $cfg['carpeta_pdf'] . '/_tmp';
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0755, true);
        }
        return $carpeta;
    }

    /** Borra PDFs temporales de más de 24h (subidas que nunca se confirmaron). */
    private function limpiarTemporalesViejos(): void
    {
        $carpeta = $this->carpetaTemporal();
        $limite = time() - 24 * 3600;
        foreach (glob($carpeta . '/*.pdf') ?: [] as $archivo) {
            if (($mtime = @filemtime($archivo)) !== false && $mtime < $limite) {
                @unlink($archivo);
            }
        }
    }
}