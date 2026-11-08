<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Models\PrecioFV;

/**
 * Frutas y Verduras: ahora vive dentro de la pantalla de Venta (botón junto
 * a Caja/Salir), no en su propio ítem de menú. Endpoints JSON (como
 * PosApiController) salvo imprimir(), que devuelve una página HTML para
 * imprimir.
 *
 * NO extiende BaseController: igual que PosApiController, cualquier falla de
 * sesión/permiso/CSRF responde JSON con su código HTTP (salvo imprimir(),
 * que si no hay sesión redirige a login, porque se abre en una pestaña
 * nueva con el navegador, no con fetch()).
 */
final class PreciosFvController
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB
    private const ANCHO_MAX = 1000;             // px, se reduce si la foto es más ancha

    public function index(array $parametros = []): void
    {
        require_once dirname(__DIR__, 2) . '/lib/db.php';   // responder_json()
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/permisos.php';
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // parsear_monto()

        $usuario = \usuario_actual();
        if (!$usuario || !\puede($usuario, 'ventas')) {
            \responder_json(['error' => 'Sin permiso.'], 403);
        }

        $accion = Request::get('accion') ?: Request::post('accion');

        try {
            if ($accion === 'listar' || $accion === '') {
                \responder_json(['ok' => true, 'productos' => $this->paraJson(PrecioFV::activos(Request::get('q')))]);
            }

            if (Request::metodo() !== 'POST') {
                \responder_json(['error' => 'Método no permitido.'], 405);
            }

            // CSRF: las escrituras llegan como multipart/form-data (traen la
            // foto), así que el token viaja como campo normal del formulario,
            // no en un cuerpo JSON (a diferencia de pos_api.php).
            if (!Session::csrfValido(Request::post('_token'))) {
                \responder_json(['error' => 'La sesión del navegador se venció. Recarga la página.'], 403);
            }

            if ($accion === 'guardar') {
                $id = Request::entero('id');
                $nombre = Request::post('nombre');
                $precio = \parsear_monto(Request::post('precio'));
                $unidad = Request::post('unidad') ?: 'kilo';
                $observacion = Request::post('observacion');
                $activo = Request::post('activo') === '1' ? 1 : 0;
                $quitarImagen = Request::post('quitar_imagen') === '1';

                if ($nombre === '') throw new \RuntimeException('Escribe el nombre del producto.');
                if ($precio === null || $precio <= 0) throw new \RuntimeException('Escribe un precio válido mayor a 0.');

                $existente = $id ? PrecioFV::porId($id) : null;
                if ($id && !$existente) throw new \RuntimeException('Ese producto ya no existe.');

                $imagen = $existente['imagen'] ?? null;
                $imagenVieja = $imagen;
                if ($quitarImagen) {
                    $imagen = null;
                } elseif (isset($_FILES['imagen']) && $_FILES['imagen']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $imagen = $this->procesarImagen($_FILES['imagen']);
                }

                $datos = [
                    'nombre' => $nombre, 'precio' => $precio, 'unidad' => $unidad,
                    'imagen' => $imagen, 'observacion' => $observacion !== '' ? $observacion : null,
                    'activo' => $activo,
                ];

                if ($id) {
                    PrecioFV::actualizar($id, $datos, (int)$usuario['id']);
                } else {
                    $id = PrecioFV::crear($datos, (int)$usuario['id']);
                }

                // Si se reemplazó o se quitó la foto, borra la anterior del disco
                // (nunca antes de guardar en la BD: si algo falla arriba, no se
                // pierde el archivo bueno).
                if ($imagenVieja && $imagenVieja !== $imagen) {
                    $this->borrarArchivoImagen($imagenVieja);
                }

                \responder_json(['ok' => true, 'producto' => $this->unoParaJson(PrecioFV::porId($id))]);
            }

            if ($accion === 'eliminar') {
                $id = Request::entero('id');
                $prod = PrecioFV::porId($id);
                if (!$prod) throw new \RuntimeException('Ese producto ya no existe.');
                PrecioFV::eliminar($id);
                if ($prod['imagen']) $this->borrarArchivoImagen($prod['imagen']);
                \responder_json(['ok' => true]);
            }

            \responder_json(['error' => 'Acción desconocida.'], 400);
        } catch (\RuntimeException $e) {
            \responder_json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('precios_fv: ' . $e->getMessage());
            \responder_json(['error' => 'Ocurrió un error al procesar la operación.'], 500);
        }
    }

    /** Hoja imprimible con la lista completa (igual patrón que *_imprimir.php). */
    public function imprimir(array $parametros = []): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/permisos.php';
        require_once dirname(__DIR__, 2) . '/lib/ui.php';

        $usuario = \usuario_actual();
        if (!$usuario) \redirigir('login.php');
        if (!\puede($usuario, 'ventas')) \redirigir('home.php');

        $productos = PrecioFV::activos();
        \App\Core\View::render('precios_fv/imprimir', compact('productos'));
    }

    private function paraJson(array $filas): array
    {
        return array_map([$this, 'unoParaJson'], $filas);
    }

    private function unoParaJson(?array $p): ?array
    {
        if (!$p) return null;
        return [
            'id' => (int)$p['id'],
            'nombre' => $p['nombre'],
            'precio' => (float)$p['precio'],
            'unidad' => $p['unidad'],
            'imagen' => $p['imagen'] ?: null,
            'observacion' => $p['observacion'],
            'activo' => (bool)$p['activo'],
            'actualizado_en' => $p['actualizado_en'],
            'actualizado_por' => $p['actualizado_por_nombre'] ?? null,
        ];
    }

    /**
     * Valida y procesa la foto subida: nunca confía en la extensión ni en el
     * Content-Type que manda el navegador. Vuelve a generar la imagen con GD
     * (lo que haya escondido el archivo original no sobrevive), la reduce si
     * es muy ancha, y la guarda con un nombre aleatorio en WebP. Devuelve la
     * ruta relativa (para guardar en la BD) o lanza una excepción si el
     * archivo no es una imagen válida.
     */
    private function procesarImagen(array $archivo): string
    {
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('La foto no se subió correctamente (¿muy grande?). Intenta de nuevo.');
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            throw new \RuntimeException('Archivo inválido.');
        }
        if ($archivo['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('La foto es demasiado grande (máximo 5 MB).');
        }

        $info = @getimagesize($archivo['tmp_name']);
        if ($info === false) {
            throw new \RuntimeException('El archivo no es una imagen válida.');
        }

        $origen = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($archivo['tmp_name']),
            IMAGETYPE_PNG  => @imagecreatefrompng($archivo['tmp_name']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($archivo['tmp_name']),
            default        => null,
        };
        if (!$origen) {
            throw new \RuntimeException('Usa una foto en formato JPG, PNG o WebP.');
        }

        $anchoOrig = imagesx($origen);
        $altoOrig = imagesy($origen);
        if ($anchoOrig > self::ANCHO_MAX) {
            $alto = (int)round($altoOrig * (self::ANCHO_MAX / $anchoOrig));
            $destino = imagecreatetruecolor(self::ANCHO_MAX, $alto);
            // Fondo blanco antes de copiar: si el PNG original tenía transparencia,
            // no queda negra al convertir a WebP sin canal alfa.
            imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
            imagecopyresampled($destino, $origen, 0, 0, 0, 0, self::ANCHO_MAX, $alto, $anchoOrig, $altoOrig);
            imagedestroy($origen);
        } else {
            $destino = $origen;
        }

        $carpeta = dirname(__DIR__, 2) . '/uploads/precios_fv';
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0755, true);
        }
        $nombre = bin2hex(random_bytes(16)) . '.webp';
        $ok = imagewebp($destino, $carpeta . '/' . $nombre, 82);
        imagedestroy($destino);
        if (!$ok) {
            throw new \RuntimeException('No se pudo procesar la foto. Intenta con otra.');
        }

        return 'uploads/precios_fv/' . $nombre;
    }

    private function borrarArchivoImagen(string $rutaRelativa): void
    {
        // Nombres de archivo siempre con esta forma (los genera procesarImagen());
        // una ruta que no calce se ignora, nunca se interpreta como ruta del SO.
        if (!preg_match('#^uploads/precios_fv/[a-f0-9]{32}\.webp$#', $rutaRelativa)) {
            return;
        }
        $ruta = dirname(__DIR__, 2) . '/' . $rutaRelativa;
        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }
}
