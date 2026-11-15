<?php
/**
 * Vista de app/Controllers/IngresosController::panel().
 * Variables disponibles: $usuario, $negocios, $mismoNegocio, $fNegocio, $fDesde,
 * $fHasta, $fCajero, $cortes, $cajerosFiltro, $viendo.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'ingresos', 'Ingresos'); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-azul">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('ingresos') ?></span>
                <h2>Ingresos</h2>
            </div>
            <div class="cab-modulo-der">
                <span class="badge-negocio">
                    <?= icono('negocios') ?>
                    Viendo: <strong><?= e($viendo) ?></strong>
                </span>
            </div>
        </div>

        <!-- Filtros (plegable: mismo patrón que Facturas/Fiados/Gastos) -->
        <?php // $fDesde/$fHasta siempre vienen con un valor por defecto (mes actual) desde el
        // Controlador, así que para saber si el usuario realmente filtró hay que mirar el GET crudo.
        $filtrosActivos = (isset($_GET['desde']) || isset($_GET['hasta']) || $fNegocio !== 0 || $fCajero !== 0); ?>
        <div class="panel panel-filtros<?= $filtrosActivos ? '' : ' colapsado' ?>" id="panelFiltros">
            <button type="button" class="panel-filtros-cab" id="btnToggleFiltros"
                    aria-expanded="<?= $filtrosActivos ? 'true' : 'false' ?>">
                <h2>Filtros</h2>
                <span class="chevron"><?= icono('chevron') ?></span>
            </button>
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
        <script>
          // Panel de Filtros plegable: mismo patrón que panel_facturas.php / fiados.php / gastos.php.
          (function () {
            var panel = document.getElementById('panelFiltros');
            var btn = document.getElementById('btnToggleFiltros');
            if (!panel || !btn) return;
            btn.addEventListener('click', function () {
              var abierto = !panel.classList.contains('colapsado');
              panel.classList.toggle('colapsado', abierto);
              btn.setAttribute('aria-expanded', abierto ? 'false' : 'true');
            });
          })();
        </script>

        <div class="resumen"><?= count($cortes) ?> corte(s)</div>

        <!-- Tabla de cortes: el contenido principal, siempre visible -->
        <div class="tabla-wrap tabla-cortes">
            <table>
                <thead><tr>
                    <th>Cierre</th>
                    <?php if (!$mismoNegocio): ?><th>Negocio</th><?php endif; ?>
                    <th>Caja</th><th class="col-cajero">Cajero</th>
                    <th class="col-num col-ventas">Ventas</th><th class="col-num">Efectivo</th>
                    <th class="col-num">Tarjeta</th><th class="col-num">Salidas</th>
                </tr></thead>
                <tbody>
                <?php if (!$cortes): ?>
                    <tr><td class="vacio" colspan="<?= $mismoNegocio ? 7 : 8 ?>">No hay cortes en el período seleccionado.<br>
                        El corte se genera solo al cerrar la caja en el POS.</td></tr>
                <?php else: foreach ($cortes as $c): ?>
                    <tr class="fila-click" onclick="location.href='corte.php?id=<?= (int)$c['id'] ?>'">
                        <td class="nowrap col-cierre">
                            <span class="f-fecha"><?= e(date('d-m-Y', strtotime($c['cerrado_en']))) ?></span><span class="f-hora"><?= e(date('H:i', strtotime($c['cerrado_en']))) ?></span>
                        </td>
                        <?php if (!$mismoNegocio): ?><td><?= e($c['negocio']) ?></td><?php endif; ?>
                        <td><?= e($c['caja'] ?: '—') ?></td>
                        <td class="col-cajero"><?= e($c['cajero'] ?: '—') ?></td>
                        <td class="col-num col-ventas"><?= clp($c['ventas_totales']) ?></td>
                        <td class="col-num col-efectivo"><?= clp($c['ventas_efectivo']) ?></td>
                        <td class="col-num col-tarjeta"><?= clp($c['ventas_tarjeta']) ?></td>
                        <td class="col-num col-salidas"><?= clp($c['salidas_caja']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
