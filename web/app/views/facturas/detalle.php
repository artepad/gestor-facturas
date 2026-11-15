<?php
/**
 * Vista de app/Controllers/FacturasController::detalle().
 * Variables disponibles: $usuario, $f, $detalle, $color, $txtEstado, $esAdmin,
 * $error, $edicion (valores enviados al editar, solo si falló la validación).
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

$titulo = 'Factura ' . ($f['numero_factura'] ?: $f['id']);

// Valores a precargar en el formulario de edición: los recién enviados (si
// la validación falló) o los que ya tiene la factura.
$ed = $edicion ?? [
    'proveedor'      => $f['proveedor'] ?? '',
    'razon_social'   => $f['razon_social'] ?? '',
    'rut_emisor'     => $f['rut_emisor'] ?? '',
    'numero_factura' => $f['numero_factura'] ?? '',
    'fecha'          => $f['fecha'] ?? '',
    'total'          => $f['total'] !== null ? number_format((float)$f['total'], 0, ',', '.') : '',
    'notas'          => $f['notas'] ?? '',
];
?>
<?php cabecera_dashboard($usuario, 'facturas', $titulo); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-naranjo">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('facturas') ?></span>
                <h2>Factura <?= e($f['numero_factura'] ?: '—') ?></h2>
            </div>
            <div class="cab-modulo-der">
                <?php if ($f['ruta_pdf']): ?>
                    <a class="btn" href="ver_pdf.php?id=<?= (int)$f['id'] ?>" target="_blank">Ver</a>
                <?php endif; ?>
                <a class="btn gris" href="panel_facturas.php">Volver</a>
            </div>
        </div>

        <?php if ($error): ?>
            <p class="modal-confirmar-aviso aviso-pagina"><?= e($error) ?></p>
        <?php endif; ?>

        <div class="factura-detalle">
            <div class="factura-stats">
                <div class="stat-card t-naranjo stat-destacado fs-total">
                    <span class="fs-icono"><?= icono('facturas') ?></span>
                    <span class="stat-label">Total</span>
                    <span class="stat-valor"><?= clp($f['total']) ?: '—' ?> <small><?= e($f['moneda']) ?></small></span>
                </div>
                <div class="stat-card fs-estado fs-estado-<?= e($color) ?>">
                    <span class="fs-icono"><span class="punto <?= $color ?>"></span></span>
                    <span class="stat-label">Estado</span>
                    <span class="stat-valor stat-estado"><?= e($txtEstado) ?></span>
                </div>
                <div class="stat-card fs-fecha">
                    <span class="fs-icono"><?= icono('reloj') ?></span>
                    <span class="stat-label">Fecha de emisión</span>
                    <span class="stat-valor stat-fecha"><?= e(fecha_dmy($f['fecha'])) ?: '—' ?></span>
                </div>
            </div>

            <div class="panel fd-panel">
                <h2 class="fd-panel-tit"><?= icono('documento') ?> Datos de la factura</h2>
                <div class="detalle-grid fd-grid">
                    <div class="dg-item">
                        <span class="dg-label">N° Factura</span>
                        <span class="dg-valor"><?= e($f['numero_factura']) ?: '—' ?></span>
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
                </div>
                <?php if (!empty($f['notas'])): ?>
                <div class="fd-notas">
                    <span class="dg-label">Notas</span>
                    <p><?= e($f['notas']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($detalle): ?>
        <div class="panel fd-panel">
            <h2 class="fd-panel-tit"><?= icono('categorias') ?> Detalle de productos</h2>
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

        <?php if ($esAdmin): ?>
        <!-- Acciones de administrador, abajo para no saturar la cabecera (más cómodo en el celular) -->
        <div class="fd-acciones">
            <button type="button" class="btn gris" id="btnEditarFactura">
                <?= icono('lapiz') ?> Editar factura
            </button>
            <form method="post" class="inline-form"
                  onsubmit="return confirm('¿Eliminar esta factura? Esta acción no se puede deshacer.')">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="eliminar">
                <button type="submit" class="btn peligro">
                    <?= icono('basurero') ?> Eliminar factura
                </button>
            </form>
        </div>

        <!-- ====== Editar factura (modal) ====== -->
        <div class="modal-fondo" id="editFondo" hidden></div>
        <section class="modal-confirmar modal-confirmar-ancho" id="editModal" <?= $error ? '' : 'hidden' ?>
                 aria-label="Editar factura">
            <div class="modal-confirmar-icono"><?= icono('lapiz') ?></div>
            <h2>Editar factura</h2>
            <p class="modal-confirmar-ayuda">
                Corrige los datos que estén mal. El PDF original no se toca.
            </p>

            <?php if ($f['origen'] === 'sync'): ?>
            <p class="modal-confirmar-aviso">
                Esta factura llegó sincronizada desde el programa de escritorio. Si ese
                mismo PC vuelve a sincronizarla (por ejemplo, porque allá también se
                editó), los datos que cambies acá se sobrescriben con lo que mande el PC.
            </p>
            <?php endif; ?>

            <form method="post">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="editar">
                <div class="factura-campos">
                    <div class="campo campo-ancho">
                        <label>Proveedor (marca)</label>
                        <input type="text" name="proveedor" value="<?= e($ed['proveedor']) ?>" required>
                    </div>
                    <div class="campo campo-ancho">
                        <label>Razón social</label>
                        <input type="text" name="razon_social" value="<?= e($ed['razon_social']) ?>">
                    </div>
                    <div class="campo">
                        <label>RUT emisor</label>
                        <input type="text" name="rut_emisor" value="<?= e($ed['rut_emisor']) ?>" placeholder="XX.XXX.XXX-X">
                    </div>
                    <div class="campo">
                        <label>N° Factura</label>
                        <input type="text" name="numero_factura" value="<?= e($ed['numero_factura']) ?>">
                    </div>
                    <div class="campo">
                        <label>Fecha de emisión</label>
                        <input type="date" name="fecha" value="<?= e($ed['fecha']) ?>" required>
                    </div>
                    <div class="campo">
                        <label>Total</label>
                        <input type="text" inputmode="numeric" name="total" id="totalEditar" value="<?= e($ed['total']) ?>" required>
                    </div>
                    <div class="campo campo-ancho">
                        <label>Notas</label>
                        <textarea name="notas" rows="2" placeholder="Observaciones (opcional)"><?= e($ed['notas']) ?></textarea>
                    </div>
                </div>

                <div class="modal-confirmar-btns">
                    <button type="button" class="btn gris" id="btnCancelarEdicion">Cancelar</button>
                    <button type="submit" class="btn verde">Guardar cambios</button>
                </div>
            </form>
        </section>
        <script>
          (function () {
            var btnAbrir = document.getElementById('btnEditarFactura'),
                btnCerrar = document.getElementById('btnCancelarEdicion'),
                fondo = document.getElementById('editFondo'),
                modal = document.getElementById('editModal'),
                totalInput = document.getElementById('totalEditar');
            function abrir() { fondo.hidden = false; modal.hidden = false; }
            function cerrar() { fondo.hidden = true; modal.hidden = true; }
            if (btnAbrir) btnAbrir.addEventListener('click', abrir);
            if (btnCerrar) btnCerrar.addEventListener('click', cerrar);
            fondo.addEventListener('click', cerrar);
            if (!modal.hidden) fondo.hidden = false; // el modal ya viene abierto si hubo un error de validación

            // Separador de miles en vivo (formato chileno: "." cada 3 dígitos),
            // igual que el pop-up de "Agregar factura" — el servidor ya sabe
            // leer "28.810" (ver parsear_monto() en lib/ui.php), así que el
            // valor se manda al backend tal cual se ve en pantalla.
            function formatoMiles(v) {
              v = String(v || '').replace(/[^0-9]/g, '');
              return v === '' ? '' : v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }
            totalInput.addEventListener('input', function () {
              var pos = totalInput.selectionStart, antes = totalInput.value.length;
              totalInput.value = formatoMiles(totalInput.value);
              totalInput.selectionStart = totalInput.selectionEnd = pos + (totalInput.value.length - antes);
            });
          })();
        </script>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
