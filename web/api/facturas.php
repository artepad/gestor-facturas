<?php
/**
 * Endpoint de recepcion de facturas desde el cliente Python.
 *
 *   POST /api/facturas.php
 *   Header:  Authorization: Bearer <token-de-la-maquina>
 *   Body (multipart/form-data):
 *     - datos: JSON con los campos de la factura + detalle (string)
 *     - pdf:   archivo PDF (opcional)
 *
 * Idempotente: si llega un uuid_local ya existente, ACTUALIZA en vez de
 * duplicar. Asi los reintentos del cliente (por corte de internet) no
 * generan facturas repetidas.
 */

require __DIR__ . '/../lib/db.php';

// --- Solo POST ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder_json(['error' => 'Metodo no permitido. Usa POST.'], 405);
}

// --- Autenticar la maquina por su token ---
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (stripos($auth, 'Bearer ') !== 0) {
    responder_json(['error' => 'Falta el token (Authorization: Bearer ...).'], 401);
}
$token = trim(substr($auth, 7));
$tokenHash = hash('sha256', $token);

$pdo = obtener_pdo();
// Se trae el slug del negocio junto con todo lo demas: lo usa la carpeta
// del PDF mas abajo (Fase 21 de la migracion: documentos/facturas/{slug}/{anio}/{mes}/).
$st = $pdo->prepare(
    "SELECT m.id AS maquina_id, m.negocio_id, n.slug
       FROM maquinas m JOIN negocios n ON n.id = m.negocio_id
      WHERE m.token_hash = ?"
);
$st->execute([$tokenHash]);
$maquina = $st->fetch();
if (!$maquina) {
    responder_json(['error' => 'Token invalido.'], 401);
}
$maquinaId = (int)$maquina['maquina_id'];
$negocioId = (int)$maquina['negocio_id'];
$slugNegocio = $maquina['slug'];

// --- Leer y validar el JSON de datos ---
$rawDatos = $_POST['datos'] ?? '';
$datos = json_decode($rawDatos, true);
if (!is_array($datos)) {
    responder_json(['error' => 'El campo datos debe ser JSON valido.'], 400);
}

$uuid = trim($datos['uuid_local'] ?? '');
if ($uuid === '') {
    responder_json(['error' => 'Falta uuid_local.'], 400);
}
// H2 de la auditoria: uuid se usa mas abajo como nombre del PDF
// ($uuid . '.pdf'). Sin validar el formato, una maquina con un token valido
// podria mandar algo como "../../otra-carpeta/x" e intentar escribir fuera
// de carpeta_pdf. El cliente Python siempre manda un UUID real
// (str(uuid.uuid4()), ver src/db.py); si no tiene esa forma, se rechaza
// antes de tocar el disco.
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    responder_json(['error' => 'uuid_local con formato invalido.'], 400);
}

// --- Eliminación (soft-delete): marca eliminada_en, no borra fisicamente ---
if (!empty($datos['eliminar'])) {
    try {
        $st = $pdo->prepare(
            "UPDATE facturas SET eliminada_en = NOW()
             WHERE uuid_local = ? AND negocio_id = ?"
        );
        $st->execute([$uuid, $negocioId]);
        $pdo->prepare("UPDATE maquinas SET ultima_sync = NOW() WHERE id = ?")
            ->execute([$maquinaId]);
        $pdo->prepare(
            "INSERT INTO sync_log (maquina_id, accion, factura_uuid, exito) VALUES (?, 'eliminar', ?, 1)"
        )->execute([$maquinaId, $uuid]);
        responder_json(['ok' => true, 'accion' => 'eliminar']);
    } catch (Throwable $e) {
        responder_json(['error' => 'Error al eliminar.'], 500);
    }
}

// --- Factura existente (upsert por uuid_local) ---
// Se mira ANTES de guardar el PDF porque, si la factura ya existia con un
// PDF guardado en otra carpeta (ej. el cliente edito la fecha y eso cambia
// de mes), hay que borrar el archivo viejo despues de guardar el nuevo —
// ver mas abajo. El resto del script (el UPDATE/INSERT de verdad) reusa
// $facturaId en vez de volver a consultar.
$st = $pdo->prepare("SELECT id, ruta_pdf FROM facturas WHERE uuid_local = ?");
$st->execute([$uuid]);
$facturaExistente = $st->fetch() ?: null; // fetch() devuelve false, no null, si no hay fila
$facturaId = $facturaExistente['id'] ?? null;
$rutaPdfVieja = $facturaExistente['ruta_pdf'] ?? null;

// --- Guardar el PDF si vino ---
// Fase 8 de la migracion (archivos y subidas): nunca confiar en lo que dice
// el cliente sobre el archivo (nombre, extension) -- se valida el contenido
// real (cabecera %PDF-) y un tope de tamano antes de escribirlo a disco. Si
// no pasa, NO se falla toda la sincronizacion: la factura se guarda igual,
// solo sin PDF (mismo criterio que ya existia para una subida fallida).
//
// Carpeta (Fase 21): documentos/facturas/{slug del negocio}/{anio}/{mes}/,
// segun la FECHA DE LA FACTURA (no la fecha de sincronizacion) — asi se
// puede buscar "las facturas de marzo" sin importar cuando se sincronizaron.
// Como la web nunca edita una factura sincronizada, el archivo no se vuelve
// a mover por este lado; si se mueve es porque el cliente Python edito la
// fecha y volvio a sincronizar.
$rutaPdf = null;
if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK
    && $_FILES['pdf']['size'] > 0 && $_FILES['pdf']['size'] <= 25 * 1024 * 1024
    && is_uploaded_file($_FILES['pdf']['tmp_name'])
    && str_starts_with((string)file_get_contents($_FILES['pdf']['tmp_name'], false, null, 0, 5), '%PDF-')
) {
    $cfg = cargar_config();

    $fecha = trim((string)($datos['fecha'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $fecha = date('Y-m-d'); // defensivo: el contrato la pide siempre, pero nunca se confia ciegamente
    }
    $anio = substr($fecha, 0, 4);
    $mes  = substr($fecha, 5, 2);

    $carpeta = $cfg['carpeta_pdf'] . "/$slugNegocio/$anio/$mes";
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
    }
    $nombrePdf = $uuid . '.pdf';
    $destino = $carpeta . '/' . $nombrePdf;
    if (move_uploaded_file($_FILES['pdf']['tmp_name'], $destino)) {
        $rutaPdf = "$slugNegocio/$anio/$mes/$nombrePdf";  // ruta relativa a carpeta_pdf

        // Si ya tenia un PDF en otra ruta (cambio de fecha en una edicion),
        // el archivo viejo queda huerfano: se borra para no desperdiciar espacio.
        if ($rutaPdfVieja && $rutaPdfVieja !== $rutaPdf) {
            $pdfViejo = $cfg['carpeta_pdf'] . '/' . $rutaPdfVieja;
            if (is_file($pdfViejo)) {
                @unlink($pdfViejo);
            }
        }
    }
}

// --- Helpers de extraccion segura ---
$g = fn($k, $def = null) => $datos[$k] ?? $def;

try {
    $pdo->beginTransaction();

    // Upsert por uuid_local ($facturaId ya se resolvio mas arriba, antes de guardar el PDF)
    $campos = [
        'negocio_id'     => $negocioId,
        'proveedor'      => $g('proveedor'),
        'razon_social'   => $g('razon_social'),
        'rut_emisor'     => $g('rut_emisor'),
        'numero_factura' => $g('numero_factura'),
        'fecha'          => $g('fecha'),          // YYYY-MM-DD
        'total'          => $g('total'),
        'moneda'         => $g('moneda', 'CLP'),
        'confianza'      => $g('confianza'),
        'notas'          => $g('notas'),
    ];
    if ($rutaPdf !== null) {
        $campos['ruta_pdf'] = $rutaPdf;
    }

    if ($facturaId) {
        // UPDATE
        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($campos)));
        $campos['id'] = $facturaId;
        $pdo->prepare("UPDATE facturas SET $sets WHERE id = :id")->execute($campos);
        $accion = 'update';
    } else {
        // INSERT
        $campos['uuid_local'] = $uuid;
        $cols = implode(', ', array_keys($campos));
        $ph   = implode(', ', array_map(fn($c) => ":$c", array_keys($campos)));
        $pdo->prepare("INSERT INTO facturas ($cols) VALUES ($ph)")->execute($campos);
        $facturaId = (int)$pdo->lastInsertId();
        $accion = 'insert';
    }

    // Detalle: si viene, reemplazamos el detalle completo de la factura
    $detalle = $g('detalle');
    if (is_array($detalle)) {
        $pdo->prepare("DELETE FROM detalle_factura WHERE factura_id = ?")->execute([$facturaId]);
        $ins = $pdo->prepare(
            "INSERT INTO detalle_factura
             (factura_id, descripcion, cantidad, precio_unitario, descuento, monto, afecto_iva, precio_sugerido)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($detalle as $p) {
            $ins->execute([
                $facturaId,
                $p['descripcion'] ?? '',
                $p['cantidad'] ?? null,
                $p['precio_unitario'] ?? null,
                $p['descuento'] ?? null,
                $p['monto'] ?? null,
                isset($p['afecto_iva']) ? (int)(bool)$p['afecto_iva'] : 1,
                $p['precio_sugerido'] ?? null,
            ]);
        }
    }

    // Registrar sync + actualizar ultima_sync de la maquina
    $pdo->prepare("UPDATE maquinas SET ultima_sync = NOW() WHERE id = ?")->execute([$maquinaId]);
    $pdo->prepare(
        "INSERT INTO sync_log (maquina_id, accion, factura_uuid, exito) VALUES (?, ?, ?, 1)"
    )->execute([$maquinaId, $accion, $uuid]);

    $pdo->commit();
    responder_json(['ok' => true, 'accion' => $accion, 'factura_id' => $facturaId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log del error (sin exponer detalles internos al cliente)
    try {
        $pdo->prepare(
            "INSERT INTO sync_log (maquina_id, accion, factura_uuid, exito, error) VALUES (?, 'error', ?, 0, ?)"
        )->execute([$maquinaId, $uuid, substr($e->getMessage(), 0, 500)]);
    } catch (Throwable $e2) {}
    responder_json(['error' => 'Error al guardar la factura.'], 500);
}
