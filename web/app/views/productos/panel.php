<?php
/**
 * Vista de app/Controllers/ProductosController::panel().
 * Variables disponibles: $usuario, $negocios, $negocioId, $mensaje, $ultima,
 * $totalP, $estado, $historial, $nombreNegocio.
 *
 * Esta pantalla es el sub-panel de "Cargar Excel / Vaciar catálogo" del
 * módulo Productos (se llega acá desde productos.php, que es la puerta de
 * entrada del módulo desde 2026-11-18). El buscador "Buscar y editar" que
 * vivía acá se sacó: productos.php ya lo hace mejor (con ventas, auditoría,
 * filtros y paginación).
 *
 * Muestra la fecha de última actualización con aviso si está vieja (naranja > 1
 * semana, rojo > 2 semanas) e incluye salvaguardas contra el borrado accidental.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'productos', 'Cargar / actualizar catálogo'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Cargar / actualizar catálogo</h2>
            <a class="btn gris" href="productos.php">Volver</a>
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

        <!-- Cargar nuevo Excel -->
        <div class="panel">
            <h2 class="panel-titulo">Cargar / actualizar base de datos</h2>
            <p class="texto-ayuda">Sube el archivo Excel (<strong>.xlsx</strong>) exportado desde Eleventa.
                Agrega los productos nuevos y <strong>actualiza</strong> los que ya existen (por código de
                barras) en el catálogo compartido por los dos negocios — nunca borra productos que falten
                en el archivo. Si el mismo código ya tiene datos distintos en el otro negocio, usa
                "Unificar catálogos" en vez de subir el Excel directo, para decidir cuál dato dejar.</p>

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
