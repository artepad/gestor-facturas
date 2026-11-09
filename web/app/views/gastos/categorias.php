<?php
/**
 * Vista de app/Controllers/GastosController::categorias().
 * Variables disponibles: $usuario, $categorias, $usos, $editId, $error.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'gastos', 'Categorías de gasto'); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-rojo">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('categorias') ?></span>
                <h2>Categorías de gasto</h2>
            </div>
            <a class="btn gris" href="gastos.php">Volver</a>
        </div>

        <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

        <div class="panel">
            <h2>Nueva categoría</h2>
            <form method="post" class="filtros">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="crear">
                <div class="campo campo-busqueda">
                    <label>Nombre</label>
                    <input type="text" name="nombre" placeholder="Ej: Patente municipal" required>
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Agregar</button>
                </div>
            </form>
        </div>

        <div class="tabla-wrap">
            <table>
                <thead><tr>
                    <th>Categoría</th>
                    <th class="col-ocultar-movil">Gastos</th>
                    <th>Estado</th>
                    <th class="col-accion">Acciones</th>
                </tr></thead>
                <tbody>
                <?php if (!$categorias): ?>
                    <tr><td class="vacio" colspan="4">No hay categorías. Crea la primera arriba.</td></tr>
                <?php else: foreach ($categorias as $c): $cid = (int)$c['id']; $n = $usos[$cid] ?? 0; ?>
                    <tr>
                        <td>
                            <?php if ($editId === $cid): ?>
                            <form method="post" class="inline-form" style="gap:6px">
                                <?= campo_csrf() ?>
                                <input type="hidden" name="accion" value="renombrar">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <input type="text" name="nombre" value="<?= e($c['nombre']) ?>" required>
                                <button class="btn sm" type="submit">Guardar</button>
                                <a class="btn sm gris" href="categorias_gasto.php">Cancelar</a>
                            </form>
                            <?php else: ?>
                                <?= e($c['nombre']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-ocultar-movil"><?= $n ?></td>
                        <td>
                            <span class="mov-badge <?= $c['activo'] ? 'mov-abono' : '' ?>">
                                <?= $c['activo'] ? 'Activa' : 'Inactiva' ?></span>
                        </td>
                        <td class="col-accion">
                            <span class="acciones-fila">
                                <a class="btn-icono" title="Renombrar" aria-label="Renombrar"
                                   href="categorias_gasto.php?editar=<?= $cid ?>"><?= icono('lapiz') ?></a>
                                <form method="post" class="inline-form">
                                    <?= campo_csrf() ?>
                                    <input type="hidden" name="accion" value="toggle">
                                    <input type="hidden" name="id" value="<?= $cid ?>">
                                    <button class="btn sm gris" type="submit"><?= $c['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                </form>
                                <?php if ($n === 0): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar esta categoría?')">
                                    <?= campo_csrf() ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= $cid ?>">
                                    <button class="btn-icono peligro" type="submit" title="Eliminar" aria-label="Eliminar"><?= icono('basurero') ?></button>
                                </form>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
