<?php
/**
 * Crea (o actualiza) un usuario del dashboard. Correr desde el navegador:
 *
 *   https://admin.minimark.cl/crear_admin.php?key=TU_SETUP_KEY
 *       &email=tu@correo.cl&clave=TuClave123&nombre=Miguel&rol=admin
 *
 * Para rol 'sucursal', agrega &negocio=ID para asignarle un negocio.
 * BORRA este archivo despues de crear tus usuarios.
 */

require __DIR__ . '/lib/db.php';

$cfg = cargar_config();
if (!hash_equals($cfg['setup_key'], $_GET['key'] ?? '')) {
    http_response_code(403);
    exit('Acceso denegado. Falta o es incorrecta la clave (?key=...).');
}

header('Content-Type: text/plain; charset=utf-8');

$email  = trim($_GET['email'] ?? '');
$clave  = $_GET['clave'] ?? '';
$nombre = trim($_GET['nombre'] ?? '');
$rol    = ($_GET['rol'] ?? 'admin') === 'sucursal' ? 'sucursal' : 'admin';

if ($email === '' || $clave === '') {
    exit("Faltan parametros. Usa:\n"
       . "?key=...&email=tu@correo.cl&clave=TuClave&nombre=Miguel&rol=admin\n");
}
if (strlen($clave) < 6) {
    exit("La clave debe tener al menos 6 caracteres.\n");
}

$pdo = obtener_pdo();
$hash = password_hash($clave, PASSWORD_DEFAULT);

// Upsert por email
$st = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
$st->execute([$email]);
$id = $st->fetchColumn();

if ($id) {
    $pdo->prepare("UPDATE usuarios SET password_hash=?, nombre=?, rol=? WHERE id=?")
        ->execute([$hash, $nombre, $rol, $id]);
    echo "Usuario actualizado: $email (rol $rol)\n";
} else {
    $pdo->prepare("INSERT INTO usuarios (email, password_hash, nombre, rol) VALUES (?,?,?,?)")
        ->execute([$email, $hash, $nombre, $rol]);
    $id = (int)$pdo->lastInsertId();
    echo "Usuario creado: $email (rol $rol)\n";
}

// Asignar negocio si es sucursal y se paso ?negocio=ID
$negocio = isset($_GET['negocio']) ? (int)$_GET['negocio'] : 0;
if ($negocio) {
    $pdo->prepare("INSERT IGNORE INTO usuario_negocio (usuario_id, negocio_id) VALUES (?, ?)")
        ->execute([$id, $negocio]);
    echo "Asignado al negocio id $negocio\n";
}

echo "\nListo. Ya puedes entrar en https://admin.minimark.cl/login.php\n";
echo "*** BORRA crear_admin.php del servidor por seguridad. ***\n";
