<?php
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_login();
$pdo = obtener_pdo();

// Permisos que afectan lo que se muestra en el inicio
$verFacturas = puede($usuario, 'facturas');
$verFiados   = puede($usuario, 'fiados');
$verNegocios = puede($usuario, 'negocios');
$verUsuarios = puede($usuario, 'usuarios');

// Negocios visibles para este usuario
$negocios = negocios_visibles($usuario);
$ids = array_map(fn($n) => (int)$n['id'], $negocios);
$nNegocios = count($negocios);

// Resumen de facturas (solo si el rol puede ver facturas)
$totFacturas = 0; $totMes = 0; $sumaMes = 0;
if ($verFacturas && $ids) {
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
?>
<?php cabecera_dashboard($usuario, 'home', 'Inicio'); ?>
    <div class="contenido">
        <h2 class="saludo">Hola, <?= h($usuario['nombre'] ?: 'usuario') ?> 👋</h2>
        <p class="saludo-sub">Resumen de tu actividad.</p>

        <?php if ($verFacturas || $verNegocios): ?>
        <div class="grid-negocios grid-stats">
            <?php if ($verFacturas): ?>
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
            <?php endif; ?>
            <?php if ($verNegocios): ?>
            <div class="panel">
                <div class="stat-label">Negocios</div>
                <div class="stat-valor"><?= (int)$nNegocios ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="panel bloque-sep">
            <h2>Accesos rápidos</h2>
            <div class="acciones-rapidas">
                <?php if ($verFacturas): ?><a class="btn" href="panel_facturas.php">Ver facturas</a><?php endif; ?>
                <?php if ($verFiados): ?><a class="btn" href="fiados.php">Fiados</a><?php endif; ?>
                <?php if (puede($usuario, 'ingresos')): ?><a class="btn" href="ingresos.php">Ingresos</a><?php endif; ?>
                <?php if ($verNegocios): ?><a class="btn gris" href="negocios.php">Gestionar negocios</a><?php endif; ?>
                <?php if ($verUsuarios): ?><a class="btn gris" href="usuarios.php">Gestionar usuarios</a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
