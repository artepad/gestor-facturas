<?php
/**
 * Vista de app/Controllers/GastosController::fijos().
 * Variables disponibles: $usuario, $negocios, $categorias, $error, $edit, $fijos,
 * $mismoNegocio, $totalMensual, $valMonto.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'gastos', 'Gastos fijos'); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-rojo">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('gastos') ?></span>
                <h2>Gastos fijos mensuales</h2>
            </div>
            <a class="btn gris" href="gastos.php">Volver</a>
        </div>
        <p class="saludo-sub">Plantillas de gastos que se repiten cada mes. Genéralos con un clic
           desde Gastos → "Generar gastos fijos del mes".</p>

        <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

        <div class="panel">
            <h2><?= $edit ? 'Editar gasto fijo' : 'Nuevo gasto fijo' ?></h2>
            <form method="post" class="filtros">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="<?= $edit ? 'editar' : 'crear' ?>">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
                <?php if (!$edit && count($negocios) > 1): ?>
                <div class="campo">
                    <label>Negocio</label>
                    <select name="negocio_id">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"><?= e($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="negocio_id" value="<?= (int)($edit['negocio_id'] ?? ($idsVisibles[0] ?? 0)) ?>">
                <?php endif; ?>
                <div class="campo">
                    <label>Categoría</label>
                    <select name="categoria_id">
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= $edit && (int)$edit['categoria_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label>Descripción</label>
                    <input type="text" name="descripcion" value="<?= e($edit['descripcion'] ?? '') ?>"
                           placeholder="Ej: Arriendo local">
                </div>
                <div class="campo campo-medio">
                    <label>Monto estimado</label>
                    <input type="text" name="monto_estimado" inputmode="numeric" placeholder="Ej: 300.000"
                           value="<?= e($valMonto) ?>" required>
                </div>
                <div class="campo campo-medio">
                    <label>Día del mes</label>
                    <input type="number" name="dia_mes" min="1" max="31" placeholder="Ej: 5"
                           value="<?= e($edit['dia_mes'] ?? '') ?>">
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit"><?= $edit ? 'Guardar' : 'Agregar' ?></button>
                    <?php if ($edit): ?><a class="btn gris" href="gastos_fijos.php">Cancelar</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="resumen">
            <span><?= count($fijos) ?> plantilla(s) ·
                Total fijo mensual (activas): <strong class="valor-rojo"><?= clp($totalMensual) ?: '$0' ?></strong></span>
        </div>

        <div class="tabla-wrap">
            <table>
                <thead><tr>
                    <?php if (!$mismoNegocio): ?><th>Negocio</th><?php endif; ?>
                    <th>Categoría</th>
                    <th class="col-ocultar-movil">Descripción</th>
                    <th class="col-ocultar-movil">Día</th>
                    <th class="monto-col">Monto</th>
                    <th>Estado</th>
                    <th class="col-accion">Acciones</th>
                </tr></thead>
                <tbody>
                <?php if (!$fijos): ?>
                    <tr><td class="vacio" colspan="7">No hay gastos fijos. Crea el primero arriba.</td></tr>
                <?php else: foreach ($fijos as $f): $fid = (int)$f['id']; ?>
                    <tr>
                        <?php if (!$mismoNegocio): ?><td><?= e($f['negocio']) ?></td><?php endif; ?>
                        <td><?= e($f['categoria']) ?></td>
                        <td class="col-ocultar-movil"><?= e($f['descripcion']) ?: '—' ?></td>
                        <td class="col-ocultar-movil"><?= $f['dia_mes'] ? (int)$f['dia_mes'] : '—' ?></td>
                        <td class="monto-col valor-rojo"><?= clp($f['monto_estimado']) ?></td>
                        <td><span class="mov-badge <?= $f['activo'] ? 'mov-abono' : '' ?>"><?= $f['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
                        <td class="col-accion">
                            <span class="acciones-fila">
                                <a class="btn-icono" title="Editar" aria-label="Editar"
                                   href="gastos_fijos.php?editar=<?= $fid ?>"><?= icono('lapiz') ?></a>
                                <form method="post" class="inline-form">
                                    <?= campo_csrf() ?>
                                    <input type="hidden" name="accion" value="toggle">
                                    <input type="hidden" name="id" value="<?= $fid ?>">
                                    <button class="btn sm gris" type="submit"><?= $f['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                </form>
                                <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar esta plantilla? Los gastos ya generados no se borran.')">
                                    <?= campo_csrf() ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= $fid ?>">
                                    <button class="btn-icono peligro" type="submit" title="Eliminar" aria-label="Eliminar"><?= icono('basurero') ?></button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
