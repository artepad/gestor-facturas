<?php
/**
 * Vista de app/Controllers/PortalProveedorController::index() para el caso
 * (no debería pasar nunca) de una cuenta 'representante' sin proveedor_id.
 * Variables disponibles: $usuario.
 */
require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal de proveedor · Minimark</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body class="login-body">
    <div class="franja"></div>
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-brand">
                <span class="login-logo"><?= icono('negocios') ?></span>
                <h1>Minimark</h1>
            </div>
            <p class="sub">Tu cuenta todavía no tiene un proveedor asignado. Pídele a Minimark que lo revise.</p>
            <a class="btn gris" href="logout.php">Cerrar sesión</a>
        </div>
    </div>
    <div class="pie">Minimark · Plataforma de gestión</div>
    <div class="franja-azul"></div>
</body>
</html>
