<?php
/**
 * Vista de app/Controllers/HomeController::index().
 * Variables disponibles: $usuario, $verFacturas, $verFiados, $verIngresos,
 * $verGastos, $negocios, $nNegocios, $mesNombre, $meses, $ventasNeg,
 * $comprasNeg, $gastosNeg, $porDia, $kpis, $herramientas, $alertas, $multiNeg.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'home', 'Inicio'); ?>
    <div class="contenido">
        <div class="saludo-cab">
            <h2 class="saludo">Hola, <?= e($usuario['nombre'] ?: 'usuario') ?> 👋</h2>
            <p class="saludo-sub">Resumen de <?= e($mesNombre) ?></p>
        </div>

        <?php if ($kpis): ?>
        <div class="ingresos-stats">
            <?php foreach ($kpis as $k):
                $tag = !empty($k['link']) ? 'a' : 'div';
                $clases = 'stat-card' . ($k['borde'] ?? '' ? ' ' . $k['borde'] : '')
                        . (!empty($k['destacado']) ? ' stat-destacado' : '')
                        . (!empty($k['link']) ? ' stat-link' : '');
                $href = !empty($k['link']) ? ' href="' . e($k['link']) . '"' : '';
            ?>
            <<?= $tag . $href ?> class="<?= $clases ?>">
                <span class="stat-label"><?= e($k[0]) ?></span>
                <span class="stat-valor <?= e($k['color'] ?? '') ?>"><?= e($k[1]) ?></span>
                <?php if (array_key_exists('comp', $k) && $k['comp'] !== null): ?>
                    <span class="stat-comp <?= $k['comp'] >= 0 ? 'comp-sube' : 'comp-baja' ?>">
                        <?= ($k['comp'] >= 0 ? '▲ +' : '▼ ') . number_format($k['comp'], 1, ',', '.') ?>%
                        <span class="texto-tenue">vs. mes anterior</span>
                    </span>
                <?php elseif (!empty($k['sub'])): ?>
                    <span class="stat-comp texto-tenue"><?= e($k['sub']) ?></span>
                <?php endif; ?>
            </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($nNegocios > 1): ?>
        <h3 class="dash-titulo">Estado por negocio</h3>
        <div class="negocios-resumen">
            <?php foreach ($negocios as $n): $nid = (int)$n['id']; ?>
            <a class="nr-card" href="negocio_panel.php?id=<?= $nid ?>">
                <h3><?= icono('negocios') ?> <?= e($n['nombre']) ?> <span class="nr-flecha">→</span></h3>
                <div class="nr-metricas">
                    <?php if ($verIngresos): ?>
                    <div>
                        <span class="nr-m-label">Ventas</span>
                        <span class="nr-m-valor"><?= clp($ventasNeg[$nid] ?? 0) ?: '$0' ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($verFacturas): ?>
                    <div>
                        <span class="nr-m-label">Compras</span>
                        <span class="nr-m-valor"><?= clp($comprasNeg[$nid]['m'] ?? 0) ?: '$0' ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($verGastos): ?>
                    <div>
                        <span class="nr-m-label">Gastos</span>
                        <span class="nr-m-valor valor-rojo"><?= clp($gastosNeg[$nid] ?? 0) ?: '$0' ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($verIngresos && $verGastos): $balNeg = ($ventasNeg[$nid] ?? 0) - ($comprasNeg[$nid]['m'] ?? 0) - ($gastosNeg[$nid] ?? 0); ?>
                    <div>
                        <span class="nr-m-label">Balance</span>
                        <span class="nr-m-valor <?= $balNeg >= 0 ? 'valor-verde' : 'valor-rojo' ?>"><?= clp($balNeg) ?: '$0' ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($herramientas): ?>
        <h3 class="dash-titulo">Herramientas</h3>
        <div class="home-tools">
            <?php foreach ($herramientas as $t): ?>
            <a class="home-tool" href="<?= e($t[2]) ?>">
                <span class="home-tool-ic"><?= icono($t[0]) ?></span>
                <span class="home-tool-txt"><?= e($t[1]) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="dash-cols">
            <?php if (count($porDia) > 1): ?>
            <div class="panel">
                <h2 class="panel-titulo">Ventas por día · <?= e($meses[(int)date('n')]) ?></h2>
                <?php
                    $maxV = 0;
                    foreach ($porDia as $p) $maxV = max($maxV, (float)$p['v']);
                    $nB = count($porDia);
                    $aB = max(10, min(40, intdiv(560, $nB) - 6));
                    $paso = $aB + 9; $w = $paso * $nB + 12; $hG = 140;
                ?>
                <div class="grafico-scroll">
                <svg class="grafico-barras" viewBox="0 0 <?= $w ?> <?= $hG + 32 ?>" width="<?= $w ?>" height="<?= $hG + 32 ?>" role="img" aria-label="Ventas por día">
                    <?php foreach ($porDia as $i => $p):
                        $v = (float)$p['v'];
                        $hB = $maxV > 0 ? max(3, round($v / $maxV * ($hG - 22))) : 3;
                        $x = 6 + $i * $paso; $y = $hG - $hB;
                    ?>
                    <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $aB ?>" height="<?= $hB ?>" rx="3" class="barra">
                        <title><?= e(fecha_dmy($p['d'])) ?>: <?= clp($v) ?></title>
                    </rect>
                    <text x="<?= $x + $aB / 2 ?>" y="<?= $hG + 15 ?>" class="barra-fecha" text-anchor="middle"><?= e(substr($p['d'], 8, 2)) ?></text>
                    <?php endforeach; ?>
                </svg>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($verFacturas || $verIngresos || $verFiados): ?>
            <div class="panel">
                <h2 class="panel-titulo">Requiere atención</h2>
                <?php if (!$alertas): ?>
                    <div class="atencion-ok"><?= icono('reloj') ?> Todo al día, sin pendientes.</div>
                <?php else: foreach ($alertas as $a): ?>
                    <a class="atencion-item <?= e($a['color']) ?>" href="<?= e($a['link']) ?>">
                        <span class="atencion-ic"><?= icono($a['modulo']) ?></span>
                        <span class="atencion-txt">
                            <span class="atencion-tit"><?= e($a['titulo']) ?></span>
                            <span class="atencion-desc"><?= e($a['desc']) ?></span>
                        </span>
                        <span class="atencion-flecha">→</span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
    <?php pie_dashboard(); ?>
