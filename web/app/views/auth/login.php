<?php
/**
 * Vista de app/Controllers/AuthController::login().
 * Variables disponibles: $error, $emailPrefill, $recordado.
 */
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
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="campo">
                <label>Correo electrónico</label>
                <input type="email" name="email" required value="<?= e($emailPrefill) ?>"
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
