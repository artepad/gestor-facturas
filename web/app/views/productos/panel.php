<?php
/**
 * Vista de app/Controllers/ProductosController::panel().
 * Variables disponibles: $usuario, $negocios, $negocioId, $mensaje, $ultima,
 * $totalP, $estado, $historial, $q, $encontrados, $msg, $nombreNegocio.
 *
 * Muestra la fecha de última actualización con aviso si está vieja (naranja > 1
 * semana, rojo > 2 semanas) e incluye salvaguardas contra el borrado accidental.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Base de Datos de Productos'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Base de Datos de Productos</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
        </div>

        <?php if (!$negocios): ?>
            <div class="panel"><p class="texto-ayuda">No tienes negocios asignados. Pídele a un administrador que te asigne uno.</p></div>
        <?php else: ?>

        <?php if ($mensaje): ?>
            <div class="aviso-flash <?= $mensaje['tipo'] === 'ok' ? 'flash-ok' : 'flash-error' ?>">
                <?= h($mensaje['texto']) ?>
            </div>
        <?php endif; ?>

        <!-- Selector de negocio -->
        <?php if (count($negocios) > 1): ?>
        <form method="get" class="selector-negocio">
            <label for="negocio">Negocio:</label>
            <select name="negocio" id="negocio" onchange="this.form.submit()">
                <?php foreach ($negocios as $n): ?>
                    <option value="<?= (int)$n['id'] ?>" <?= (int)$n['id'] === $negocioId ? 'selected' : '' ?>>
                        <?= h($n['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <?php if ($msg === 'guardado'): ?>
            <div class="panel exito"><p>Producto guardado. El cambio ya está en los dos negocios.</p></div>
        <?php elseif ($msg === 'eliminado'): ?>
            <div class="panel exito"><p>Producto eliminado del catálogo.</p></div>
        <?php endif; ?>

        <!-- Estado de la base de datos -->
        <div class="panel">
            <div class="bd-estado bd-<?= $estado['nivel'] ?>">
                <span class="bd-punto"></span>
                <div class="bd-estado-txt">
                    <strong><?= h($estado['texto']) ?></strong>
                    <span class="bd-sub">
                        <?php if ($totalP > 0): ?>
                            <?= (int)$totalP ?> productos ·
                            última carga el <?= h(date('d-m-Y H:i', strtotime($ultima['cargado_en']))) ?>
                            <?php if (!empty($ultima['usuario_nombre'])): ?> por <?= h($ultima['usuario_nombre']) ?><?php endif; ?>
                        <?php else: ?>
                            Sube un Excel de Eleventa para crear el catálogo de <?= h($nombreNegocio) ?>.
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Buscar y editar productos del catalogo -->
        <div class="panel">
            <div class="cab-acciones">
                <h2 class="panel-titulo">Buscar y editar productos</h2>
                <div class="cab-botones">
                    <?php if (puede($usuario, 'usuarios')): ?>
                        <a class="btn gris" href="historial_productos.php">Ver historial</a>
                    <?php endif; ?>
                    <a class="btn gris" href="inventario.php">Inventario</a>
                    <a class="btn gris" href="promociones.php">Promociones</a>
                    <a class="btn verde" href="producto_form.php">+ Nuevo producto</a>
                </div>
            </div>
            <p class="texto-ayuda">
                El catálogo es <strong>compartido por los dos negocios</strong>: lo que
                cambies acá se ve en ambos. Cada cambio queda registrado con tu nombre.
            </p>
            <form method="get" class="filtros">
                <div class="campo" style="flex:1 1 260px">
                    <label>Código o nombre</label>
                    <input type="text" name="q" value="<?= h($q) ?>" autocomplete="off"
                           placeholder="Coca Cola o 7801234567890" style="width:100%">
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit">Buscar</button>
                </div>
            </form>
            <?php if ($q !== ''): ?>
                <?php if (!$encontrados): ?>
                    <p class="vacio">No se encontró ningún producto con "<?= h($q) ?>".</p>
                <?php else: ?>
                    <div class="tabla-wrap">
                        <table>
                            <thead><tr>
                                <th>Código</th><th>Producto</th><th>Departamento</th>
                                <th class="col-num">Costo</th><th class="col-num">Venta</th>
                                <th>Última edición</th><th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($encontrados as $p): ?>
                                <tr<?= $p['activo'] ? '' : ' class="fila-inactiva"' ?>>
                                    <td><?= h($p['codigo']) ?></td>
                                    <td>
                                        <?= h($p['nombre']) ?>
                                        <?php if (!$p['activo']): ?>
                                            <span class="badge-inactivo">Oculto</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($p['departamento'] ?: '—') ?></td>
                                    <td class="col-num"><?= $p['precio_costo'] !== null ? clp($p['precio_costo']) : '—' ?></td>
                                    <td class="col-num"><?= clp($p['precio_venta']) ?></td>
                                    <td class="texto-tenue">
                                        <?= h($p['editor'] ?: '—') ?>
                                        <?= $p['actualizado_en'] ? '· ' . h(date('d-m-Y', strtotime($p['actualizado_en']))) : '' ?>
                                    </td>
                                    <td><a class="btn sm" href="producto_form.php?id=<?= (int)$p['id'] ?>">Editar</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($encontrados) >= 40): ?>
                        <p class="texto-tenue">Se muestran los primeros 40. Afina la búsqueda para ver menos.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Cargar nuevo Excel -->
        <div class="panel">
            <h2 class="panel-titulo">Cargar / actualizar base de datos</h2>
            <p class="texto-ayuda">Sube el archivo Excel (<strong>.xlsx</strong>) exportado desde Eleventa.
                Reemplaza por completo el catálogo de <strong><?= h($nombreNegocio) ?></strong>. Si el
                archivo no es válido, el catálogo actual <strong>no se borra</strong>.</p>

            <form method="post" enctype="multipart/form-data">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="cargar">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <div class="bd-carga">
                    <input type="file" name="archivo" id="archivo" accept=".xlsx" required>
                    <button class="btn" type="submit">Subir y actualizar</button>
                </div>
            </form>
        </div>

        <!-- Historial (oculto por defecto, en acordeón) -->
        <?php if ($historial): ?>
        <details class="panel acordeon">
            <summary class="acordeon-titulo">
                <span>Historial de cargas</span>
                <span class="acordeon-chevron"><?= icono('chevron') ?></span>
            </summary>
            <div class="tabla-wrap">
                <table>
                    <thead><tr>
                        <th>Fecha</th><th>Archivo</th><th class="col-num">Productos</th>
                        <th class="col-num">Nuevos</th><th class="col-num">Actualizados</th>
                        <th class="col-num">Omitidos</th><th>Cargado por</th><th>Desde</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($historial as $i => $c): ?>
                        <tr>
                            <td><?= h(date('d-m-Y H:i', strtotime($c['cargado_en']))) ?></td>
                            <td><?= h($c['archivo_nombre'] ?: '—') ?></td>
                            <td class="col-num"><?= (int)$c['total_productos'] ?></td>
                            <td class="col-num"><?= (int)$c['total_nuevos'] ?></td>
                            <td class="col-num"><?= (int)$c['total_actualizados'] ?></td>
                            <td class="col-num"><?= (int)$c['total_omitidos'] ?></td>
                            <td><?= h($c['usuario_nombre'] ?: '—') ?></td>
                            <td><?= h($c['negocio_nombre'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <!-- Zona de peligro -->
        <?php if ($totalP > 0): ?>
        <div class="panel zona-peligro">
            <h2 class="panel-titulo">Zona de peligro</h2>
            <p class="texto-ayuda">Vaciar el catálogo de <strong><?= h($nombreNegocio) ?></strong> elimina sus
                <?= (int)$totalP ?> productos. El Gestor de Etiquetas dejará de encontrarlos hasta que vuelvas a
                cargar un Excel. Para confirmar, escribe <strong>ELIMINAR</strong>.</p>
            <form method="post" onsubmit="return confirm('¿Seguro que quieres vaciar el catálogo de este negocio?')">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="vaciar">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <div class="bd-carga">
                    <input type="text" name="confirmar" id="confirmar" placeholder="Escribe ELIMINAR"
                           autocomplete="off">
                    <button class="btn peligro" type="submit" id="btnVaciar" disabled>Vaciar catálogo</button>
                </div>
            </form>
        </div>
        <script>
          (function () {
            var c = document.getElementById('confirmar');
            var b = document.getElementById('btnVaciar');
            if (c && b) c.addEventListener('input', function () { b.disabled = c.value.trim() !== 'ELIMINAR'; });
          })();
        </script>
        <?php endif; ?>

        <?php endif; /* hay negocios */ ?>
    </div>
    <?php pie_dashboard(); ?>
