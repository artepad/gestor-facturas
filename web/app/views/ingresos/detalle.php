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
<?php
$difCab = ($corte['diferencia'] ?? null) !== null ? (float)$corte['diferencia'] : null;
?>
<?php cabecera_dashboard($usuario, $esAdmin ? 'ingresos' : 'ventas', 'Detalle del corte'); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-azul">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('ingresos') ?></span>
                <h2>Corte del <?= e($fmtFH($corte['cerrado_en'])) ?></h2>
            </div>
            <div class="cab-modulo-der">
                <a class="btn gris" href="<?= h($volverA) ?>">Volver</a>
            </div>
        </div>

        <div class="ingresos-stats bloque-sep">
            <div class="stat-card t-azul stat-destacado stat-centro">
                <span class="fs-icono"><?= icono('ventas') ?></span>
                <span class="stat-label">Ventas totales</span>
                <span class="stat-valor"><?= clp($corte['ventas_totales']) ?: '—' ?></span>
            </div>
            <div class="stat-card stat-centro">
                <span class="fs-icono"><?= icono('codigo-barras') ?></span>
                <span class="stat-label">N° de ventas</span>
                <span class="stat-valor stat-fecha"><?= $corte['numero_ventas'] !== null ? (int)$corte['numero_ventas'] : '—' ?></span>
            </div>
            <?php if ($difCab !== null): ?>
            <div class="stat-card stat-centro">
                <span class="fs-icono"><?= icono('caja') ?></span>
                <span class="stat-label">Resultado del arqueo</span>
                <span class="stat-valor stat-fecha dif <?= $difCab < 0 ? 'dif-falta' : ($difCab > 0 ? 'dif-sobra' : 'dif-ok') ?>">
                    <?= $difCab == 0 ? 'Cuadró' : (($difCab > 0 ? 'Sobra ' : 'Falta ') . clp(abs($difCab))) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <div class="cortes-detalle-grid bloque-sep">
            <div class="panel">
                <h2 class="panel-titulo"><?= icono('negocios') ?> Datos del corte</h2>
                <table class="tabla-corte">
                    <tr><td>Negocio</td><td><?= e($corte['negocio']) ?></td></tr>
                    <tr><td>Caja</td><td><?= e($corte['caja'] ?: '—') ?></td></tr>
                    <tr><td>Cajero</td><td><?= e($corte['cajero'] ?: '—') ?></td></tr>
                    <tr><td>Inicio del turno</td><td><?= e($fmtFH($corte['abierto_en'])) ?></td></tr>
                    <tr><td>Fin del turno</td><td><?= e($fmtFH($corte['cerrado_en'])) ?></td></tr>
                </table>
            </div>
            <div class="panel">
                <h2 class="panel-titulo"><?= icono('caja') ?> Dinero en caja</h2>
                <table class="tabla-corte">
                    <tr><td>Fondo de caja</td><td><?= clp($corte['fondo_caja']) ?: '—' ?></td></tr>
                    <tr><td>Ventas en efectivo</td><td class="tc-mas">+ <?= clp($corte['ventas_efectivo']) ?: '$0' ?></td></tr>
                    <tr><td>Abonos en efectivo</td><td class="tc-mas">+ <?= clp($corte['abonos_efectivo']) ?: '$0' ?></td></tr>
                    <?php if ((float)($corte['recaudado_efectivo'] ?? 0) > 0): ?>
                        <!-- Solo aparece en cortes viejos de cuando el negocio todavía
                             recaudaba cuentas de terceros (Klap, retirado 2026-11-14 — ver
                             web/CLAUDE.md, módulo Servicios). Entraba al cajón pero no era
                             venta del negocio; ya no se genera en cortes nuevos. -->
                        <tr><td>Pagos de cuentas <small class="texto-tenue">(no es venta)</small></td>
                            <td class="tc-mas">+ <?= clp($corte['recaudado_efectivo']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Entradas</td><td class="tc-mas">+ <?= clp($corte['entradas_caja']) ?: '$0' ?></td></tr>
                    <tr><td>Salidas</td><td class="tc-menos">− <?= clp($corte['salidas_caja']) ?: '$0' ?></td></tr>
                    <tr class="fila-total"><td>Esperado en caja</td><td><?= clp($corte['efectivo_esperado']) ?: '—' ?></td></tr>
                    <?php if ($difCab !== null): ?>
                        <tr><td>Resultado del arqueo</td>
                            <td><span class="dif <?= $difCab < 0 ? 'dif-falta' : ($difCab > 0 ? 'dif-sobra' : 'dif-ok') ?>">
                                <?= $difCab == 0 ? 'Cuadró' : (($difCab > 0 ? 'Sobra ' : 'Falta ') . clp(abs($difCab))) ?>
                            </span></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <div class="panel">
                <h2 class="panel-titulo"><?= icono('ventas') ?> Ventas por medio de pago</h2>
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
            <h2 class="panel-titulo"><?= icono('documento') ?> Movimientos de caja</h2>
            <div class="tabla-wrap tabla-plana tabla-movimientos-caja">
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
