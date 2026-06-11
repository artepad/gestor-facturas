<?php
/**
 * Worker del módulo Ingresos: lee la casilla de cortes por IMAP, guarda los
 * correos nuevos en correos_corte y procesa todos los pendientes/erróneos
 * con el parser de Eleventa.
 *
 * Se ejecuta de dos formas:
 *   - Cron de cPanel (cada 10 min):  php /home/.../procesar_cortes.php
 *   - A mano en el navegador:        procesar_cortes.php?clave=SETUP_KEY
 *
 * Si el bloque 'cortes_imap' no está configurado en config.php, salta la
 * lectura IMAP y solo reprocesa lo ya almacenado (útil en local).
 */

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/eleventa.php';

$config = cargar_config();
$esCli = (PHP_SAPI === 'cli');

// --- Acceso: solo CLI (cron) o con la setup_key ---
if (!$esCli) {
    $clave = $_GET['clave'] ?? '';
    if (!hash_equals((string)($config['setup_key'] ?? ''), $clave) || $clave === '') {
        http_response_code(403);
        exit('Acceso denegado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$pdo = obtener_pdo();
echo "== Procesamiento de cortes de Eleventa ==\n\n";

// --- 1) Bajar correos nuevos por IMAP (si está configurado) ---
$imap = $config['cortes_imap'] ?? null;
if ($imap && !empty($imap['usuario'])) {
    if (!function_exists('imap_open')) {
        echo "[IMAP] La extensión imap de PHP no está disponible en este servidor.\n";
    } else {
        $mbox = '{' . $imap['host'] . ':' . ($imap['puerto'] ?? 993) . '/imap/ssl}' . ($imap['carpeta'] ?? 'INBOX');
        // n_retries = 1: un solo intento de login. Titan bloquea la casilla tras
        // varios fallos seguidos, así que no insistimos en la misma corrida.
        $con = @imap_open($mbox, $imap['usuario'], $imap['clave'], 0, 1);
        if (!$con) {
            echo "[IMAP] No se pudo conectar a la casilla: " . (imap_last_error() ?: 'error desconocido') . "\n";
        } else {
            $nuevos = imap_search($con, 'UNSEEN') ?: [];
            echo '[IMAP] Correos nuevos: ' . count($nuevos) . "\n";
            foreach ($nuevos as $num) {
                $cab = imap_headerinfo($con, $num);
                $msgId = trim($cab->message_id ?? '');
                if ($msgId === '') {
                    $msgId = 'sin-id-' . sha1(($cab->subject ?? '') . ($cab->udate ?? ''));
                }
                $asunto = isset($cab->subject)
                    ? trim(iconv_mime_decode($cab->subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')) : '';
                $remit = isset($cab->from[0])
                    ? ($cab->from[0]->mailbox ?? '') . '@' . ($cab->from[0]->host ?? '') : '';
                // Destinatario: el negocio se identifica por la etiqueta de la
                // dirección (cortes+slug@ / corte-slug@). Reunimos todos los
                // posibles destinatarios (To, Cc y los encabezados de entrega) y
                // nos quedamos con el que trae la etiqueta. OJO: Titan entrega a
                // la casilla base y pone Delivered-To SIN la etiqueta, así que no
                // hay que quedarse con ese si hay uno mejor.
                $crudo = imap_fetchheader($con, $num);
                $candidatos = [];
                foreach (['to', 'cc'] as $campo) {
                    foreach (($cab->$campo ?? []) as $a) {
                        $candidatos[] = ($a->mailbox ?? '') . '@' . ($a->host ?? '');
                    }
                }
                if (preg_match_all('/^(?:Delivered-To|X-Delivered-To|X-Original-To|Envelope-To):\s*<?([^>\s]+)>?/mi', $crudo, $mm)) {
                    foreach ($mm[1] as $d) $candidatos[] = trim($d);
                }
                $dest = $candidatos[0] ?? '';
                foreach ($candidatos as $cand) {
                    if (preg_match('/cortes?[-.+][a-z0-9\-]+@/i', $cand)) { $dest = $cand; break; }
                }
                $recibido = isset($cab->udate) ? date('Y-m-d H:i:s', $cab->udate) : null;

                $cuerpo = imap_corte_extraer_cuerpo($con, $num);
                if ($cuerpo === '') {
                    echo "  ! mensaje $num sin cuerpo legible, se omite\n";
                    imap_setflag_full($con, (string)$num, '\\Seen');
                    continue;
                }

                $negocioId = negocio_por_destinatario($pdo, $dest);
                try {
                    $pdo->prepare(
                        "INSERT INTO correos_corte
                           (negocio_id, message_id, remitente, destinatario, asunto, recibido_en, cuerpo, origen)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'imap')"
                    )->execute([$negocioId, substr($msgId, 0, 255), substr($remit, 0, 255),
                                substr($dest, 0, 255), substr($asunto, 0, 255), $recibido, $cuerpo]);
                    echo "  + guardado: $asunto\n";
                } catch (Throwable $e) {
                    if (str_contains($e->getMessage(), 'Duplicate')) {
                        echo "  - ya estaba: $asunto\n";
                    } else {
                        echo "  ! error al guardar '$asunto': " . $e->getMessage() . "\n";
                        continue;   // no marcar visto: se reintenta en la próxima corrida
                    }
                }
                imap_setflag_full($con, (string)$num, '\\Seen');
            }
            imap_close($con);
        }
    }
} else {
    echo "[IMAP] Sin configurar (bloque 'cortes_imap' en config.php). Solo se reprocesa lo almacenado.\n";
}

// --- 2) Procesar todo lo pendiente o con error ---
$filas = $pdo->query(
    "SELECT * FROM correos_corte WHERE estado IN ('pendiente','error') ORDER BY id"
)->fetchAll();
echo "\n[Parser] Correos por procesar: " . count($filas) . "\n";
$ok = 0; $mal = 0;
foreach ($filas as $correo) {
    $corteId = procesar_correo_corte($pdo, $correo);
    if ($corteId) {
        $ok++;
        echo "  + corte #$corteId desde correo #{$correo['id']}\n";
    } else {
        $mal++;
        $st = $pdo->prepare("SELECT error FROM correos_corte WHERE id = ?");
        $st->execute([$correo['id']]);
        echo "  ! correo #{$correo['id']}: " . $st->fetchColumn() . "\n";
    }
}
echo "\nListo. Procesados: $ok, con error: $mal.\n";

/**
 * Extrae el cuerpo de un mensaje IMAP prefiriendo la parte HTML (es la que
 * manda Eleventa). Decodifica base64/quoted-printable y convierte a UTF-8.
 */
function imap_corte_extraer_cuerpo($con, int $num): string
{
    $est = imap_fetchstructure($con, $num);
    if (!$est) return '';

    // Mensaje simple (sin partes)
    if (empty($est->parts)) {
        return imap_corte_decodificar(imap_body($con, $num), $est);
    }

    // Buscamos recursivamente la parte text/html; si no hay, text/plain.
    $html = imap_corte_buscar_parte($con, $num, $est->parts, '', 'HTML');
    if ($html !== null) return $html;
    return imap_corte_buscar_parte($con, $num, $est->parts, '', 'PLAIN') ?? '';
}

function imap_corte_buscar_parte($con, int $num, array $partes, string $prefijo, string $subtipo): ?string
{
    foreach ($partes as $i => $p) {
        $seccion = $prefijo === '' ? (string)($i + 1) : $prefijo . '.' . ($i + 1);
        if (!empty($p->parts)) {
            $r = imap_corte_buscar_parte($con, $num, $p->parts, $seccion, $subtipo);
            if ($r !== null) return $r;
        } elseif (strtoupper($p->subtype ?? '') === $subtipo) {
            return imap_corte_decodificar(imap_fetchbody($con, $num, $seccion), $p);
        }
    }
    return null;
}

function imap_corte_decodificar(string $cuerpo, $parte): string
{
    $enc = $parte->encoding ?? 0;
    if ($enc == 3) $cuerpo = base64_decode($cuerpo);          // BASE64
    elseif ($enc == 4) $cuerpo = quoted_printable_decode($cuerpo); // QUOTED-PRINTABLE

    // Charset declarado -> UTF-8
    $charset = 'UTF-8';
    foreach (($parte->parameters ?? []) as $par) {
        if (strtolower($par->attribute ?? '') === 'charset') $charset = strtoupper($par->value);
    }
    if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $conv = @iconv($charset, 'UTF-8//IGNORE', $cuerpo);
        if ($conv !== false) $cuerpo = $conv;
    }
    return $cuerpo;
}
