<?php
/**
 * Home: tablero de mando. Cruza Ingresos (cortes), Fiados y Facturas para dar
 * el estado de los negocios de un vistazo. Todo respeta los permisos del rol y
 * el alcance por negocios visibles; las secciones aparecen según corresponda.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/gastos.php';

$usuario = exigir_login();
$pdo = obtener_pdo();

$verFacturas = puede($usuario, 'facturas');
$verFiados   = puede($usuario, 'fiados');
$verIngresos = puede($usuario, 'ingresos');
$verGastos   = puede($usuario, 'gastos');
$verNegocios = puede($usuario, 'negocios');
$verUsuarios = puede($usuario, 'usuarios');

$negocios = negocios_visibles($usuario);
$ids = array_map(fn($n) => (int)$n['id'], $negocios);
$nNegocios = count($negocios);

// Rango del mes en curso y del mes anterior (para comparativa)
$inicioMes    = date('Y-m-01');
$hoy          = date('Y-m-d');
$inicioMesAnt = date('Y-m-01', strtotime('first day of last month'));
$finMesAnt    = date('Y-m-t',  strtotime('last day of last month'));
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
          'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mesNombre = $meses[(int)date('n')] . ' ' . date('Y');

$ph = $ids ? implode(',', array_fill(0, count($ids), '?')) : '';

// --- Métricas por negocio (este mes) ---
$ventasNeg = []; $comprasNeg = []; $deudaNeg = []; $gastosNeg = [];
if ($ids && $verIngresos) {
    $st = $pdo->prepare("SELECT negocio_id, COALESCE(SUM(ventas_totales),0) v
        FROM cortes WHERE negocio_id IN ($ph) AND DATE(cerrado_en) BETWEEN ? AND ?
        GROUP BY negocio_id");
    $st->execute([...$ids, $inicioMes, $hoy]);
    foreach ($st as $r) $ventasNeg[(int)$r['negocio_id']] = (float)$r['v'];
}
if ($ids && $verFacturas) {
    $st = $pdo->prepare("SELECT negocio_id, COALESCE(SUM(total),0) m, COUNT(*) n
        FROM facturas WHERE negocio_id IN ($ph) AND eliminada_en IS NULL AND fecha BETWEEN ? AND ?
        GROUP BY negocio_id");
    $st->execute([...$ids, $inicioMes, $hoy]);
    foreach ($st as $r) $comprasNeg[(int)$r['negocio_id']] = ['m' => (float)$r['m'], 'n' => (int)$r['n']];
}
if ($ids && $verFiados) {
    // Deuda por negocio = Σ saldos positivos de sus clientes (= fiados − abonos)
    $st = $pdo->prepare("SELECT c.negocio_id, COALESCE(SUM(GREATEST(0,
            (SELECT COALESCE(SUM(f.monto),0) FROM fiados f WHERE f.cliente_id = c.id)
          - (SELECT COALESCE(SUM(a.monto),0) FROM abonos a WHERE a.cliente_id = c.id))),0) d
        FROM clientes c WHERE c.negocio_id IN ($ph) AND c.activo = 1 GROUP BY c.negocio_id");
    $st->execute($ids);
    foreach ($st as $r) $deudaNeg[(int)$r['negocio_id']] = (float)$r['d'];
}
$nGastosMes = 0;
if ($ids && $verGastos) {
    $gastosNeg = gastos_por_negocio($pdo, $ids, $inicioMes, $hoy);
    $st = $pdo->prepare("SELECT COUNT(*) FROM gastos
        WHERE negocio_id IN ($ph) AND fecha BETWEEN ? AND ?");
    $st->execute([...$ids, $inicioMes, $hoy]);
    $nGastosMes = (int)$st->fetchColumn();
}

// Totales (KPIs) derivados de los mapas por negocio
$ventasMes    = array_sum($ventasNeg);
$comprasMes   = array_sum(array_column($comprasNeg, 'm'));
$nFacturasMes = array_sum(array_column($comprasNeg, 'n'));
$deudaTotal   = array_sum($deudaNeg);
$gastosMes    = array_sum($gastosNeg);

// Ventas del mes anterior (para la comparativa de la tarjeta destacada)
$ventasMesAnt = 0;
if ($ids && $verIngresos) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(ventas_totales),0) FROM cortes
        WHERE negocio_id IN ($ph) AND DATE(cerrado_en) BETWEEN ? AND ?");
    $st->execute([...$ids, $inicioMesAnt, $finMesAnt]);
    $ventasMesAnt = (float)$st->fetchColumn();
}
$varVentas = $ventasMesAnt > 0 ? (($ventasMes - $ventasMesAnt) / $ventasMesAnt * 100) : null;

// Ventas por día del mes (gráfico)
$porDia = [];
if ($ids && $verIngresos) {
    $st = $pdo->prepare("SELECT DATE(cerrado_en) d, COALESCE(SUM(ventas_totales),0) v
        FROM cortes WHERE negocio_id IN ($ph) AND DATE(cerrado_en) BETWEEN ? AND ?
        GROUP BY DATE(cerrado_en) ORDER BY d");
    $st->execute([...$ids, $inicioMes, $hoy]);
    $porDia = $st->fetchAll();
}

// --- Requiere atención (desglosado por negocio) ---
$nombreNeg = [];
foreach ($negocios as $n) $nombreNeg[(int)$n['id']] = $n['nombre'];
$multiNeg = $nNegocios > 1;

// Facturas por revisar, por negocio
$facturasRevisarNeg = [];
if ($ids && $verFacturas) {
    $st = $pdo->prepare("SELECT negocio_id, COUNT(*) n FROM facturas
        WHERE negocio_id IN ($ph) AND eliminada_en IS NULL
          AND confianza IS NOT NULL AND confianza < 0.7
        GROUP BY negocio_id");
    $st->execute($ids);
    foreach ($st as $r) $facturasRevisarNeg[(int)$r['negocio_id']] = (int)$r['n'];
}
// Cortes sin procesar (suelen llegar sin negocio asignado: se deja agregado)
$correosError = 0;
if ($ids && $verIngresos) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM correos_corte
        WHERE estado = 'error' AND (negocio_id IS NULL OR negocio_id IN ($ph))");
    $st->execute($ids);
    $correosError = (int)$st->fetchColumn();
}
// Clientes con deuda + monto por cobrar, por negocio
$fiadosNeg = [];
if ($ids && $verFiados) {
    $st = $pdo->prepare("SELECT t.negocio_id,
            COUNT(*) n, COALESCE(SUM(t.saldo),0) monto
        FROM (
            SELECT c.id, c.negocio_id,
                (SELECT COALESCE(SUM(f.monto),0) FROM fiados f WHERE f.cliente_id = c.id)
              - (SELECT COALESCE(SUM(a.monto),0) FROM abonos a WHERE a.cliente_id = c.id) AS saldo
            FROM clientes c WHERE c.negocio_id IN ($ph) AND c.activo = 1
        ) t
        WHERE t.saldo > 0
        GROUP BY t.negocio_id");
    $st->execute($ids);
    foreach ($st as $r) $fiadosNeg[(int)$r['negocio_id']] = ['n' => (int)$r['n'], 'monto' => (float)$r['monto']];
}

$alertas = [];
foreach ($facturasRevisarNeg as $nid => $cnt) {
    if ($cnt <= 0) continue;
    $alertas[] = ['modulo' => 'facturas', 'color' => 'modulo-naranjo',
        'titulo' => "$cnt factura(s) por revisar",
        'desc'   => ($multiNeg ? $nombreNeg[$nid] . ' · ' : '') . 'Baja confianza; revísalas para confirmarlas.',
        'link'   => 'panel_facturas.php'];
}
if ($correosError > 0) $alertas[] = [
    'modulo' => 'ingresos', 'color' => 'modulo-azul',
    'titulo' => "$correosError corte(s) sin procesar",
    'desc'   => 'Llegaron por correo pero no se pudieron leer.',
    'link'   => 'ingresos.php'];
foreach ($fiadosNeg as $nid => $d) {
    if ($d['n'] <= 0) continue;
    $alertas[] = ['modulo' => 'fiados', 'color' => 'modulo-morado',
        'titulo' => $d['n'] . ' cliente(s) con deuda',
        'desc'   => ($multiNeg ? $nombreNeg[$nid] . ' · ' : '') . 'Por cobrar: ' . (clp($d['monto']) ?: '$0'),
        'link'   => 'fiados.php'];
}

// --- KPIs (cada uno con el color de su valor) ---
$kpis = [];
if ($verIngresos) $kpis[] = ['Ventas del mes', clp($ventasMes) ?: '$0', 'color' => 'valor-azul', 'borde' => 't-azul', 'destacado' => true, 'comp' => $varVentas, 'link' => 'ingresos.php'];
if ($verGastos)   $kpis[] = ['Gastos del mes', clp($gastosMes) ?: '$0', 'color' => 'valor-rojo', 'borde' => 't-rojo', 'sub' => $nGastosMes . ' gasto(s)', 'link' => 'gastos.php'];
if ($verFacturas) $kpis[] = ['Compras del mes', clp($comprasMes) ?: '$0', 'color' => 'valor-naranjo', 'borde' => 't-naranjo', 'sub' => $nFacturasMes . ' factura(s)', 'link' => 'panel_facturas.php'];
if ($verIngresos && $verGastos) {
    $balanceMes = $ventasMes - $comprasMes - $gastosMes;
    $kpis[] = ['Balance del mes', clp($balanceMes) ?: '$0',
               'color' => $balanceMes >= 0 ? 'valor-verde' : 'valor-rojo',
               'borde' => $balanceMes >= 0 ? 't-verde' : 't-rojo',
               'sub'   => 'ventas − compras − gastos'];
}

// --- Accesos directos a Herramientas (los 4 más usados, sin Base de Datos) ---
$herramientas = [];
if (puede($usuario, 'herramientas')) {
    $herramientas = [
        ['codigo-barras', 'Consultar Catálogo', 'herramienta_catalogo.php'],
        ['calculadora',   'Contador de Caja',   'herramienta_caja.php'],
        ['etiqueta',      'Gestor de Etiquetas', 'herramienta_etiquetas.php'],
        ['oferta',        'Etiquetas de Ofertas', 'herramienta_ofertas.php'],
    ];
}
?>
<?php cabecera_dashboard($usuario, 'home', 'Inicio'); ?>
    <div class="contenido">
        <div class="saludo-cab">
            <h2 class="saludo">Hola, <?= h($usuario['nombre'] ?: 'usuario') ?> 👋</h2>
            <p class="saludo-sub">Resumen de <?= h($mesNombre) ?></p>
        </div>

        <?php if ($kpis): ?>
        <div class="ingresos-stats">
            <?php foreach ($kpis as $k):
                $tag = !empty($k['link']) ? 'a' : 'div';
                $clases = 'stat-card' . ($k['borde'] ?? '' ? ' ' . $k['borde'] : '')
                        . (!empty($k['destacado']) ? ' stat-destacado' : '')
                        . (!empty($k['link']) ? ' stat-link' : '');
                $href = !empty($k['link']) ? ' href="' . h($k['link']) . '"' : '';
            ?>
            <<?= $tag . $href ?> class="<?= $clases ?>">
                <span class="stat-label"><?= h($k[0]) ?></span>
                <span class="stat-valor <?= h($k['color'] ?? '') ?>"><?= h($k[1]) ?></span>
                <?php if (array_key_exists('comp', $k) && $k['comp'] !== null): ?>
                    <span class="stat-comp <?= $k['comp'] >= 0 ? 'comp-sube' : 'comp-baja' ?>">
                        <?= ($k['comp'] >= 0 ? '▲ +' : '▼ ') . number_format($k['comp'], 1, ',', '.') ?>%
                        <span class="texto-tenue">vs. mes anterior</span>
                    </span>
                <?php elseif (!empty($k['sub'])): ?>
                    <span class="stat-comp texto-tenue"><?= h($k['sub']) ?></span>
                <?php endif; ?>
            </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($nNegocios > 1): ?>
        <h3 class="dash-titulo">Estado por negocio</h3>
        <div class="negocios-resumen">
            <?php foreach ($negocios as $n): $nid = (int)$n['id']; ?>
            <div class="nr-card">
                <h3><?= icono('negocios') ?> <?= h($n['nombre']) ?></h3>
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
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($herramientas): ?>
        <h3 class="dash-titulo">Herramientas</h3>
        <div class="home-tools">
            <?php foreach ($herramientas as $t): ?>
            <a class="home-tool" href="<?= h($t[2]) ?>">
                <span class="home-tool-ic"><?= icono($t[0]) ?></span>
                <span class="home-tool-txt"><?= h($t[1]) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="dash-cols">
            <?php if (count($porDia) > 1): ?>
            <div class="panel">
                <h2 class="panel-titulo">Ventas por día · <?= h($meses[(int)date('n')]) ?></h2>
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
                        <title><?= h(fecha_dmy($p['d'])) ?>: <?= clp($v) ?></title>
                    </rect>
                    <text x="<?= $x + $aB / 2 ?>" y="<?= $hG + 15 ?>" class="barra-fecha" text-anchor="middle"><?= h(substr($p['d'], 8, 2)) ?></text>
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
                    <a class="atencion-item <?= h($a['color']) ?>" href="<?= h($a['link']) ?>">
                        <span class="atencion-ic"><?= icono($a['modulo']) ?></span>
                        <span class="atencion-txt">
                            <span class="atencion-tit"><?= h($a['titulo']) ?></span>
                            <span class="atencion-desc"><?= h($a['desc']) ?></span>
                        </span>
                        <span class="atencion-flecha">→</span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
    <?php pie_dashboard(); ?>
