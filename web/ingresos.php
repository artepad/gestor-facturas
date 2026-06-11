<?php
/**
 * Módulo Ingresos: dashboard de los cortes de caja que envía Eleventa.
 * Filtros por negocio/fechas/cajero, tarjetas resumen con comparativa contra
 * el período anterior, evolución diaria, rendimiento por cajero y la tabla
 * de cortes.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('ingresos');
$pdo = obtener_pdo();

$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

// --- Filtros ---
$fNegocio = (int)($_GET['negocio'] ?? 0);
if ($fNegocio && !in_array($fNegocio, $idsVisibles, true)) $fNegocio = 0;
$hoy    = date('Y-m-d');
$fDesde = $_GET['desde'] ?? date('Y-m-01');
$fHasta = $_GET['hasta'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)) $fDesde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)) $fHasta = $hoy;
$fCajero = (int)($_GET['cajero'] ?? 0);

// --- Condición base (negocios visibles + filtros) ---
$ids = $fNegocio ? [$fNegocio] : $idsVisibles;
$datos = [];
$resumen = ['ventas' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0,
            'salidas' => 0, 'n' => 0];
$resumenAnt = null;
$cortes = []; $porCajero = []; $porDia = []; $cajerosFiltro = [];
$correosError = 0;

if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $cond = "c.negocio_id IN ($ph) AND DATE(c.cerrado_en) BETWEEN ? AND ?";
    $par  = [...$ids, $fDesde, $fHasta];
    if ($fCajero) { $cond .= " AND c.cajero_id = ?"; $par[] = $fCajero; }

    $sumas = "COALESCE(SUM(c.ventas_totales),0), COALESCE(SUM(c.ventas_efectivo),0),
              COALESCE(SUM(c.ventas_tarjeta),0), COALESCE(SUM(c.ventas_transferencia),0),
              COALESCE(SUM(c.salidas_caja),0), COUNT(*)";

    $st = $pdo->prepare("SELECT $sumas FROM cortes c WHERE $cond");
    $st->execute($par);
    [$resumen['ventas'], $resumen['efectivo'], $resumen['tarjeta'],
     $resumen['transferencia'], $resumen['salidas'], $resumen['n']] =
        array_values($st->fetch(PDO::FETCH_NUM));

    // Período anterior equivalente (mismos días hacia atrás) para la comparativa
    $dias = max(1, (int)((strtotime($fHasta) - strtotime($fDesde)) / 86400) + 1);
    $antHasta = date('Y-m-d', strtotime("$fDesde -1 day"));
    $antDesde = date('Y-m-d', strtotime("$antHasta -" . ($dias - 1) . " day"));
    $parAnt = [...$ids, $antDesde, $antHasta];
    if ($fCajero) $parAnt[] = $fCajero;
    $st = $pdo->prepare("SELECT $sumas FROM cortes c WHERE $cond");
    $st->execute($parAnt);
    $fila = array_values($st->fetch(PDO::FETCH_NUM));
    if ((int)$fila[5] > 0) {
        $resumenAnt = ['ventas' => (float)$fila[0], 'desde' => $antDesde, 'hasta' => $antHasta];
    }

    // Evolución diaria (gráfico)
    $st = $pdo->prepare(
        "SELECT DATE(c.cerrado_en) d, COALESCE(SUM(c.ventas_totales),0) v
         FROM cortes c WHERE $cond GROUP BY DATE(c.cerrado_en) ORDER BY d"
    );
    $st->execute($par);
    $porDia = $st->fetchAll();

    // Rendimiento por cajero
    $st = $pdo->prepare(
        "SELECT cj.nombre, u.nombre AS usuario_web, COUNT(*) n,
                COALESCE(SUM(c.ventas_totales),0) ventas,
                COALESCE(SUM(c.ventas_efectivo),0) efectivo,
                COALESCE(SUM(c.salidas_caja),0) salidas
         FROM cortes c
         LEFT JOIN cajeros cj ON cj.id = c.cajero_id
         LEFT JOIN usuarios u ON u.id = cj.usuario_id
         WHERE $cond GROUP BY c.cajero_id, cj.nombre, u.nombre ORDER BY ventas DESC"
    );
    $st->execute($par);
    $porCajero = $st->fetchAll();

    // Tabla de cortes
    $st = $pdo->prepare(
        "SELECT c.*, n.nombre AS negocio, cj.nombre AS cajero
         FROM cortes c
         JOIN negocios n ON n.id = c.negocio_id
         LEFT JOIN cajeros cj ON cj.id = c.cajero_id
         WHERE $cond ORDER BY c.cerrado_en DESC LIMIT 200"
    );
    $st->execute($par);
    $cortes = $st->fetchAll();

    // Cajeros para el filtro (de los negocios visibles)
    $phT = implode(',', array_fill(0, count($idsVisibles), '?'));
    $st = $pdo->prepare("SELECT id, nombre FROM cajeros WHERE negocio_id IN ($phT) ORDER BY nombre");
    $st->execute($idsVisibles);
    $cajerosFiltro = $st->fetchAll();

    // Correos que no se pudieron procesar (aviso)
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM correos_corte
         WHERE estado = 'error' AND (negocio_id IN ($phT) OR negocio_id IS NULL)"
    );
    $st->execute($idsVisibles);
    $correosError = (int)$st->fetchColumn();
}

// Variación porcentual contra el período anterior
$variacion = null;
if ($resumenAnt && $resumenAnt['ventas'] > 0) {
    $variacion = (($resumen['ventas'] - $resumenAnt['ventas']) / $resumenAnt['ventas']) * 100;
}
?>
<?php cabecera_dashboard($usuario, 'ingresos', 'Ingresos'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Ingresos</h2>
            <a class="btn" href="corte_pegar.php">+ Registrar corte</a>
        </div>

        <?php if ($correosError > 0): ?>
        <div class="error">Hay <?= $correosError ?> correo(s) de corte que no se pudieron procesar.
            Puedes registrarlos a mano con "+ Registrar corte".</div>
        <?php endif; ?>

        <form class="filtros" method="get">
            <?php if (count($negocios) > 1): ?>
            <select name="negocio">
                <option value="0">Todos los negocios</option>
                <?php foreach ($negocios as $n): ?>
                    <option value="<?= (int)$n['id'] ?>" <?= $fNegocio === (int)$n['id'] ? 'selected' : '' ?>>
                        <?= h($n['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="date" name="desde" value="<?= h($fDesde) ?>">
            <input type="date" name="hasta" value="<?= h($fHasta) ?>">
            <select name="cajero">
                <option value="0">Todos los cajeros</option>
                <?php foreach ($cajerosFiltro as $cj): ?>
                    <option value="<?= (int)$cj['id'] ?>" <?= $fCajero === (int)$cj['id'] ? 'selected' : '' ?>>
                        <?= h($cj['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Filtrar</button>
        </form>

        <div class="grid-negocios grid-stats">
            <div class="panel">
                <div class="stat-label">Ventas del período</div>
                <div class="stat-valor"><?= clp($resumen['ventas']) ?: '$0' ?></div>
                <?php if ($variacion !== null): ?>
                <div class="stat-comp <?= $variacion >= 0 ? 'comp-sube' : 'comp-baja' ?>">
                    <?= ($variacion >= 0 ? '▲ +' : '▼ ') . number_format($variacion, 1, ',', '.') ?>%
                    vs período anterior
                </div>
                <?php endif; ?>
            </div>
            <div class="panel">
                <div class="stat-label">Efectivo</div>
                <div class="stat-valor"><?= clp($resumen['efectivo']) ?: '$0' ?></div>
            </div>
            <div class="panel">
                <div class="stat-label">Tarjeta + transferencia</div>
                <div class="stat-valor"><?= clp($resumen['tarjeta'] + $resumen['transferencia']) ?: '$0' ?></div>
            </div>
            <div class="panel">
                <div class="stat-label">Salidas de caja</div>
                <div class="stat-valor"><?= clp($resumen['salidas']) ?: '$0' ?></div>
            </div>
            <div class="panel">
                <div class="stat-label">Cortes</div>
                <div class="stat-valor"><?= (int)$resumen['n'] ?></div>
            </div>
        </div>

        <?php if (count($porDia) > 1): ?>
        <div class="panel bloque-sep">
            <h2>Ventas por día</h2>
            <?php
                $maxV = 0;
                foreach ($porDia as $p) $maxV = max($maxV, (float)$p['v']);
                $nB = count($porDia);
                $aB = max(8, min(48, intdiv(720, $nB) - 4));   // ancho de barra
                $w = ($aB + 4) * $nB + 10; $hG = 150;
            ?>
            <div class="grafico-scroll">
            <svg class="grafico-barras" viewBox="0 0 <?= $w ?> <?= $hG + 30 ?>" width="<?= $w ?>" height="<?= $hG + 30 ?>" role="img" aria-label="Ventas por día">
                <?php foreach ($porDia as $i => $p):
                    $v = (float)$p['v'];
                    $hB = $maxV > 0 ? max(2, round($v / $maxV * ($hG - 20))) : 2;
                    $x = 5 + $i * ($aB + 4);
                    $y = $hG - $hB;
                ?>
                <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $aB ?>" height="<?= $hB ?>" rx="2" class="barra">
                    <title><?= h(fecha_dmy($p['d'])) ?>: <?= clp($v) ?></title>
                </rect>
                <text x="<?= $x + $aB / 2 ?>" y="<?= $hG + 14 ?>" class="barra-fecha"
                      text-anchor="middle"><?= h(substr($p['d'], 8, 2)) ?></text>
                <?php endforeach; ?>
            </svg>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($porCajero): ?>
        <div class="panel bloque-sep">
            <h2>Por cajero</h2>
            <div class="tabla-wrap tabla-plana">
                <table>
                    <thead><tr>
                        <th>Cajero</th><th>Cortes</th><th>Ventas</th><th>Efectivo</th><th>Salidas</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($porCajero as $c): ?>
                        <tr>
                            <td><?= h($c['nombre'] ?: '—') ?>
                                <?php if ($c['usuario_web']): ?><span class="texto-sec">(<?= h($c['usuario_web']) ?>)</span><?php endif; ?>
                            </td>
                            <td><?= (int)$c['n'] ?></td>
                            <td><?= clp($c['ventas']) ?></td>
                            <td><?= clp($c['efectivo']) ?></td>
                            <td><?= clp($c['salidas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="tabla-wrap bloque-sep">
            <table>
                <thead><tr>
                    <th>Cierre</th>
                    <?php if (count($negocios) > 1): ?><th>Negocio</th><?php endif; ?>
                    <th>Caja</th><th>Cajero</th><th>Ventas</th><th>Efectivo</th>
                    <th>Tarjeta</th><th>Salidas</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$cortes): ?>
                    <tr><td class="vacio" colspan="9">No hay cortes en el período.
                        Los cortes llegan solos desde Eleventa, o regístralos con "+ Registrar corte".</td></tr>
                <?php else: foreach ($cortes as $c): ?>
                    <tr>
                        <td class="nowrap"><?= h(date('d-m-Y H:i', strtotime($c['cerrado_en']))) ?></td>
                        <?php if (count($negocios) > 1): ?><td><?= h($c['negocio']) ?></td><?php endif; ?>
                        <td><?= h($c['caja'] ?: '—') ?></td>
                        <td><?= h($c['cajero'] ?: '—') ?></td>
                        <td><?= clp($c['ventas_totales']) ?></td>
                        <td><?= clp($c['ventas_efectivo']) ?></td>
                        <td><?= clp($c['ventas_tarjeta']) ?></td>
                        <td><?= clp($c['salidas_caja']) ?></td>
                        <td class="nowrap"><a class="btn sm gris" href="corte.php?id=<?= (int)$c['id'] ?>">Ver</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
