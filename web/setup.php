<?php
/**
 * Setup inicial del servidor. Se corre UNA vez desde el navegador:
 *   https://admin.minimark.cl/setup.php?key=TU_SETUP_KEY
 *
 * Hace:
 *  1. Crea todas las tablas (esquema.sql).
 *  2. Crea un negocio.
 *  3. Crea una maquina para ese negocio y genera su TOKEN (se muestra UNA vez).
 *  4. Opcionalmente crea el usuario admin del dashboard.
 *
 * IMPORTANTE: borra este archivo despues de usarlo.
 */

require __DIR__ . '/lib/db.php';

$cfg = cargar_config();

// --- Seguridad: exige la clave correcta ---
$key = $_GET['key'] ?? '';
if (!hash_equals($cfg['setup_key'], $key)) {
    http_response_code(403);
    echo "Acceso denegado. Falta o es incorrecta la clave (?key=...).";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family:Consolas,monospace;font-size:14px;line-height:1.5'>";
echo "== Setup del Sistema de Gestion de Facturas ==\n\n";

$pdo = obtener_pdo();

// --- 1. Crear tablas ---
echo "[1] Creando tablas...\n";
$sql = file_get_contents(__DIR__ . '/lib/esquema.sql');
// Ejecutar sentencia por sentencia (PDO no corre multiples con exec en algunos drivers)
foreach (array_filter(array_map('trim', explode(';', $sql))) as $sentencia) {
    if ($sentencia !== '') {
        $pdo->exec($sentencia);
    }
}
echo "    OK\n\n";

// --- 2. Crear negocio (si se pasa ?negocio=...&slug=...) ---
$nombreNegocio = $_GET['negocio'] ?? '';
$slug = $_GET['slug'] ?? '';
$nombreMaquina = $_GET['maquina'] ?? 'PC principal';

if ($nombreNegocio === '' || $slug === '') {
    echo "Para crear un negocio y su token, agrega a la URL:\n";
    echo "  &negocio=Minimark+Centro&slug=minimark-centro&maquina=Caja+1\n\n";
    echo "Tablas listas. Vuelve a llamar con esos parametros.\n";
    echo "</pre>";
    exit;
}

// Evitar duplicar negocio con el mismo slug
$st = $pdo->prepare("SELECT id FROM negocios WHERE slug = ?");
$st->execute([$slug]);
$negocioId = $st->fetchColumn();
if (!$negocioId) {
    $st = $pdo->prepare("INSERT INTO negocios (slug, nombre) VALUES (?, ?)");
    $st->execute([$slug, $nombreNegocio]);
    $negocioId = (int)$pdo->lastInsertId();
    echo "[2] Negocio creado: {$nombreNegocio} (id {$negocioId}, slug {$slug})\n\n";
} else {
    echo "[2] Negocio ya existia: {$nombreNegocio} (id {$negocioId})\n\n";
}

// --- 3. Crear maquina + token ---
echo "[3] Generando token de maquina...\n";
$token = bin2hex(random_bytes(24));            // token plano (48 hex chars)
$tokenHash = hash('sha256', $token);
$st = $pdo->prepare("INSERT INTO maquinas (negocio_id, nombre, token_hash) VALUES (?, ?, ?)");
$st->execute([$negocioId, $nombreMaquina, $tokenHash]);
$maquinaId = (int)$pdo->lastInsertId();

echo "    Maquina '{$nombreMaquina}' creada (id {$maquinaId}).\n\n";
echo "================================================================\n";
echo "  TOKEN DE LA MAQUINA (copialo, NO se vuelve a mostrar):\n\n";
echo "  {$token}\n\n";
echo "================================================================\n\n";
echo "Este token va en el config del PC de ese negocio.\n";
echo "Cada negocio/PC debe tener su propio token (vuelve a correr\n";
echo "setup con otro &slug y &maquina para crear otro).\n\n";
echo "*** AHORA BORRA setup.php del servidor por seguridad. ***\n";
echo "</pre>";
