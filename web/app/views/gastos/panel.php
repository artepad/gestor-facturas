<?php
/**
 * Vista de app/Controllers/GastosController::panel().
 * Variables disponibles: $usuario, $negocios, $mismoNegocio, $fNegocio, $fDesde,
 * $fHasta, $fCategoria, $categorias, $flash, $totalGastos, $nGastos, $porCategoria,
 * $gastos, $promedioDiario, $varGastos, $topCat, $maxCat.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'gastos', 'Gastos'); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-rojo">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('gastos') ?></span>
                <h2>Gastos</h2>
            </div>
            <div class="acciones-rapidas">
                <a class="btn" href="gasto_form.php">+ Registrar gasto</a>
                <details class="menu-acciones">
                    <summary class="btn gris">Más ▾</summary>
                    <div class="menu-pop">
                        <form method="post"
                              onsubmit="return confirm('¿Generar los gastos fijos de este mes? No se duplican los ya creados.')">
                            <?= campo_csrf() ?>
                            <input type="hidden" name="accion" value="generar_fijos">
                            <button class="menu-item" type="submit">Generar gastos fijos del mes</button>
                        </form>
                        <a class="menu-item" href="gastos_fijos.php">Gastos fijos</a>
                        <a class="menu-item" href="categorias_gasto.php">Categorías</a>
                    </div>
                </details>
            </div>
        </div>

        <?php if ($flash): ?><div class="aviso-ok"><?= e($flash) ?></div><?php endif; ?>

        <!-- Filtros -->
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
                    <label>Categoría</label>
                    <select name="categoria">
                        <option value="0">Todas</option>
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $fCategoria === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Filtrar</button>
                    <a class="btn gris" href="gastos.php">Limpiar</a>
                </div>
            </form>
        </div>

        <!-- Resumen del período -->
        <div class="ingresos-stats stats-compactas">
            <div class="stat-card stat-destacado">
                <span class="stat-label">Gastos del período</span>
                <span class="stat-valor valor-rojo"><?= clp($totalGastos) ?: '$0' ?></span>
                <span class="stat-comp texto-tenue"><?= $nGastos ?> gasto(s)</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Promedio diario</span>
                <span class="stat-valor"><?= clp($promedioDiario) ?: '$0' ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Variación de gastos</span>
                <?php if ($varGastos !== null): $sube = $varGastos > 0; $pct = number_format(abs($varGastos), 1, ',', '.'); ?>
                    <span class="stat-valor <?= $sube ? 'valor-rojo' : 'valor-verde' ?>"><?= ($sube ? '▲ +' : '▼ −') . $pct ?>%</span>
                    <span class="stat-comp texto-tenue">vs. período anterior</span>
                <?php else: ?>
                    <span class="stat-valor">—</span>
                    <span class="stat-comp texto-tenue">sin datos previos</span>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <span class="stat-label">Mayor gasto por categoría</span>
                <?php if ($topCat): ?>
                    <span class="stat-valor valor-rojo"><?= clp($topCat['total']) ?></span>
                    <span class="stat-comp texto-tenue"><?= e($topCat['nombre']) ?></span>
                <?php else: ?>
                    <span class="stat-valor">$0</span>
                    <span class="stat-comp texto-tenue">sin gastos</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabla de gastos -->
        <div class="tabla-wrap">
            <table>
                <thead><tr>
                    <th>Fecha</th>
                    <th>Categoría</th>
                    <th class="col-ocultar-movil">Descripción</th>
                    <?php if (!$mismoNegocio): ?><th class="col-ocultar-movil">Negocio</th><?php endif; ?>
                    <th class="monto-col">Monto</th>
                </tr></thead>
                <tbody>
                <?php if (!$gastos): ?>
                    <tr><td class="vacio" colspan="5">No hay gastos en el período. Registra el primero con "+ Registrar gasto".</td></tr>
                <?php else: foreach ($gastos as $g): ?>
                    <tr class="fila-click" onclick="location.href='gasto_form.php?id=<?= (int)$g['id'] ?>'">
                        <td class="nowrap"><?= e(fecha_dmy($g['fecha'])) ?></td>
                        <td><?= e($g['categoria']) ?><?= $g['gasto_fijo_id'] ? ' <span class="mini-tag">fijo</span>' : '' ?></td>
                        <td class="col-ocultar-movil"><?= e($g['descripcion']) ?: '—' ?></td>
                        <?php if (!$mismoNegocio): ?><td class="col-ocultar-movil"><?= e($g['negocio']) ?></td><?php endif; ?>
                        <td class="monto-col valor-rojo"><?= clp($g['monto']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Desglose por categoría (colapsable, oculto por defecto) -->
        <?php if ($porCategoria): ?>
        <details class="bloque bloque-sep">
            <summary>Por categoría</summary>
            <div class="bloque-cuerpo">
                <div class="cat-barras">
                    <?php foreach ($porCategoria as $c): $pct = $maxCat > 0 ? round((float)$c['total'] / $maxCat * 100) : 0; ?>
                    <div class="cat-fila">
                        <span class="cat-nombre"><?= e($c['nombre']) ?></span>
                        <span class="cat-barra"><span class="cat-relleno" style="width: <?= $pct ?>%"></span></span>
                        <span class="cat-monto"><?= clp($c['total']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
