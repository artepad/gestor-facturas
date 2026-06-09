<?php
/**
 * Crea el usuario administrador mediante un FORMULARIO (sin claves en la URL).
 * Subelo, abrelo en el navegador, llena los campos y envia.
 *
 *   https://admin.minimark.cl/crear_admin_form.php
 *
 * *** BORRA este archivo despues de crear tu usuario. ***
 */

require __DIR__ . '/lib/db.php';

$mensaje = '';
$ok = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email  = trim($_POST['email'] ?? '');
    $clave  = $_POST['clave'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $rol    = ($_POST['rol'] ?? 'admin') === 'sucursal' ? 'sucursal' : 'admin';

    if ($email === '' || $clave === '') {
        $mensaje = 'Correo y contraseña son obligatorios.';
    } elseif (strlen($clave) < 6) {
        $mensaje = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        try {
            $pdo = obtener_pdo();
            $hash = password_hash($clave, PASSWORD_DEFAULT);
            $st = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $st->execute([$email]);
            $id = $st->fetchColumn();
            if ($id) {
                $pdo->prepare("UPDATE usuarios SET password_hash=?, nombre=?, rol=? WHERE id=?")
                    ->execute([$hash, $nombre, $rol, $id]);
                $mensaje = "Usuario actualizado: $email (rol $rol).";
            } else {
                $pdo->prepare("INSERT INTO usuarios (email, password_hash, nombre, rol) VALUES (?,?,?,?)")
                    ->execute([$email, $hash, $nombre, $rol]);
                $mensaje = "Usuario creado: $email (rol $rol).";
            }
            $ok = true;
        } catch (Throwable $e) {
            $mensaje = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear usuario administrador</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
    <div class="franja"></div>
    <div class="login-wrap">
        <form class="login-card" method="post" autocomplete="off">
            <h1>Crear administrador</h1>
            <p class="sub">Llena los datos del usuario del dashboard</p>

            <?php if ($mensaje): ?>
                <div class="<?= $ok ? 'error' : 'error' ?>"
                     style="<?= $ok ? 'background:#e8f5e9;color:#1a7a3a;border-color:#c8e6c9' : '' ?>">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <?php if ($ok): ?>
                <p style="text-align:center">
                    Ya puedes <a href="login.php">ingresar</a>.<br><br>
                    <strong style="color:#dc3545">Importante:</strong>
                    borra <code>crear_admin_form.php</code> del servidor.
                </p>
            <?php else: ?>
                <div class="campo">
                    <label>Correo electrónico</label>
                    <input type="email" name="email" required autofocus
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label>Nombre</label>
                    <input type="text" name="nombre"
                           value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label>Contraseña (mínimo 6 caracteres)</label>
                    <input type="password" name="clave" required>
                </div>
                <input type="hidden" name="rol" value="admin">
                <button class="btn" type="submit">Crear administrador</button>
            <?php endif; ?>
        </form>
    </div>
    <div class="pie">Minimark · Plataforma de gestión</div>
</body>
</html>
