<?php
/**
 * Vista de app/Controllers/FacturasController::panel().
 * Variables disponibles: $usuario, $negocios, $fNegocio, $fProveedor, $fDesde,
 * $fHasta, $fTexto, $facturas, $proveedores, $viendo.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'facturas', 'Facturas'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-naranjo">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('facturas') ?></span>
                <h2>Facturas</h2>
            </div>
            <span class="badge-negocio">
                <?= icono('negocios') ?>
                Viendo: <strong><?= e($viendo) ?></strong>
            </span>
        </div>
        <div class="panel">
            <h2>Filtros</h2>
            <?php $fechasActivas = ($fDesde !== '' || $fHasta !== ''); ?>
            <form class="filtros<?= $fechasActivas ? ' mostrar-fechas' : '' ?>" method="get">
                <div class="campo campo-busqueda">
                    <label>Búsqueda</label>
                    <input type="text" name="q" value="<?= e($fTexto) ?>"
                           placeholder="N° Factura, proveedor, razón social" autofocus>
                    <!-- Interruptor de fechas: queda justo debajo de la búsqueda. -->
                    <label class="toggle-fechas">
                        <input type="checkbox" id="toggleFechas" <?= $fechasActivas ? 'checked' : '' ?>>
                        Filtrar por fechas
                    </label>
                </div>
                <?php if (count($negocios) > 1): ?>
                <div class="campo campo-medio">
                    <label>Negocio</label>
                    <!-- Al elegir un negocio se busca solo, sin tocar "Buscar". -->
                    <select name="negocio" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"
                                <?= $fNegocio === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="campo campo-medio">
                    <label>Proveedor</label>
                    <!-- Combo con los proveedores existentes; busca solo al elegir. -->
                    <select name="proveedor" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($proveedores as $prov): ?>
                            <option value="<?= e($prov) ?>"
                                <?= $fProveedor === $prov ? 'selected' : '' ?>>
                                <?= e($prov) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Desde/Hasta: ocultos por defecto; aparecen al marcar el interruptor.
                     En móvil quedan ocultos (junto con el interruptor) por CSS. -->
                <div class="campo campo-fecha">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= e($fDesde) ?>">
                </div>
                <div class="campo campo-fecha">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= e($fHasta) ?>">
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Buscar</button>
                    <a class="btn gris" href="panel_facturas.php">Limpiar</a>
                </div>
            </form>
        </div>

        <div class="resumen"><?= count($facturas) ?> factura(s)</div>

        <div class="tabla-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="nowrap">Fecha</th>
                        <th>Proveedor</th>
                        <th>N° Factura</th>
                        <th class="total">Total</th>
                        <th class="col-ocultar-movil">Razón Social</th>
                        <?php if (count($negocios) > 1): ?><th class="col-ocultar-movil">Negocio</th><?php endif; ?>
                        <th class="celda-estado">Estado</th>
                        <th class="col-ocultar-movil">PDF</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$facturas): ?>
                    <tr><td class="vacio" colspan="8">No hay facturas con esos filtros.</td></tr>
                <?php else: foreach ($facturas as $f):
                    [$color, $txtEstado] = estado_factura($f); ?>
                    <tr class="fila-click" onclick="location.href='factura.php?id=<?= (int)$f['id'] ?>'">
                        <td class="nowrap"><?= e(fecha_dmy($f['fecha'])) ?></td>
                        <td><?= e($f['proveedor']) ?></td>
                        <td><?= e($f['numero_factura'] ?? '') ?></td>
                        <td class="total"><?= clp($f['total']) ?></td>
                        <td class="col-ocultar-movil"><?= e($f['razon_social'] ?? '') ?></td>
                        <?php if (count($negocios) > 1): ?>
                            <td class="col-ocultar-movil"><?= e($f['negocio_nombre']) ?></td>
                        <?php endif; ?>
                        <td class="celda-estado"><span class="estado"><span class="punto <?= $color ?>"></span><span class="estado-txt"><?= $txtEstado ?></span></span></td>
                        <td class="col-ocultar-movil">
                            <?php if ($f['ruta_pdf']): ?>
                                <a class="btn-icono" href="ver_pdf.php?id=<?= (int)$f['id'] ?>" target="_blank"
                                   title="Ver PDF" aria-label="Ver PDF" onclick="event.stopPropagation()"><?= icono('documento') ?></a>
                            <?php else: ?>
                                <span style="color:#aaa">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
      // El interruptor "Filtrar por fechas" muestra/oculta los campos Desde/Hasta.
      (function () {
        var chk = document.getElementById('toggleFechas');
        if (!chk) return;
        var form = chk.closest('form');
        chk.addEventListener('change', function () {
          form.classList.toggle('mostrar-fechas', chk.checked);
          if (!chk.checked) {  // al desactivar, limpiar las fechas elegidas
            form.querySelector('input[name="desde"]').value = '';
            form.querySelector('input[name="hasta"]').value = '';
          }
        });
      })();
    </script>
    <?php pie_dashboard(); ?>
