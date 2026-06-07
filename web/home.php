<?php
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_login();
$pdo = obtener_pdo();
$esAdmin = ($usuario['rol'] ?? '') === 'admin';

// Negocios visibles para este usuario
$negocios = negocios_visibles($usuario);
$ids = array_map(fn($n) => (int)$n['id'], $negocios);

// Resumen
$totFacturas = 0; $totMes = 0; $sumaMes = 0;
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM facturas WHERE negocio_id IN ($ph) AND eliminada_en IS NULL");
    $st->execute($ids);
    $totFacturas = (int)$st->fetchColumn();

    $inicioMes = date('Y-m-01');
    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total),0) FROM facturas
        WHERE negocio_id IN ($ph) AND eliminada_en IS NULL AND fecha >= ?");
    $st->execute([...$ids, $inicioMes]);
    [$totMes, $sumaMes] = array_values($st->fetch(PDO::FETCH_NUM));
}
$nNegocios = count($negocios);

function h($v) { return htmlspecialchars((string)($v ?? '')); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inicio · Sistema de Gestión</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
    <?php cabecera_dashboard($usuario, 'home'); ?>
    <div class="contenido">
        <h2 style="margin-top:0">Hola, <?= h($usuario['nombre'] ?: 'usuario') ?> 👋</h2>
        <p style="color:var(--texto-sec);margin-top:-6px">
            Resumen de tu actividad.
        </p>

        <div class="grid-negocios" style="margin-top:18px">
            <div class="panel">
                <div style="font-size:13px;color:var(--texto-sec)">Facturas totales</div>
                <div style="font-size:30px;font-weight:700"><?= (int)$totFacturas ?></div>
            </div>
            <div class="panel">
                <div style="font-size:13px;color:var(--texto-sec)">Facturas este mes</div>
                <div style="font-size:30px;font-weight:700"><?= (int)$totMes ?></div>
            </div>
            <div class="panel">
                <div style="font-size:13px;color:var(--texto-sec)">Monto del mes</div>
                <div style="font-size:30px;font-weight:700">$<?= number_format((float)$sumaMes, 0, ',', '.') ?></div>
            </div>
            <?php if ($esAdmin): ?>
            <div class="panel">
                <div style="font-size:13px;color:var(--texto-sec)">Negocios</div>
                <div style="font-size:30px;font-weight:700"><?= (int)$nNegocios ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="panel" style="margin-top:20px">
            <h2>Accesos rápidos</h2>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <a class="btn" href="panel.php">Ver facturas</a>
                <?php if ($esAdmin): ?>
                    <a class="btn gris" href="negocios.php">Gestionar negocios</a>
                    <a class="btn gris" href="usuarios.php">Gestionar usuarios</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
</body>
</html>
