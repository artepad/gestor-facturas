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
?>
<?php cabecera_dashboard($usuario, 'home', 'Inicio'); ?>
    <div class="contenido">
        <h2 class="saludo">Hola, <?= h($usuario['nombre'] ?: 'usuario') ?> 👋</h2>
        <p class="saludo-sub">Resumen de tu actividad.</p>

        <div class="grid-negocios grid-stats">
            <div class="panel">
                <div class="stat-label">Facturas totales</div>
                <div class="stat-valor"><?= (int)$totFacturas ?></div>
            </div>
            <div class="panel">
                <div class="stat-label">Facturas este mes</div>
                <div class="stat-valor"><?= (int)$totMes ?></div>
            </div>
            <div class="panel">
                <div class="stat-label">Monto del mes</div>
                <div class="stat-valor">$<?= number_format((float)$sumaMes, 0, ',', '.') ?></div>
            </div>
            <?php if ($esAdmin): ?>
            <div class="panel">
                <div class="stat-label">Negocios</div>
                <div class="stat-valor"><?= (int)$nNegocios ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="panel bloque-sep">
            <h2>Accesos rápidos</h2>
            <div class="acciones-rapidas">
                <a class="btn" href="panel_facturas.php">Ver facturas</a>
                <?php if ($esAdmin): ?>
                    <a class="btn gris" href="negocios.php">Gestionar negocios</a>
                    <a class="btn gris" href="usuarios.php">Gestionar usuarios</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
