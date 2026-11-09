<?php
/**
 * Vista de app/Controllers/NegociosController::panel().
 * Variables disponibles: $usuario, $id, $negocio, $verIngresos, $verFacturas,
 * $verGastos, $verFiados, $fDesde, $fHasta, $inicioMesAnt, $finMesAnt, $ventas,
 * $porDia, $porDepto, $compras, $topComprados, $gastosTotal, $gastosCat,
 * $hero, $kpis, $esEsteMes, $esMesAnt, $esPersonalizado, $metodos.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

// Helper para el ancho de barras horizontales.
$pctDe = fn(float $v, float $max) => $max > 0 ? round($v / $max * 100) : 0;
?>
<?php cabecera_dashboard($usuario, 'home', $negocio['nombre']); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-azul">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('negocios') ?></span>
                <h2><?= e($negocio['nombre']) ?></h2>
            </div>
            <a class="btn gris" href="home.php">Volver</a>
        </div>

        <!-- Período: chips rápidos + personalizar (fechas en desplegable) -->
        <div class="np-periodo">
            <a class="np-chip <?= $esEsteMes ? 'activo' : '' ?>" href="negocio_panel.php?id=<?= $id ?>">Este mes</a>
            <a class="np-chip <?= $esMesAnt ? 'activo' : '' ?>" href="negocio_panel.php?id=<?= $id ?>&amp;desde=<?= $inicioMesAnt ?>&amp;hasta=<?= $finMesAnt ?>">Mes anterior</a>
            <details class="np-fechas" <?= $esPersonalizado ? 'open' : '' ?>>
                <summary class="np-chip <?= $esPersonalizado ? 'activo' : '' ?>">Personalizar ▾</summary>
                <form class="np-fechas-form" method="get">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="campo"><label>Desde</label>
                        <input type="date" name="desde" value="<?= e($fDesde) ?>"></div>
                    <div class="campo"><label>Hasta</label>
                        <input type="date" name="hasta" value="<?= e($fHasta) ?>"></div>
                    <button class="btn" type="submit">Aplicar</button>
                </form>
            </details>
        </div>

        <!-- Hero: resultado del período -->
        <?php if ($hero): ?>
        <div class="np-hero">
            <div>
                <div class="np-hero-lbl"><?= e($hero['titulo']) ?> · <?= e(fecha_dmy($fDesde)) ?> a <?= e(fecha_dmy($fHasta)) ?></div>
                <div class="np-hero-val <?= e($hero['color']) ?>"><?= e($hero['signo'] . $hero['valor']) ?></div>
                <div class="np-hero-sub"><?= e($hero['sub']) ?></div>
            </div>
            <?php if ($verIngresos): ?>
            <div class="np-hero-side">
                <div><span class="hs-lbl">Ventas</span><span class="hs-val"><?= clp($ventas['total']) ?: '$0' ?></span></div>
                <?php if ($verGastos): ?><div><span class="hs-lbl">Gastos</span><span class="hs-val"><?= clp($gastosTotal) ?: '$0' ?></span></div><?php endif; ?>
                <div><span class="hs-lbl">N° ventas</span><span class="hs-val"><?= number_format($ventas['nventas'], 0, ',', '.') ?></span></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- KPIs -->
        <?php if ($kpis): ?>
        <div class="np-kpis">
            <?php foreach ($kpis as $k): ?>
            <div class="np-kpi <?= e($k[3]) ?>">
                <span class="np-kpi-lbl"><?= icono($k[4]) ?> <?= e($k[0]) ?></span>
                <span class="np-kpi-val <?= e($k[2]) ?>"><?= e($k[1]) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Evolución diaria de ventas -->
        <?php if ($verIngresos && $porDia): ?>
        <div class="panel">
            <h2 class="panel-titulo"><?= icono('ingresos') ?> Ventas por día</h2>
            <?php
                $maxV = 0; foreach ($porDia as $p) $maxV = max($maxV, (float)$p['v']);
                $nB = count($porDia);
                $aB = max(10, min(40, intdiv(560, max(1, $nB)) - 6));
                $paso = $aB + 9; $w = $paso * $nB + 12; $hG = 140;
            ?>
            <div class="grafico-scroll">
            <svg class="grafico-barras" viewBox="0 0 <?= $w ?> <?= $hG + 32 ?>" width="<?= $w ?>" height="<?= $hG + 32 ?>" role="img" aria-label="Ventas por día">
                <?php foreach ($porDia as $i => $p):
                    $v = (float)$p['v'];
                    $hB = $maxV > 0 ? max(3, round($v / $maxV * ($hG - 22))) : 3;
                    $x = 6 + $i * $paso; $y = $hG - $hB; ?>
                <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $aB ?>" height="<?= $hB ?>" rx="3" class="barra">
                    <title><?= e(fecha_dmy($p['d'])) ?>: <?= clp($v) ?></title>
                </rect>
                <text x="<?= $x + $aB / 2 ?>" y="<?= $hG + 15 ?>" class="barra-fecha" text-anchor="middle"><?= e(substr($p['d'], 8, 2)) ?></text>
                <?php endforeach; ?>
            </svg>
            </div>
        </div>
        <?php endif; ?>

        <div class="dash-cols">
            <!-- Métodos de pago -->
            <?php if ($verIngresos && $metodos): $maxM = max(array_map(fn($m) => (float)$m[1], $metodos)); ?>
            <div class="panel">
                <h2 class="panel-titulo"><?= icono('ingresos') ?> Métodos de pago</h2>
                <div class="cat-barras">
                    <?php foreach ($metodos as $m): ?>
                    <div class="cat-fila">
                        <span class="cat-nombre"><?= e($m[0]) ?></span>
                        <span class="cat-barra"><span class="cat-relleno cat-azul" style="width: <?= $pctDe((float)$m[1], $maxM) ?>%"></span></span>
                        <span class="cat-monto"><?= clp($m[1]) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Ventas por departamento -->
            <?php if ($verIngresos && $porDepto): $maxD = max(array_map(fn($d) => (float)$d['total'], $porDepto)); ?>
            <div class="panel">
                <h2 class="panel-titulo"><?= icono('negocios') ?> Ventas por departamento</h2>
                <div class="cat-barras">
                    <?php foreach ($porDepto as $d): ?>
                    <div class="cat-fila">
                        <span class="cat-nombre"><?= e($d['departamento']) ?></span>
                        <span class="cat-barra"><span class="cat-relleno cat-azul" style="width: <?= $pctDe((float)$d['total'], $maxD) ?>%"></span></span>
                        <span class="cat-monto"><?= clp($d['total']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Gastos por categoría -->
            <?php if ($verGastos && $gastosCat): $maxG = max(array_map(fn($c) => (float)$c['total'], $gastosCat)); ?>
            <div class="panel">
                <h2 class="panel-titulo"><?= icono('gastos') ?> Gastos por categoría</h2>
                <div class="cat-barras">
                    <?php foreach ($gastosCat as $c): ?>
                    <div class="cat-fila">
                        <span class="cat-nombre"><?= e($c['nombre']) ?></span>
                        <span class="cat-barra"><span class="cat-relleno" style="width: <?= $pctDe((float)$c['total'], $maxG) ?>%"></span></span>
                        <span class="cat-monto"><?= clp($c['total']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Productos más comprados -->
            <?php if ($verFacturas && $topComprados): $maxC = max(array_map(fn($c) => (float)$c['total'], $topComprados)); ?>
            <div class="panel">
                <h2 class="panel-titulo"><?= icono('facturas') ?> Productos más comprados</h2>
                <div class="cat-barras">
                    <?php foreach ($topComprados as $c): ?>
                    <div class="cat-fila">
                        <span class="cat-nombre" title="<?= e($c['descripcion']) ?>"><?= e($c['descripcion']) ?></span>
                        <span class="cat-barra"><span class="cat-relleno cat-naranjo" style="width: <?= $pctDe((float)$c['total'], $maxC) ?>%"></span></span>
                        <span class="cat-monto"><?= clp($c['total']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$kpis): ?>
            <div class="panel"><p class="vacio" style="margin:0">No tienes permisos para ver indicadores de este negocio.</p></div>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
