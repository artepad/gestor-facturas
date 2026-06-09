<?php
/**
 * Borra TODAS las facturas y su detalle del servidor (para empezar pruebas
 * de cero). CONSERVA negocios, usuarios y maquinas (login y token siguen
 * funcionando). Requiere estar logueado como admin.
 *
 *   https://admin.minimark.cl/reset_facturas.php
 *
 * Puedes dejarlo o borrarlo; solo lo usa un admin autenticado.
 */

require __DIR__ . '/lib/auth.php';

$usuario = exigir_login();
if (($usuario['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Solo un administrador puede hacer esto.');
}

$hecho = false;
$conteo = 0;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['confirmar'] ?? '') === 'BORRAR') {
    $pdo = obtener_pdo();
    $conteo = (int)$pdo->query("SELECT COUNT(*) FROM facturas")->fetchColumn();
    // El ON DELETE CASCADE de detalle_factura se encarga del detalle.
    $pdo->exec("DELETE FROM facturas");
    $pdo->exec("DELETE FROM sync_log");
    // Borrar PDFs almacenados
    $cfg = cargar_config();
    $base = $cfg['carpeta_pdf'];
    if (is_dir($base)) {
        foreach (glob($base . '/*/*.pdf') as $pdf) { @unlink($pdf); }
    }
    $hecho = true;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reiniciar facturas</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
    <div class="franja"></div>
    <div class="login-wrap">
        <form class="login-card" method="post">
            <h1>Reiniciar facturas</h1>
            <?php if ($hecho): ?>
                <div class="error" style="background:#e8f5e9;color:#1a7a3a;border-color:#c8e6c9">
                    Listo. Se borraron <?= $conteo ?> factura(s) y sus PDFs.
                </div>
                <p style="text-align:center"><a href="panel_facturas.php">Ir al panel</a></p>
            <?php else: ?>
                <p class="sub">Borra TODAS las facturas y PDFs del servidor.
                   Conserva tu usuario y el token. Esto es irreversible.</p>
                <div class="campo">
                    <label>Escribe BORRAR para confirmar</label>
                    <input type="text" name="confirmar" required autofocus>
                </div>
                <button class="btn" type="submit" style="background:#dc3545">
                    Borrar todas las facturas
                </button>
                <p style="text-align:center;margin-top:14px">
                    <a href="panel_facturas.php">Cancelar</a>
                </p>
            <?php endif; ?>
        </form>
    </div>
    <div class="pie">Minimark · Plataforma de gestión</div>
</body>
</html>
