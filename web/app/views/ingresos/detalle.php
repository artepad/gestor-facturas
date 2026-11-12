<?php
/**
 * Vista de app/Controllers/IngresosController::detalle().
 * Variables disponibles: $usuario, $esAdmin, $corte, $movimientos, $departamentos, $correo.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

$fmtFH = fn($v) => $v ? date('d-m-Y H:i', strtotime($v)) : '—';
// Una vendedora no tiene el módulo Ingresos: "Volver" la manda a Caja (de
// donde en la práctica siempre llega), no al dashboard de Ingresos que no
// puede abrir.
$volverA = $esAdmin ? 'ingresos.php' : 'caja.php';
?>
<?php cabecera_dashboard($usuario, $esAdmin ? 'ingresos' : 'ventas', 'Detalle del corte'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Corte del <?= e($fmtFH($corte['cerrado_en'])) ?></h2>
            <a class="btn gris" href="<?= h($volverA) ?>">Volver</a>
        </div>

        <div class="panel">
            <div class="detalle-grid dg-bold">
                <div class="dg-item"><span class="dg-label">Negocio</span><span class="dg-valor"><?= e($corte['negocio']) ?></span></div>
                <div class="dg-item"><span class="dg-label">Caja</span><span class="dg-valor"><?= e($corte['caja'] ?: '—') ?></span></div>
                <div class="dg-item"><span class="dg-label">Cajero</span><span class="dg-valor"><?= e($corte['cajero'] ?: '—') ?></span></div>
                <div class="dg-item"><span class="dg-label">Turno</span><span class="dg-valor"><?= e($fmtFH($corte['abierto_en'])) ?> al <?= e($fmtFH($corte['cerrado_en'])) ?></span></div>
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
                    <?php if (($corte['recaudado_efectivo'] ?? null) !== null): ?>
                        <!-- Solo en cortes del POS propio: pagos de cuentas (Klap y otros
                             convenios). Entran al cajón pero NO son venta del negocio. -->
                        <tr><td>Pagos de cuentas <small class="texto-tenue">(no es venta)</small></td>
                            <td>+ <?= clp($corte['recaudado_efectivo']) ?: '$0' ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Entradas</td><td>+ <?= clp($corte['entradas_caja']) ?: '$0' ?></td></tr>
                    <tr><td>Salidas</td><td>− <?= clp($corte['salidas_caja']) ?: '$0' ?></td></tr>
                    <tr class="fila-total"><td>Esperado en caja</td><td><?= clp($corte['efectivo_esperado']) ?: '—' ?></td></tr>
                    <?php if (($corte['diferencia'] ?? null) !== null): ?>
                        <?php $dif = (float)$corte['diferencia']; ?>
                        <tr><td>Resultado del arqueo</td>
                            <td><span class="dif <?= $dif < 0 ? 'dif-falta' : ($dif > 0 ? 'dif-sobra' : 'dif-ok') ?>">
                                <?= $dif == 0 ? 'Cuadró' : (($dif > 0 ? 'Sobra ' : 'Falta ') . clp(abs($dif))) ?>
                            </span></td></tr>
                    <?php endif; ?>
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
                            <td><?= e($m['hora'] ?: '—') ?></td>
                            <td><?= e($m['descripcion']) ?></td>
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
                        <tr><td><?= e($d['departamento']) ?></td><td><?= clp($d['monto']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($correo): ?>
        <details class="bloque bloque-sep">
            <summary>Correo original
                (<?= $correo['origen'] === 'manual' ? 'pegado a mano' : 'recibido ' . e($fmtFH($correo['recibido_en'])) ?>)
            </summary>
            <?php if ($correo['asunto']): ?><p><strong>Asunto:</strong> <?= e($correo['asunto']) ?></p><?php endif; ?>
            <pre class="correo-crudo"><?= e($correo['cuerpo']) ?></pre>
        </details>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
