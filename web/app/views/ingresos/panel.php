<?php
/**
 * Vista de app/Controllers/IngresosController::panel().
 * Variables disponibles: $usuario, $negocios, $mismoNegocio, $fNegocio, $fDesde,
 * $fHasta, $fCajero, $resumen, $variacion, $cortes, $porCajero, $porDia,
 * $cajerosFiltro, $pendientes, $correosError.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

$fmtFH = fn($v) => $v ? date('d-m-Y H:i', strtotime($v)) : '—';
?>
<?php cabecera_dashboard($usuario, 'ingresos', 'Ingresos'); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-azul">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('ingresos') ?></span>
                <h2>Ingresos</h2>
            </div>
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
                                <td class="nowrap"><?= e($fmtFH($p['recibido_en'])) ?></td>
                                <td><?= e($p['asunto'] ?: '(sin asunto)') ?></td>
                                <td class="texto-sec"><?= e($p['error']) ?></td>
                                <td class="nowrap">
                                    <form method="post" class="inline-form"
                                          onsubmit="return confirm('¿Descartar este correo? No es un corte válido.')">
                                        <?= campo_csrf() ?>
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
                                <?= e($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="campo">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= e($fDesde) ?>">
                </div>
                <div class="campo">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= e($fHasta) ?>">
                </div>
                <div class="campo">
                    <label>Cajero</label>
                    <select name="cajero">
                        <option value="0">Todos los cajeros</option>
                        <?php foreach ($cajerosFiltro as $cj): ?>
                            <option value="<?= (int)$cj['id'] ?>" <?= $fCajero === (int)$cj['id'] ? 'selected' : '' ?>>
                                <?= e($cj['nombre']) ?></option>
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
            <div class="stat-card">
                <span class="stat-label">Ventas del período</span>
                <span class="stat-valor valor-azul"><?= clp($resumen['ventas']) ?: '$0' ?></span>
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
            <div class="stat-card stat-centro">
                <span class="stat-label">Número de ventas</span>
                <span class="stat-valor"><?= number_format((int)$resumen['nventas'], 0, ',', '.') ?></span>
            </div>
        </div>

        <!-- Tabla de cortes: el contenido principal, siempre visible -->
        <div class="tabla-wrap tabla-cortes">
            <table>
                <thead><tr>
                    <th>Cierre</th>
                    <?php if (!$mismoNegocio): ?><th>Negocio</th><?php endif; ?>
                    <th>Caja</th><th class="col-cajero">Cajero</th>
                    <th class="col-num">Ventas</th><th class="col-num">Efectivo</th>
                    <th class="col-num">Tarjeta</th><th class="col-num">Salidas</th>
                </tr></thead>
                <tbody>
                <?php if (!$cortes): ?>
                    <tr><td class="vacio" colspan="<?= $mismoNegocio ? 7 : 8 ?>">No hay cortes en el período seleccionado.<br>
                        Los cortes llegan solos desde Eleventa, o puedes registrarlos con "+ Registrar corte".</td></tr>
                <?php else: foreach ($cortes as $c): ?>
                    <tr class="fila-click" onclick="location.href='corte.php?id=<?= (int)$c['id'] ?>'">
                        <td class="nowrap col-cierre">
                            <span class="f-fecha"><?= e(date('d-m-Y', strtotime($c['cerrado_en']))) ?></span><span class="f-hora"><?= e(date('H:i', strtotime($c['cerrado_en']))) ?></span>
                        </td>
                        <?php if (!$mismoNegocio): ?><td><?= e($c['negocio']) ?></td><?php endif; ?>
                        <td><?= e($c['caja'] ?: '—') ?></td>
                        <td class="col-cajero"><?= e($c['cajero'] ?: '—') ?></td>
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
                        <title><?= e(fecha_dmy($p['d'])) ?>: <?= clp($v) ?></title>
                    </rect>
                    <text x="<?= $x + $aB / 2 ?>" y="<?= $y - 5 ?>" class="barra-monto" text-anchor="middle"><?= number_format($v / 1000, 0, ',', '.') ?>k</text>
                    <text x="<?= $x + $aB / 2 ?>" y="<?= $hG + 16 ?>" class="barra-fecha" text-anchor="middle"><?= e(substr($p['d'], 8, 2)) . '/' . e(substr($p['d'], 5, 2)) ?></text>
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
                            <td><?= e($c['nombre'] ?: '—') ?>
                                <?php if ($c['usuario_web']): ?><span class="texto-sec">(<?= e($c['usuario_web']) ?>)</span><?php endif; ?>
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
