<?php
/**
 * Vista de app/Controllers/ComprasController::index().
 *
 * Fase 9 (despliegue): esta pantalla ya es la real (web/compras.php la
 * puentea directo a este Controlador), así que usa el layout completo del
 * panel (cabecera_dashboard/pie_dashboard), igual que el resto del sitio.
 *
 * Variables disponibles: $usuario, $negocios, $negocioId, $error, $aviso,
 * $controla, $proveedores, $compras, $verId, $detalle, $facturas,
 * $verProveedor, $proveedorActual, $productosProveedor.
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
                        <thead><tr><th>Nombre</th><th>RUT</th><th>Teléfono</th><th>Correo</th><th></th></tr></thead>
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
                                <td><a class="btn sm gris" href="compras.php?negocio_id=<?= $negocioId ?>&proveedor=<?= (int)$p['id'] ?>">Productos</a></td>
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

        <?php if ($proveedorActual): ?>
        <div class="panel">
            <h2 class="panel-titulo">Productos de <?= e($proveedorActual['nombre']) ?></h2>
            <?php if ($productosProveedor): ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr><th>Código</th><th>Producto</th><th>Código del proveedor</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($productosProveedor as $pp): ?>
                            <tr>
                                <td class="texto-tenue"><?= e($pp['codigo']) ?></td>
                                <td><?= e($pp['nombre']) ?></td>
                                <td class="texto-tenue"><?= e($pp['codigo_proveedor'] ?: '—') ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('¿Quitar este producto del proveedor?')">
                                        <?= campo_csrf() ?>
                                        <input type="hidden" name="accion" value="quitar_producto">
                                        <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                                        <input type="hidden" name="proveedor_id" value="<?= (int)$proveedorActual['id'] ?>">
                                        <input type="hidden" name="producto_id" value="<?= (int)$pp['id'] ?>">
                                        <button class="btn sm rojo" type="submit">Quitar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="vacio">Todavía no tiene productos asignados.</p>
            <?php endif; ?>

            <form method="post" class="filtros">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="asignar_producto">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <input type="hidden" name="proveedor_id" value="<?= (int)$proveedorActual['id'] ?>">
                <input type="hidden" name="producto_id" id="comboProductoId">
                <div class="campo" style="flex:1 1 260px">
                    <label>Agregar producto</label>
                    <div class="combo-producto" id="comboProducto">
                        <input type="text" id="inputProducto" autocomplete="off"
                               placeholder="Escribe el código o el nombre...">
                        <div class="combo-producto-lista" id="listaComboProducto" hidden>
                            <p class="combo-producto-vacio" hidden>Ningún producto coincide.</p>
                        </div>
                    </div>
                </div>
                <div class="campo">
                    <label>Código del proveedor</label>
                    <input type="text" name="codigo_proveedor" placeholder="Opcional" style="width:150px">
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit" id="btnAsignarProducto" disabled>Agregar</button>
                </div>
            </form>
        </div>
        <script>
          // Buscador de productos para asignarlos al proveedor: mismo patrón que
          // el combo de Departamento (Nuevo/Editar producto), pero busca por AJAX
          // contra compras_producto_buscar.php (el catálogo es demasiado grande
          // para filtrar una lista fija en el cliente).
          (function () {
            var caja = document.getElementById('comboProducto');
            var input = document.getElementById('inputProducto');
            var lista = document.getElementById('listaComboProducto');
            var hiddenId = document.getElementById('comboProductoId');
            var btn = document.getElementById('btnAsignarProducto');
            if (!caja || !input || !lista || !hiddenId || !btn) return;
            var avisoVacio = lista.querySelector('.combo-producto-vacio');
            var timer = null;

            function cerrar() { lista.hidden = true; }

            function pintar(productos) {
              lista.querySelectorAll('.combo-producto-opcion').forEach(function (n) { n.remove(); });
              productos.forEach(function (p) {
                var op = document.createElement('div');
                op.className = 'combo-producto-opcion';
                op.textContent = p.nombre + ' (' + p.codigo + ')';
                op.addEventListener('mousedown', function (e) {
                  e.preventDefault(); // no perder el foco antes del click
                  input.value = p.nombre + ' (' + p.codigo + ')';
                  hiddenId.value = p.id;
                  btn.disabled = false;
                  cerrar();
                });
                lista.insertBefore(op, avisoVacio);
              });
              avisoVacio.hidden = productos.length > 0;
              lista.hidden = false;
            }

            function buscar(q) {
              fetch('compras_producto_buscar.php?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (d) { pintar(d.productos || []); })
                .catch(function () { /* sin conexión: no se rompe el formulario */ });
            }

            input.addEventListener('input', function () {
              hiddenId.value = '';
              btn.disabled = true;
              var q = input.value.trim();
              if (timer) clearTimeout(timer);
              if (q.length < 2) { cerrar(); return; }
              timer = setTimeout(function () { buscar(q); }, 250);
            });
            input.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
            document.addEventListener('click', function (e) {
              if (!caja.contains(e.target)) cerrar();
            });
          })();
        </script>

        <div class="panel">
            <h2 class="panel-titulo">Representantes de <?= e($proveedorActual['nombre']) ?></h2>
            <p class="texto-tenue">
                Cuentas para que el vendedor de este proveedor entre desde su celular a ver
                (solo ver, nunca editar) los productos que distribuye. Ver
                <a href="portal_proveedor.php" target="_blank" rel="noopener">cómo se ve su portal</a>.
            </p>
            <?php if ($representantes): ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr><th>Nombre</th><th>Correo</th><th>Sucursales</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($representantes as $r): ?>
                            <tr>
                                <td><?= e($r['nombre']) ?></td>
                                <td class="texto-tenue"><?= e($r['email']) ?></td>
                                <td class="texto-tenue"><?= e($r['negocios'] ?: '—') ?></td>
                                <td class="nowrap">
                                    <a class="btn sm gris"
                                       href="compras.php?negocio_id=<?= $negocioId ?>&proveedor=<?= (int)$proveedorActual['id'] ?>&editar_rep=<?= (int)$r['id'] ?>#formRepresentante">Editar</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar el acceso de este representante?')">
                                        <?= campo_csrf() ?>
                                        <input type="hidden" name="accion" value="eliminar_representante">
                                        <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                                        <input type="hidden" name="proveedor_id" value="<?= (int)$proveedorActual['id'] ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn sm rojo" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="vacio">Todavía no tiene representantes con acceso.</p>
            <?php endif; ?>

            <?php $rep = $editarRep ?: ['id' => 0, 'email' => '', 'nombre' => '', 'negocios_ids' => []]; ?>
            <form method="post" class="filtros" id="formRepresentante">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="guardar_representante">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <input type="hidden" name="proveedor_id" value="<?= (int)$proveedorActual['id'] ?>">
                <input type="hidden" name="id" value="<?= (int)$rep['id'] ?>">
                <div class="campo">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" value="<?= e($rep['nombre']) ?>" required>
                </div>
                <div class="campo">
                    <label>Correo *</label>
                    <input type="email" name="email" value="<?= e($rep['email']) ?>" required>
                </div>
                <div class="campo">
                    <label>Contraseña<?= $rep['id'] ? ' (en blanco = no cambiarla)' : ' *' ?></label>
                    <input type="password" name="clave" autocomplete="new-password">
                </div>
                <div class="campo" style="flex-basis:100%">
                    <label>Sucursales que puede ver</label>
                    <?php if ($negociosTodos): ?>
                        <div class="lista-check">
                            <?php foreach ($negociosTodos as $n): ?>
                                <label>
                                    <input type="checkbox" name="negocios[]" value="<?= (int)$n['id'] ?>"
                                        <?= in_array((int)$n['id'], $rep['negocios_ids'] ?? [], true) ? 'checked' : '' ?>>
                                    <span><?= e($n['nombre']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="campo-ayuda">Aún no hay sucursales creadas.</p>
                    <?php endif; ?>
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit"><?= $rep['id'] ? 'Guardar cambios' : 'Crear acceso' ?></button>
                </div>
                <?php if ($rep['id']): ?>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <a class="btn gris" href="compras.php?negocio_id=<?= $negocioId ?>&proveedor=<?= (int)$proveedorActual['id'] ?>">Cancelar</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
