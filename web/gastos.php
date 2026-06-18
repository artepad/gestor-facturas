<?php
/**
 * Módulo Gastos: dashboard de los gastos operacionales del negocio (agua, luz,
 * gas, sueldos, arriendo...). Solo administrador. Tarjetas resumen del período,
 * desglose por categoría, comparación contra las ventas (cortes) y la tabla de
 * gastos. Acotado siempre a los negocios visibles del usuario.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/gastos.php';

$usuario = exigir_permiso('gastos');
$pdo = obtener_pdo();

$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
$mismoNegocio = count($negocios) === 1;

// --- Filtros ---
$fNegocio = (int)($_GET['negocio'] ?? 0);
if ($fNegocio && !in_array($fNegocio, $idsVisibles, true)) $fNegocio = 0;
$hoy    = date('Y-m-d');
$fDesde = $_GET['desde'] ?? date('Y-m-01');
$fHasta = $_GET['hasta'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)) $fDesde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)) $fHasta = $hoy;
$fCategoria = (int)($_GET['categoria'] ?? 0);

$ids = $fNegocio ? [$fNegocio] : $idsVisibles;

// --- Acción: generar los gastos fijos del mes ---
$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'generar_fijos') {
    $creados = 0;
    foreach ($ids as $nid) {
        $creados += registrar_gastos_fijos($pdo, $nid, date('Y-m-01'), (int)$usuario['id']);
    }
    $flash = $creados > 0
        ? "Se generaron $creados gasto(s) fijo(s) de este mes."
        : 'No había gastos fijos pendientes de generar este mes.';
}

// --- Datos del período ---
$totalGastos = 0; $nGastos = 0; $porCategoria = []; $gastos = []; $ventasPeriodo = 0;
$categorias = categorias_gasto($pdo, false);

if ($ids) {
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $cond = "g.negocio_id IN ($ph) AND g.fecha BETWEEN ? AND ?";
    $par  = [...$ids, $fDesde, $fHasta];
    if ($fCategoria) { $cond .= " AND g.categoria_id = ?"; $par[] = $fCategoria; }

    // Totales del período (con el filtro de categoría aplicado)
    $st = $pdo->prepare("SELECT COALESCE(SUM(g.monto),0), COUNT(*) FROM gastos g WHERE $cond");
    $st->execute($par);
    [$totalGastos, $nGastos] = array_values($st->fetch(PDO::FETCH_NUM));
    $totalGastos = (float)$totalGastos; $nGastos = (int)$nGastos;

    // Desglose por categoría (del período completo, sin el filtro de categoría)
    $porCategoria = gastos_por_categoria($pdo, $ids, $fDesde, $fHasta);

    // Gasto del período anterior equivalente (mismos días hacia atrás), para la variación
    $diasP    = max(1, (int)((strtotime($fHasta) - strtotime($fDesde)) / 86400) + 1);
    $antHasta = date('Y-m-d', strtotime("$fDesde -1 day"));
    $antDesde = date('Y-m-d', strtotime("$antHasta -" . ($diasP - 1) . " day"));
    $condAnt  = "g.negocio_id IN ($ph) AND g.fecha BETWEEN ? AND ?";
    $parAnt   = [...$ids, $antDesde, $antHasta];
    if ($fCategoria) { $condAnt .= " AND g.categoria_id = ?"; $parAnt[] = $fCategoria; }
    $st = $pdo->prepare("SELECT COALESCE(SUM(g.monto),0) FROM gastos g WHERE $condAnt");
    $st->execute($parAnt);
    $gastosAnt = (float)$st->fetchColumn();

    // Tabla de gastos
    $st = $pdo->prepare(
        "SELECT g.*, cg.nombre AS categoria, n.nombre AS negocio
         FROM gastos g
         JOIN categorias_gasto cg ON cg.id = g.categoria_id
         JOIN negocios n ON n.id = g.negocio_id
         WHERE $cond ORDER BY g.fecha DESC, g.id DESC LIMIT 300"
    );
    $st->execute($par);
    $gastos = $st->fetchAll();
}

$dias = max(1, (int)((strtotime($fHasta) - strtotime($fDesde)) / 86400) + 1);
$promedioDiario = $totalGastos / $dias;
$varGastos = ($gastosAnt ?? 0) > 0 ? (($totalGastos - $gastosAnt) / $gastosAnt * 100) : null;
$topCat = $porCategoria[0] ?? null;
$maxCat = $porCategoria ? max(array_map(fn($c) => (float)$c['total'], $porCategoria)) : 0;
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
                <form method="post" class="inline-form"
                      onsubmit="return confirm('¿Generar los gastos fijos de este mes? No se duplican los ya creados.')">
                    <input type="hidden" name="accion" value="generar_fijos">
                    <button class="btn gris" type="submit">Generar gastos fijos del mes</button>
                </form>
                <a class="btn gris" href="gastos_fijos.php">Gastos fijos</a>
                <a class="btn gris" href="categorias_gasto.php">Categorías</a>
            </div>
        </div>

        <?php if ($flash): ?><div class="aviso-ok"><?= h($flash) ?></div><?php endif; ?>

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
                    <label>Categoría</label>
                    <select name="categoria">
                        <option value="0">Todas</option>
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $fCategoria === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= h($c['nombre']) ?></option>
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
                    <span class="stat-comp texto-tenue"><?= h($topCat['nombre']) ?></span>
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
                        <td class="nowrap"><?= h(fecha_dmy($g['fecha'])) ?></td>
                        <td><?= h($g['categoria']) ?><?= $g['gasto_fijo_id'] ? ' <span class="mini-tag">fijo</span>' : '' ?></td>
                        <td class="col-ocultar-movil"><?= h($g['descripcion']) ?: '—' ?></td>
                        <?php if (!$mismoNegocio): ?><td class="col-ocultar-movil"><?= h($g['negocio']) ?></td><?php endif; ?>
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
                        <span class="cat-nombre"><?= h($c['nombre']) ?></span>
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
