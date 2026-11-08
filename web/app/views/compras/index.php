<?php
/**
 * Vista de app/Controllers/ComprasController::index().
 *
 * Fase 9 (despliegue): esta pantalla ya es la real (web/compras.php la
 * puentea directo a este Controlador), así que usa el layout completo del
 * panel (cabecera_dashboard/pie_dashboard), igual que el resto del sitio.
 *
 * Variables disponibles: $usuario, $negocios, $negocioId, $error, $aviso,
 * $controla, $proveedores, $compras, $verId, $detalle, $facturas.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

function n3c_vista($v) { return rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ','); }
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Compras'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('inventario') ?></span>
                <h2>Compras</h2>
            </div>
            <div class="cab-botones">
                <a class="btn gris" href="inventario.php?negocio_id=<?= $negocioId ?>">Inventario</a>
                <?php if (count($negocios) > 1): ?>
                <form method="get">
                    <select name="negocio_id" onchange="this.form.submit()">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= $negocioId === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($aviso): ?><div class="panel exito"><p><?= e($aviso) ?></p></div><?php endif; ?>

        <?php if (!$controla): ?>
            <div class="panel aviso-compartido">
                <p>
                    El <strong>inventario está apagado</strong> en este negocio: las compras se
                    guardan como registro de lo que llegó, pero <strong>no suman stock</strong>.
                    <a href="inventario.php?negocio_id=<?= $negocioId ?>">Encenderlo</a> si quieres
                    llevar existencias.
                </p>
            </div>
        <?php endif; ?>

        <!-- ================= Registrar lo que llegó ================= -->
        <div class="panel">
            <h2 class="panel-titulo">Registrar mercadería recibida</h2>
            <p class="texto-ayuda">
                Pega una línea por producto: <strong>código, cantidad</strong> y, si quieres,
                el <strong>costo unitario</strong>. Los códigos que no estén en el catálogo se
                informan y no se cargan (no se inventan productos solos).
            </p>
            <form method="post">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="compra">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <div class="filtros">
                    <div class="campo">
                        <label>Proveedor</label>
                        <select name="proveedor_id">
                            <option value="">— Sin especificar —</option>
                            <?php foreach ($proveedores as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <?php if ($facturas): ?>
                    <div class="campo" style="flex:1 1 260px">
                        <label>Enlazar con una factura escaneada (opcional)</label>
                        <select name="factura_id" style="width:100%">
                            <option value="">— Ninguna —</option>
                            <?php foreach ($facturas as $f): ?>
                                <option value="<?= (int)$f['id'] ?>">
                                    <?= e($f['proveedor'] ?: 'Sin proveedor') ?>
                                    <?= $f['numero_factura'] ? '· N° ' . e($f['numero_factura']) : '' ?>
                                    · <?= e(fecha_dmy($f['fecha'])) ?> · <?= clp($f['total']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="campo">
                    <label>Lo que llegó (código, cantidad y costo por línea)</label>
                    <textarea name="detalle" rows="8" required style="width:100%;font-family:monospace"
placeholder="7801234567890 24 850
1100000100 10 1200
7802820331530 6"></textarea>
                    <small class="texto-tenue">El costo es opcional; sirve para saber cuánto vale tu inventario.</small>
                </div>
                <div class="campo">
                    <label>Nota (opcional)</label>
                    <input type="text" name="nota" placeholder="Pedido del martes">
                </div>
                <div class="form-acciones">
                    <button class="btn verde" type="submit">Registrar y sumar al stock</button>
                </div>
            </form>
        </div>

        <!-- ================= Historial ================= -->
        <div class="panel">
            <h2 class="panel-titulo">Últimas compras</h2>
            <?php if (!$compras): ?>
                <p class="vacio">Todavía no hay compras registradas.</p>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>Fecha</th><th>Proveedor</th><th class="col-num">Productos</th>
                            <th class="col-num">Total</th><th>Nota</th><th>Quién</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($compras as $c): ?>
                            <tr>
                                <td><?= e(fecha_dmy($c['fecha'])) ?></td>
                                <td><?= e($c['proveedor'] ?: '—') ?></td>
                                <td class="col-num"><?= (int)$c['n_items'] ?></td>
                                <td class="col-num"><?= $c['total'] !== null ? clp($c['total']) : '—' ?></td>
                                <td class="texto-tenue"><?= e($c['nota'] ?: '—') ?></td>
                                <td class="texto-tenue"><?= e($c['usuario'] ?: '—') ?></td>
                                <td><a class="btn sm gris" href="compras.php?negocio_id=<?= $negocioId ?>&ver=<?= (int)$c['id'] ?>">Ver</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($detalle): ?>
        <div class="panel">
            <h2 class="panel-titulo">Detalle de la compra</h2>
            <div class="tabla-wrap">
                <table>
                    <thead><tr>
                        <th>Código</th><th>Producto</th>
                        <th class="col-num">Cantidad</th><th class="col-num">Costo</th><th class="col-num">Total</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($detalle as $d): ?>
                        <tr>
                            <td class="texto-tenue"><?= e($d['codigo']) ?></td>
                            <td><?= e($d['nombre']) ?></td>
                            <td class="col-num"><?= n3c_vista($d['cantidad']) ?></td>
                            <td class="col-num"><?= $d['costo_unitario'] !== null ? clp($d['costo_unitario']) : '—' ?></td>
                            <td class="col-num"><?= $d['total'] !== null ? clp($d['total']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ================= Proveedores ================= -->
        <details class="bloque panel"<?= $proveedores ? '' : ' open' ?>>
            <summary><h2 class="panel-titulo">Proveedores (<?= count($proveedores) ?>)</h2></summary>
            <?php if ($proveedores): ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr><th>Nombre</th><th>RUT</th><th>Teléfono</th><th>Correo</th></tr></thead>
                        <tbody>
                        <?php foreach ($proveedores as $p): ?>
                            <tr>
                                <td><?= e($p['nombre']) ?>
                                    <?php if ($p['negocio_id'] === null): ?>
                                        <span class="badge-inactivo">Los dos negocios</span>
                                    <?php endif; ?>
                                </td>
                                <td class="texto-tenue"><?= e($p['rut'] ?: '—') ?></td>
                                <td class="texto-tenue"><?= e($p['telefono'] ?: '—') ?></td>
                                <td class="texto-tenue"><?= e($p['correo'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <form method="post" class="filtros">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="proveedor">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <div class="campo">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" required placeholder="Distribuidora Los Andes">
                </div>
                <div class="campo">
                    <label>RUT</label>
                    <input type="text" name="rut" placeholder="76.123.456-7" style="width:130px">
                </div>
                <div class="campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" style="width:130px">
                </div>
                <div class="campo">
                    <label>Correo</label>
                    <input type="email" name="correo" style="width:170px">
                </div>
                <div class="campo campo-check">
                    <label><input type="checkbox" name="ambos" value="1"> Sirve para los dos negocios</label>
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit">Agregar</button>
                </div>
            </form>
        </details>
    </div>
    <?php pie_dashboard(); ?>
