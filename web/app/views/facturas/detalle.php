<?php
/**
 * Vista de app/Controllers/FacturasController::detalle().
 * Variables disponibles: $usuario, $f, $detalle, $color, $txtEstado.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

$titulo = 'Factura ' . ($f['numero_factura'] ?: $f['id']);
?>
<?php cabecera_dashboard($usuario, 'facturas', $titulo); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-naranjo">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('facturas') ?></span>
                <h2>Factura <?= e($f['numero_factura'] ?: '—') ?></h2>
            </div>
            <div class="filtros-botones">
                <?php if ($f['ruta_pdf']): ?>
                    <a class="btn" href="ver_pdf.php?id=<?= (int)$f['id'] ?>" target="_blank">Ver PDF</a>
                <?php endif; ?>
                <a class="btn gris" href="panel_facturas.php">Volver</a>
            </div>
        </div>

        <div class="panel">
            <h2>Datos de la factura</h2>
            <div class="detalle-grid">
                <div class="dg-item">
                    <span class="dg-label">Estado</span>
                    <span class="dg-valor">
                        <span class="estado"><span class="punto <?= $color ?>"></span><?= e($txtEstado) ?></span>
                    </span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Fecha</span>
                    <span class="dg-valor"><?= e(fecha_dmy($f['fecha'])) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">N° Factura</span>
                    <span class="dg-valor"><?= e($f['numero_factura']) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Total</span>
                    <span class="dg-valor"><?= clp($f['total']) ?: '—' ?> <?= e($f['moneda']) ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Proveedor</span>
                    <span class="dg-valor"><?= e($f['proveedor']) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Razón Social</span>
                    <span class="dg-valor"><?= e($f['razon_social']) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">RUT Emisor</span>
                    <span class="dg-valor"><?= e($f['rut_emisor']) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Negocio</span>
                    <span class="dg-valor"><?= e($f['negocio_nombre']) ?></span>
                </div>
                <?php if (!empty($f['notas'])): ?>
                <div class="dg-item dg-ancho">
                    <span class="dg-label">Notas</span>
                    <span class="dg-valor"><?= e($f['notas']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($detalle): ?>
        <div class="panel">
            <h2>Detalle de productos</h2>
            <div class="tabla-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Descripción</th>
                            <th class="total">Cantidad</th>
                            <th class="total">P. Unitario</th>
                            <th class="total">Descuento</th>
                            <th class="total">Monto</th>
                            <th>Afecto IVA</th>
                            <th class="total">P. Sugerido</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detalle as $d): ?>
                        <tr>
                            <td><?= e($d['descripcion']) ?></td>
                            <td class="total"><?= $d['cantidad'] !== null ? e(rtrim(rtrim(number_format((float)$d['cantidad'], 2, ',', '.'), '0'), ',')) : '—' ?></td>
                            <td class="total"><?= clp($d['precio_unitario']) ?: '—' ?></td>
                            <td class="total"><?= clp($d['descuento']) ?: '—' ?></td>
                            <td class="total"><?= clp($d['monto']) ?: '—' ?></td>
                            <td><?= $d['afecto_iva'] ? 'Sí' : 'No' ?></td>
                            <td class="total"><?= clp($d['precio_sugerido']) ?: '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
