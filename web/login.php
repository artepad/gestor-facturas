<?php
require __DIR__ . '/lib/auth.php';

// Si ya hay sesion, al panel
if (usuario_actual()) {
    header('Location: home.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = $_POST['email'] ?? '';
    $clave = $_POST['clave'] ?? '';
    if (login($email, $clave)) {
        header('Location: home.php');
        exit;
    }
    $error = 'Correo o contraseña incorrectos.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar · Minimark</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
    <div class="franja"></div>
    <div class="login-wrap">
        <form class="login-card" method="post" autocomplete="off">
            <h1>Minimark</h1>
            <p class="sub">Plataforma de gestión · Ingresa con tu cuenta</p>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <div class="campo">
                <label>Correo electrónico</label>
                <input type="email" name="email" required autofocus>
            </div>
            <div class="campo">
                <label>Contraseña</label>
                <input type="password" name="clave" required>
            </div>
            <button class="btn" type="submit">Ingresar</button>
        </form>
    </div>
    <div class="pie">Minimark · Plataforma de gestión</div>
</body>
</html>
