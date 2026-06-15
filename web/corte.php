<?php
/**
 * Detalle de un corte de caja: resumen completo, movimientos (entradas y
 * salidas), ventas por departamento y el correo original como respaldo.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('ingresos');
$pdo = obtener_pdo();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT c.*, n.nombre AS negocio, cj.nombre AS cajero
     FROM cortes c
     JOIN negocios n ON n.id = c.negocio_id
     LEFT JOIN cajeros cj ON cj.id = c.cajero_id
     WHERE c.id = ?"
);
$st->execute([$id]);
$corte = $st->fetch();

if (!$corte || !puede_ver_negocio($usuario, (int)$corte['negocio_id'])) {
    header('Location: ingresos.php');
    exit;
}

$st = $pdo->prepare("SELECT * FROM corte_movimientos WHERE corte_id = ? ORDER BY tipo, id");
$st->execute([$id]);
$movimientos = $st->fetchAll();

$st = $pdo->prepare("SELECT * FROM corte_departamentos WHERE corte_id = ? ORDER BY monto DESC");
$st->execute([$id]);
$departamentos = $st->fetchAll();

$correo = null;
if ($corte['correo_id']) {
    $st = $pdo->prepare("SELECT asunto, remitente, recibido_en, origen, cuerpo FROM correos_corte WHERE id = ?");
    $st->execute([(int)$corte['correo_id']]);
    $correo = $st->fetch() ?: null;
}

$fmtFH = fn($v) => $v ? date('d-m-Y H:i', strtotime($v)) : '—';
?>
<?php cabecera_dashboard($usuario, 'ingresos', 'Detalle del corte'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Corte del <?= h($fmtFH($corte['cerrado_en'])) ?></h2>
            <a class="btn gris" href="ingresos.php">Volver</a>
        </div>

        <div class="panel">
            <div class="detalle-grid dg-bold">
                <div class="dg-item"><span class="dg-label">Negocio</span><span class="dg-valor"><?= h($corte['negocio']) ?></span></div>
                <div class="dg-item"><span class="dg-label">Caja</span><span class="dg-valor"><?= h($corte['caja'] ?: '—') ?></span></div>
                <div class="dg-item"><span class="dg-label">Cajero</span><span class="dg-valor"><?= h($corte['cajero'] ?: '—') ?></span></div>
                <div class="dg-item"><span class="dg-label">Turno</span><span class="dg-valor"><?= h($fmtFH($corte['abierto_en'])) ?> al <?= h($fmtFH($corte['cerrado_en'])) ?></span></div>
                <div class="dg-item"><span class="dg-label">N° de ventas</span><span class="dg-valor"><?= $corte['numero_ventas'] !== null ? (int)$corte['numero_ventas'] : '—' ?></span></div>
                <div class="dg-item"><span class="dg-label">Ventas totales</span><span class="dg-valor"><?= clp($corte['ventas_totales']) ?: '—' ?></span></div>
            </div>
        </div>

        <div class="grid-negocios grid-stats bloque-sep">
            <div class="panel">
                <h2 class="panel-titulo">Dinero en caja</h2>
                <table class="tabla-corte">
                    <tr><td>Fondo de caja</td><td><?= clp($corte['fondo_caja']) ?: '—' ?></td></tr>
                    <tr><td>Ventas en efectivo</td><td>+ <?= clp($corte['ventas_efectivo']) ?: '$0' ?></td></tr>
                    <tr><td>Abonos en efectivo</td><td>+ <?= clp($corte['abonos_efectivo']) ?: '$0' ?></td></tr>
                    <tr><td>Entradas</td><td>+ <?= clp($corte['entradas_caja']) ?: '$0' ?></td></tr>
                    <tr><td>Salidas</td><td>− <?= clp($corte['salidas_caja']) ?: '$0' ?></td></tr>
                    <tr class="fila-total"><td>Esperado en caja</td><td><?= clp($corte['efectivo_esperado']) ?: '—' ?></td></tr>
                </table>
            </div>
            <div class="panel">
                <h2 class="panel-titulo">Ventas por medio de pago</h2>
                <table class="tabla-corte">
                    <tr><td>Efectivo</td><td><?= clp($corte['ventas_efectivo']) ?: '$0' ?></td></tr>
                    <tr><td>Tarjeta</td><td><?= clp($corte['ventas_tarjeta']) ?: '$0' ?></td></tr>
                    <tr><td>Transferencia</td><td><?= clp($corte['ventas_transferencia']) ?: '$0' ?></td></tr>
                    <tr><td>A crédito</td><td><?= clp($corte['ventas_credito']) ?: '$0' ?></td></tr>
                    <tr><td>Vales</td><td><?= clp($corte['ventas_vales']) ?: '$0' ?></td></tr>
                    <tr class="fila-total"><td>Total</td><td><?= clp($corte['ventas_totales']) ?: '—' ?></td></tr>
                </table>
            </div>
        </div>

        <?php if ($movimientos): ?>
        <div class="panel bloque-sep">
            <h2 class="panel-titulo">Movimientos de caja</h2>
            <div class="tabla-wrap tabla-plana">
                <table>
                    <thead><tr><th>Tipo</th><th>Hora</th><th>Descripción</th><th>Monto</th></tr></thead>
                    <tbody>
                    <?php foreach ($movimientos as $m): ?>
                        <tr>
                            <td><span class="mov-badge <?= $m['tipo'] === 'salida' ? 'mov-fiado' : 'mov-abono' ?>">
                                <?= $m['tipo'] === 'salida' ? 'Salida' : 'Entrada' ?></span></td>
                            <td><?= h($m['hora'] ?: '—') ?></td>
                            <td><?= h($m['descripcion']) ?></td>
                            <td><?= clp($m['monto']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($departamentos): ?>
        <details class="bloque bloque-sep">
            <summary>Ventas por departamento (<?= count($departamentos) ?>)</summary>
            <div class="tabla-wrap tabla-plana">
                <table>
                    <thead><tr><th>Departamento</th><th>Monto</th></tr></thead>
                    <tbody>
                    <?php foreach ($departamentos as $d): ?>
                        <tr><td><?= h($d['departamento']) ?></td><td><?= clp($d['monto']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($correo): ?>
        <details class="bloque bloque-sep">
            <summary>Correo original
                (<?= $correo['origen'] === 'manual' ? 'pegado a mano' : 'recibido ' . h($fmtFH($correo['recibido_en'])) ?>)
            </summary>
            <?php if ($correo['asunto']): ?><p><strong>Asunto:</strong> <?= h($correo['asunto']) ?></p><?php endif; ?>
            <pre class="correo-crudo"><?= h($correo['cuerpo']) ?></pre>
        </details>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
