<?php
/**
 * Módulo Ingresos: dashboard de los cortes de caja que envía Eleventa.
 * Filtros por negocio/fechas/cajero, tarjetas resumen con comparativa contra
 * el período anterior, evolución diaria, rendimiento por cajero y la tabla
 * de cortes. Lo secundario (gráfico, cajeros, correos sin procesar) va en
 * secciones plegables para mantener la pantalla limpia.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('ingresos');
$pdo = obtener_pdo();

$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

// --- Acción: descartar un correo que no se pudo procesar (no es un corte) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'descartar') {
    $cid = (int)($_POST['correo_id'] ?? 0);
    $st = $pdo->prepare("SELECT negocio_id FROM correos_corte WHERE id = ?");
    $st->execute([$cid]);
    $row = $st->fetch();
    // Solo si es de un negocio visible o aún sin asignar
    if ($row && ($row['negocio_id'] === null || in_array((int)$row['negocio_id'], $idsVisibles, true))) {
        $pdo->prepare("UPDATE correos_corte SET estado = 'descartado' WHERE id = ?")->execute([$cid]);
    }
    header('Location: ingresos.php');
    exit;
}

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
$resumen = ['ventas' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0,
            'salidas' => 0, 'n' => 0];
$resumenAnt = null;
$cortes = []; $porCajero = []; $porDia = []; $cajerosFiltro = [];
$pendientes = []; $correosError = 0;

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

    // Correos que no se pudieron procesar (para gestionarlos)
    $st = $pdo->prepare(
        "SELECT id, asunto, destinatario, recibido_en, origen, error
         FROM correos_corte
         WHERE estado = 'error' AND (negocio_id IS NULL OR negocio_id IN ($phT))
         ORDER BY id DESC LIMIT 50"
    );
    $st->execute($idsVisibles);
    $pendientes = $st->fetchAll();
    $correosError = count($pendientes);
}

// Variación porcentual contra el período anterior
$variacion = null;
if ($resumenAnt && $resumenAnt['ventas'] > 0) {
    $variacion = (($resumen['ventas'] - $resumenAnt['ventas']) / $resumenAnt['ventas']) * 100;
}
$mismoNegocio = count($negocios) === 1;
$fmtFH = fn($v) => $v ? date('d-m-Y H:i', strtotime($v)) : '—';
?>
<?php cabecera_dashboard($usuario, 'ingresos', 'Ingresos'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Ingresos</h2>
            <a class="btn" href="corte_pegar.php">+ Registrar corte</a>
        </div>

        <!-- Correos sin procesar: aviso plegable. Permanece mientras haya
             correos en estado 'error'; al descartarlos o registrarlos, baja a 0
             y desaparece solo. -->
        <?php if ($correosError > 0): ?>
        <details class="bloque bloque-aviso bloque-sep">
            <summary>
                <?= icono('reloj') ?>
                <?= $correosError ?> correo<?= $correosError === 1 ? '' : 's' ?> sin procesar
                <span class="aviso-hint">— requieren tu atención</span>
            </summary>
            <div class="bloque-cuerpo">
                <p class="texto-ayuda">Llegaron a la casilla pero no se pudieron leer como un corte
                    (por ejemplo, correos que no son cortes, o de un negocio sin identificar).
                    Si no son cortes, <strong>descártalos</strong>; si sí lo son, regístralos a mano.</p>
                <div class="tabla-wrap tabla-plana">
                    <table>
                        <thead><tr><th>Recibido</th><th>Asunto</th><th>Motivo</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($pendientes as $p): ?>
                            <tr>
                                <td class="nowrap"><?= h($fmtFH($p['recibido_en'])) ?></td>
                                <td><?= h($p['asunto'] ?: '(sin asunto)') ?></td>
                                <td class="texto-sec"><?= h($p['error']) ?></td>
                                <td class="nowrap">
                                    <form method="post" class="inline-form"
                                          onsubmit="return confirm('¿Descartar este correo? No es un corte válido.')">
                                        <input type="hidden" name="accion" value="descartar">
                                        <input type="hidden" name="correo_id" value="<?= (int)$p['id'] ?>">
                                        <button class="btn sm gris" type="submit">Descartar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
        <?php endif; ?>

        <!-- Filtros (mismo patrón visual que el resto del sitio) -->
        <div class="panel">
            <h2>Filtros</h2>
            <form class="filtros" method="get">
                <?php if (!$mismoNegocio): ?>
                <div class="campo">
                    <label>Negocio</label>
                    <select name="negocio">
                        <option value="0">Todos los negocios</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= $fNegocio === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= h($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="campo">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= h($fDesde) ?>">
                </div>
                <div class="campo">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= h($fHasta) ?>">
                </div>
                <div class="campo">
                    <label>Cajero</label>
                    <select name="cajero">
                        <option value="0">Todos los cajeros</option>
                        <?php foreach ($cajerosFiltro as $cj): ?>
                            <option value="<?= (int)$cj['id'] ?>" <?= $fCajero === (int)$cj['id'] ? 'selected' : '' ?>>
                                <?= h($cj['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Filtrar</button>
                    <a class="btn gris" href="ingresos.php">Limpiar</a>
                </div>
            </form>
        </div>

        <!-- Lo más importante: resumen del período -->
        <div class="ingresos-stats">
            <div class="stat-card destacado">
                <span class="stat-label">Ventas del período</span>
                <span class="stat-valor"><?= clp($resumen['ventas']) ?: '$0' ?></span>
                <?php if ($variacion !== null): ?>
                <span class="stat-comp <?= $variacion >= 0 ? 'comp-sube' : 'comp-baja' ?>">
                    <?= ($variacion >= 0 ? '▲ +' : '▼ ') . number_format($variacion, 1, ',', '.') ?>%
                    <span class="texto-tenue">vs. período anterior</span>
                </span>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <span class="stat-label">Efectivo</span>
                <span class="stat-valor"><?= clp($resumen['efectivo']) ?: '$0' ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Tarjeta + transferencia</span>
                <span class="stat-valor"><?= clp($resumen['tarjeta'] + $resumen['transferencia']) ?: '$0' ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Salidas de caja</span>
                <span class="stat-valor"><?= clp($resumen['salidas']) ?: '$0' ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Cortes</span>
                <span class="stat-valor"><?= (int)$resumen['n'] ?></span>
            </div>
        </div>

        <!-- Tabla de cortes: el contenido principal, siempre visible -->
        <div class="tabla-wrap">
            <table>
                <thead><tr>
                    <th>Cierre</th>
                    <?php if (!$mismoNegocio): ?><th>Negocio</th><?php endif; ?>
                    <th>Caja</th><th>Cajero</th>
                    <th class="col-num">Ventas</th><th class="col-num">Efectivo</th>
                    <th class="col-num">Tarjeta</th><th class="col-num">Salidas</th>
                </tr></thead>
                <tbody>
                <?php if (!$cortes): ?>
                    <tr><td class="vacio" colspan="<?= $mismoNegocio ? 7 : 8 ?>">No hay cortes en el período seleccionado.<br>
                        Los cortes llegan solos desde Eleventa, o puedes registrarlos con "+ Registrar corte".</td></tr>
                <?php else: foreach ($cortes as $c): ?>
                    <tr class="fila-click" onclick="location.href='corte.php?id=<?= (int)$c['id'] ?>'">
                        <td class="nowrap"><?= h(date('d-m-Y H:i', strtotime($c['cerrado_en']))) ?></td>
                        <?php if (!$mismoNegocio): ?><td><?= h($c['negocio']) ?></td><?php endif; ?>
                        <td><?= h($c['caja'] ?: '—') ?></td>
                        <td><?= h($c['cajero'] ?: '—') ?></td>
                        <td class="col-num"><?= clp($c['ventas_totales']) ?></td>
                        <td class="col-num"><?= clp($c['ventas_efectivo']) ?></td>
                        <td class="col-num"><?= clp($c['ventas_tarjeta']) ?></td>
                        <td class="col-num"><?= clp($c['salidas_caja']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Análisis secundario: plegable para no recargar la pantalla -->
        <?php if (count($porDia) > 1): ?>
        <details class="bloque bloque-sep" open>
            <summary>Ventas por día</summary>
            <div class="bloque-cuerpo">
                <?php
                    $maxV = 0;
                    foreach ($porDia as $p) $maxV = max($maxV, (float)$p['v']);
                    $nB = count($porDia);
                    $aB = max(10, min(46, intdiv(760, $nB) - 6));   // ancho de barra
                    $paso = $aB + 10; $w = $paso * $nB + 12; $hG = 150;
                ?>
                <div class="grafico-scroll">
                <svg class="grafico-barras" viewBox="0 0 <?= $w ?> <?= $hG + 34 ?>" width="<?= $w ?>" height="<?= $hG + 34 ?>" role="img" aria-label="Ventas por día">
                    <?php foreach ($porDia as $i => $p):
                        $v = (float)$p['v'];
                        $hB = $maxV > 0 ? max(3, round($v / $maxV * ($hG - 24))) : 3;
                        $x = 6 + $i * $paso;
                        $y = $hG - $hB;
                    ?>
                    <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $aB ?>" height="<?= $hB ?>" rx="3" class="barra">
                        <title><?= h(fecha_dmy($p['d'])) ?>: <?= clp($v) ?></title>
                    </rect>
                    <text x="<?= $x + $aB / 2 ?>" y="<?= $y - 5 ?>" class="barra-monto" text-anchor="middle"><?= number_format($v / 1000, 0, ',', '.') ?>k</text>
                    <text x="<?= $x + $aB / 2 ?>" y="<?= $hG + 16 ?>" class="barra-fecha" text-anchor="middle"><?= h(substr($p['d'], 8, 2)) . '/' . h(substr($p['d'], 5, 2)) ?></text>
                    <?php endforeach; ?>
                </svg>
                </div>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($porCajero): ?>
        <details class="bloque bloque-sep">
            <summary>Rendimiento por cajero (<?= count($porCajero) ?>)</summary>
            <div class="tabla-wrap tabla-plana">
                <table>
                    <thead><tr>
                        <th>Cajero</th><th class="col-num">Cortes</th><th class="col-num">Ventas</th>
                        <th class="col-num">Efectivo</th><th class="col-num">Salidas</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($porCajero as $c): ?>
                        <tr>
                            <td><?= h($c['nombre'] ?: '—') ?>
                                <?php if ($c['usuario_web']): ?><span class="texto-sec">(<?= h($c['usuario_web']) ?>)</span><?php endif; ?>
                            </td>
                            <td class="col-num"><?= (int)$c['n'] ?></td>
                            <td class="col-num"><?= clp($c['ventas']) ?></td>
                            <td class="col-num"><?= clp($c['efectivo']) ?></td>
                            <td class="col-num"><?= clp($c['salidas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
