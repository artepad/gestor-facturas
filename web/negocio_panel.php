<?php
/**
 * Panel de análisis de un negocio. Se abre al hacer clic en una tarjeta de
 * "Estado por negocio" del Home. Reúne ventas (cortes), compras (facturas),
 * gastos, fiados y el balance del período, con gráficos. Cada sección se muestra
 * según el permiso del rol; el acceso al negocio se valida con puede_ver_negocio.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/gastos.php';

$usuario = exigir_login();
$pdo = obtener_pdo();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id || !puede_ver_negocio($usuario, $id)) {
    header('Location: home.php');
    exit;
}
$stn = $pdo->prepare("SELECT * FROM negocios WHERE id = ?");
$stn->execute([$id]);
$negocio = $stn->fetch();
if (!$negocio) { header('Location: home.php'); exit; }

$verIngresos = puede($usuario, 'ingresos');
$verFacturas = puede($usuario, 'facturas');
$verGastos   = puede($usuario, 'gastos');
$verFiados   = puede($usuario, 'fiados');

// --- Período (mes actual por defecto; con selector) ---
$hoy    = date('Y-m-d');
$fDesde = $_GET['desde'] ?? date('Y-m-01');
$fHasta = $_GET['hasta'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)) $fDesde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)) $fHasta = $hoy;
$inicioMesAnt = date('Y-m-01', strtotime('first day of last month'));
$finMesAnt    = date('Y-m-t',  strtotime('last day of last month'));

// --- Ventas (cortes) ---
$ventas = ['total' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0,
           'credito' => 0, 'vales' => 0, 'salidas' => 0, 'nventas' => 0];
$porDia = []; $porDepto = [];
if ($verIngresos) {
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(ventas_totales),0), COALESCE(SUM(ventas_efectivo),0),
                COALESCE(SUM(ventas_tarjeta),0), COALESCE(SUM(ventas_transferencia),0),
                COALESCE(SUM(ventas_credito),0), COALESCE(SUM(ventas_vales),0),
                COALESCE(SUM(salidas_caja),0), COALESCE(SUM(numero_ventas),0)
         FROM cortes WHERE negocio_id = ? AND DATE(cerrado_en) BETWEEN ? AND ?"
    );
    $st->execute([$id, $fDesde, $fHasta]);
    [$ventas['total'], $ventas['efectivo'], $ventas['tarjeta'], $ventas['transferencia'],
     $ventas['credito'], $ventas['vales'], $ventas['salidas'], $ventas['nventas']] =
        array_map('floatval', array_values($st->fetch(PDO::FETCH_NUM)));
    $ventas['nventas'] = (int)$ventas['nventas'];

    $st = $pdo->prepare(
        "SELECT DATE(cerrado_en) d, COALESCE(SUM(ventas_totales),0) v
         FROM cortes WHERE negocio_id = ? AND DATE(cerrado_en) BETWEEN ? AND ?
         GROUP BY DATE(cerrado_en) ORDER BY d"
    );
    $st->execute([$id, $fDesde, $fHasta]);
    $porDia = $st->fetchAll();

    $st = $pdo->prepare(
        "SELECT cd.departamento, COALESCE(SUM(cd.monto),0) total
         FROM corte_departamentos cd JOIN cortes c ON c.id = cd.corte_id
         WHERE c.negocio_id = ? AND DATE(c.cerrado_en) BETWEEN ? AND ?
         GROUP BY cd.departamento ORDER BY total DESC LIMIT 8"
    );
    $st->execute([$id, $fDesde, $fHasta]);
    $porDepto = $st->fetchAll();
}

// --- Compras (facturas) + productos más comprados ---
$compras = 0; $nFacturas = 0; $topComprados = [];
if ($verFacturas) {
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(total),0), COUNT(*) FROM facturas
         WHERE negocio_id = ? AND eliminada_en IS NULL AND fecha BETWEEN ? AND ?"
    );
    $st->execute([$id, $fDesde, $fHasta]);
    [$compras, $nFacturas] = array_values($st->fetch(PDO::FETCH_NUM));
    $compras = (float)$compras; $nFacturas = (int)$nFacturas;

    $st = $pdo->prepare(
        "SELECT df.descripcion, COALESCE(SUM(df.monto),0) total
         FROM detalle_factura df JOIN facturas f ON f.id = df.factura_id
         WHERE f.negocio_id = ? AND f.eliminada_en IS NULL AND f.fecha BETWEEN ? AND ?
           AND df.descripcion IS NOT NULL AND df.descripcion <> ''
         GROUP BY df.descripcion ORDER BY total DESC LIMIT 8"
    );
    $st->execute([$id, $fDesde, $fHasta]);
    $topComprados = $st->fetchAll();
}

// --- Gastos (total + por categoría) ---
$gastosTotal = 0; $gastosCat = [];
if ($verGastos) {
    $gastosTotal = gastos_total($pdo, [$id], $fDesde, $fHasta);
    $gastosCat   = gastos_por_categoria($pdo, [$id], $fDesde, $fHasta);
}

// --- Fiados (deuda vigente del negocio; no depende del período) ---
$deuda = 0;
if ($verFiados) {
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(GREATEST(0,
              (SELECT COALESCE(SUM(f.monto),0) FROM fiados f WHERE f.cliente_id = c.id)
            - (SELECT COALESCE(SUM(a.monto),0) FROM abonos a WHERE a.cliente_id = c.id))),0)
         FROM clientes c WHERE c.negocio_id = ? AND c.activo = 1"
    );
    $st->execute([$id]);
    $deuda = (float)$st->fetchColumn();
}

$balance = $ventas['total'] - $compras - $gastosTotal;

// Hero: resultado del período (protagonista). Necesita ventas y gastos.
$hero = null;
if ($verIngresos && $verGastos) {
    $hero = ['titulo' => 'Resultado del período', 'valor' => clp($balance) ?: '$0',
             'signo' => $balance >= 0 ? '+ ' : '− ',
             'color' => $balance >= 0 ? 'valor-verde' : 'valor-rojo',
             'sub' => 'ventas − compras − gastos'];
} elseif ($verIngresos) {
    $hero = ['titulo' => 'Ventas del período', 'valor' => clp($ventas['total']) ?: '$0',
             'signo' => '', 'color' => 'valor-azul', 'sub' => $ventas['nventas'] . ' venta(s)'];
} elseif ($verGastos) {
    $hero = ['titulo' => 'Gastos del período', 'valor' => clp($gastosTotal) ?: '$0',
             'signo' => '', 'color' => 'valor-rojo', 'sub' => 'total gastado'];
}
// Para el "balance" del hero, signo solo cuando aplica
if ($hero && abs($balance) === 0.0) $hero['signo'] = '';

// KPIs (cada uno gateado por permiso): [label, valor, color-valor, color-filo, icono]
$kpis = [];
if ($verIngresos) $kpis[] = ['Ventas', clp($ventas['total']) ?: '$0', 'valor-azul', 'k-azul', 'ingresos'];
if ($verFacturas) $kpis[] = ['Compras', clp($compras) ?: '$0', 'valor-naranjo', 'k-naranjo', 'facturas'];
if ($verGastos)   $kpis[] = ['Gastos', clp($gastosTotal) ?: '$0', 'valor-rojo', 'k-rojo', 'gastos'];
if ($verIngresos && $verGastos) $kpis[] = ['Balance', clp($balance) ?: '$0', $balance >= 0 ? 'valor-verde' : 'valor-rojo', $balance >= 0 ? 'k-verde' : 'k-rojo', 'ingresos'];
if ($verFiados)   $kpis[] = ['Por cobrar', clp($deuda) ?: '$0', $deuda > 0 ? 'valor-rojo' : '', 'k-morado', 'fiados'];
if ($verIngresos) $kpis[] = ['N° de ventas', number_format($ventas['nventas'], 0, ',', '.'), '', 'k-gris', 'reloj'];

// ¿Qué chip de período está activo?
$esEsteMes = ($fDesde === date('Y-m-01') && $fHasta === $hoy);
$esMesAnt  = ($fDesde === $inicioMesAnt && $fHasta === $finMesAnt);
$esPersonalizado = !$esEsteMes && !$esMesAnt;

// Métodos de pago (solo los que tengan monto)
$metodos = [];
if ($verIngresos) {
    foreach ([['Efectivo', $ventas['efectivo']], ['Tarjeta', $ventas['tarjeta']],
              ['Transferencia', $ventas['transferencia']], ['Crédito', $ventas['credito']],
              ['Vales', $ventas['vales']]] as $m) {
        if ($m[1] > 0) $metodos[] = $m;
    }
}

// Helper para el ancho de barras horizontales
$pctDe = fn(float $v, float $max) => $max > 0 ? round($v / $max * 100) : 0;
?>
<?php cabecera_dashboard($usuario, 'home', $negocio['nombre']); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-azul">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('negocios') ?></span>
                <h2><?= h($negocio['nombre']) ?></h2>
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
                        <input type="date" name="desde" value="<?= h($fDesde) ?>"></div>
                    <div class="campo"><label>Hasta</label>
                        <input type="date" name="hasta" value="<?= h($fHasta) ?>"></div>
                    <button class="btn" type="submit">Aplicar</button>
                </form>
            </details>
        </div>

        <!-- Hero: resultado del período -->
        <?php if ($hero): ?>
        <div class="np-hero">
            <div>
                <div class="np-hero-lbl"><?= h($hero['titulo']) ?> · <?= h(fecha_dmy($fDesde)) ?> a <?= h(fecha_dmy($fHasta)) ?></div>
                <div class="np-hero-val <?= h($hero['color']) ?>"><?= h($hero['signo'] . $hero['valor']) ?></div>
                <div class="np-hero-sub"><?= h($hero['sub']) ?></div>
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
            <div class="np-kpi <?= h($k[3]) ?>">
                <span class="np-kpi-lbl"><?= icono($k[4]) ?> <?= h($k[0]) ?></span>
                <span class="np-kpi-val <?= h($k[2]) ?>"><?= h($k[1]) ?></span>
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
                    <title><?= h(fecha_dmy($p['d'])) ?>: <?= clp($v) ?></title>
                </rect>
                <text x="<?= $x + $aB / 2 ?>" y="<?= $hG + 15 ?>" class="barra-fecha" text-anchor="middle"><?= h(substr($p['d'], 8, 2)) ?></text>
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
                        <span class="cat-nombre"><?= h($m[0]) ?></span>
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
                        <span class="cat-nombre"><?= h($d['departamento']) ?></span>
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
                        <span class="cat-nombre"><?= h($c['nombre']) ?></span>
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
                        <span class="cat-nombre" title="<?= h($c['descripcion']) ?>"><?= h($c['descripcion']) ?></span>
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
