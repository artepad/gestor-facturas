<?php
/**
 * Detalle de una factura: toda la información del documento + sus líneas de
 * producto + botón para ver el PDF. Validamos que el usuario tenga acceso al
 * negocio de la factura (mismo criterio que ver_pdf.php).
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('facturas');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: panel_facturas.php'); exit; }

$pdo = obtener_pdo();
$st = $pdo->prepare(
    "SELECT f.*, n.nombre AS negocio_nombre
     FROM facturas f JOIN negocios n ON n.id = f.negocio_id
     WHERE f.id = ? AND f.eliminada_en IS NULL"
);
$st->execute([$id]);
$f = $st->fetch();

// No existe, o el usuario no puede ver ese negocio -> de vuelta al listado.
if (!$f || !puede_ver_negocio($usuario, (int)$f['negocio_id'])) {
    header('Location: panel_facturas.php');
    exit;
}

// Líneas de producto (si las hay)
$st = $pdo->prepare("SELECT * FROM detalle_factura WHERE factura_id = ? ORDER BY id");
$st->execute([$id]);
$detalle = $st->fetchAll();

[$color, $txtEstado] = estado_factura($f);
$titulo = 'Factura ' . ($f['numero_factura'] ?: $id);
?>
<?php cabecera_dashboard($usuario, 'facturas', $titulo); ?>

    <div class="contenido">
        <div class="cab-acciones">
            <h2>Factura <?= h($f['numero_factura'] ?: '—') ?></h2>
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
                        <span class="estado"><span class="punto <?= $color ?>"></span><?= h($txtEstado) ?></span>
                    </span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Fecha</span>
                    <span class="dg-valor"><?= h(fecha_dmy($f['fecha'])) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">N° Factura</span>
                    <span class="dg-valor"><?= h($f['numero_factura']) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Total</span>
                    <span class="dg-valor"><?= clp($f['total']) ?: '—' ?> <?= h($f['moneda']) ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Proveedor</span>
                    <span class="dg-valor"><?= h($f['proveedor']) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Razón Social</span>
                    <span class="dg-valor"><?= h($f['razon_social']) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">RUT Emisor</span>
                    <span class="dg-valor"><?= h($f['rut_emisor']) ?: '—' ?></span>
                </div>
                <div class="dg-item">
                    <span class="dg-label">Negocio</span>
                    <span class="dg-valor"><?= h($f['negocio_nombre']) ?></span>
                </div>
                <?php if (!empty($f['notas'])): ?>
                <div class="dg-item dg-ancho">
                    <span class="dg-label">Notas</span>
                    <span class="dg-valor"><?= h($f['notas']) ?></span>
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
                            <td><?= h($d['descripcion']) ?></td>
                            <td class="total"><?= $d['cantidad'] !== null ? h(rtrim(rtrim(number_format((float)$d['cantidad'], 2, ',', '.'), '0'), ',')) : '—' ?></td>
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
