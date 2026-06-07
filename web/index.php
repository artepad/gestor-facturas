<?php
/**
 * Pagina raiz de admin.minimark.cl
 * Por ahora solo confirma que el servidor esta vivo. El dashboard con
 * login y listado de facturas se construye en una fase posterior.
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistema de Gestion de Facturas</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background:#f8f9fa;
               color:#2c3e50; display:flex; min-height:100vh; margin:0;
               align-items:center; justify-content:center; }
        .tarjeta { background:#fff; border:1px solid #dde1e6; border-radius:10px;
                   padding:40px 48px; text-align:center; box-shadow:0 2px 12px rgba(0,0,0,.05); }
        h1 { margin:0 0 6px; font-size:22px; }
        p { color:#6c757d; margin:4px 0; }
        .ok { color:#1a7a3a; font-weight:bold; }
    </style>
</head>
<body>
    <div class="tarjeta">
        <h1>Sistema de Gestion de Facturas</h1>
        <p class="ok">Servidor activo</p>
        <p>El panel de administracion estara disponible proximamente.</p>
    </div>
</body>
</html>
