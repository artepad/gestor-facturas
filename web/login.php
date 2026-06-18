<?php
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';   // icono() + h()

// Si ya hay sesion, al panel
if (usuario_actual()) {
    header('Location: home.php');
    exit;
}

$COOKIE_RECORDAR = 'mm_recordar';
$https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');

// "Usar otro correo": olvida el correo recordado y recarga limpio
if (isset($_GET['olvidar'])) {
    setcookie($COOKIE_RECORDAR, '', time() - 3600, '/', '', $https, true);
    header('Location: login.php');
    exit;
}

$error = '';
$emailPrefill = $_COOKIE[$COOKIE_RECORDAR] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $clave    = $_POST['clave'] ?? '';
    $recordar = isset($_POST['recordar']);

    // Recordar (o olvidar) el correo según el checkbox. Nunca se guarda la clave.
    if ($recordar && $email !== '') {
        setcookie($COOKIE_RECORDAR, $email, time() + 60 * 60 * 24 * 30, '/', '', $https, true);
    } else {
        setcookie($COOKIE_RECORDAR, '', time() - 3600, '/', '', $https, true);
    }

    if (login($email, $clave)) {
        header('Location: home.php');
        exit;
    }
    $error = 'Correo o contraseña incorrectos.';
    $emailPrefill = $email;
}

$recordado = $emailPrefill !== '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar · Minimark</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body class="login-body">
    <div class="franja"></div>
    <div class="login-wrap">
        <form class="login-card" method="post" autocomplete="off">
            <div class="login-brand">
                <span class="login-logo"><?= icono('negocios') ?></span>
                <h1>Minimark</h1>
            </div>
            <p class="sub">Plataforma de gestión · Ingresa con tu cuenta</p>

            <?php if ($error): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="campo">
                <label>Correo electrónico</label>
                <input type="email" name="email" required value="<?= h($emailPrefill) ?>"
                       <?= $recordado ? '' : 'autofocus' ?>>
            </div>
            <div class="campo">
                <label>Contraseña</label>
                <input type="password" name="clave" required <?= $recordado ? 'autofocus' : '' ?>>
            </div>

            <div class="login-remember">
                <label>
                    <input type="checkbox" name="recordar" value="1" <?= $recordado ? 'checked' : '' ?>>
                    Recordar mi correo
                </label>
                <?php if ($recordado): ?>
                    <a class="login-olvidar" href="login.php?olvidar=1">Usar otro correo</a>
                <?php endif; ?>
            </div>

            <button class="btn" type="submit">Ingresar</button>
        </form>
    </div>
    <div class="pie">Minimark · Plataforma de gestión</div>
    <div class="franja-azul"></div>
</body>
</html>
