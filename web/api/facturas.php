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
$st = $pdo->prepare("SELECT id, negocio_id FROM maquinas WHERE token_hash = ?");
$st->execute([$tokenHash]);
$maquina = $st->fetch();
if (!$maquina) {
    responder_json(['error' => 'Token invalido.'], 401);
}
$maquinaId = (int)$maquina['id'];
$negocioId = (int)$maquina['negocio_id'];

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

// --- Guardar el PDF si vino ---
$rutaPdf = null;
if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
    $cfg = cargar_config();
    $carpeta = $cfg['carpeta_pdf'] . '/' . $negocioId;
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
    }
    $nombrePdf = $uuid . '.pdf';
    $destino = $carpeta . '/' . $nombrePdf;
    if (move_uploaded_file($_FILES['pdf']['tmp_name'], $destino)) {
        $rutaPdf = $negocioId . '/' . $nombrePdf;  // ruta relativa al almacen
    }
}

// --- Helpers de extraccion segura ---
$g = fn($k, $def = null) => $datos[$k] ?? $def;

try {
    $pdo->beginTransaction();

    // Upsert por uuid_local
    $st = $pdo->prepare("SELECT id FROM facturas WHERE uuid_local = ?");
    $st->execute([$uuid]);
    $facturaId = $st->fetchColumn();

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
