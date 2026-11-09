<?php
/**
 * Vista de app/Controllers/GastosController::formulario().
 * Variables disponibles: $usuario, $negocios, $id, $gasto, $categorias, $error, $titulo.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'gastos', $titulo); ?>
    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-modulo modulo-rojo">
                <div class="cab-modulo-tit">
                    <span class="modulo-badge"><?= icono('gastos') ?></span>
                    <h2><?= $titulo ?></h2>
                </div>
                <a class="btn gris" href="gastos.php">Volver</a>
            </div>
            <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
            <div class="panel">
            <form method="post">
                <?= campo_csrf() ?>
                <?php if (!$id && count($negocios) > 1): ?>
                <div class="campo">
                    <label>Negocio *</label>
                    <select name="negocio_id">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"
                                <?= (int)$gasto['negocio_id'] === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="negocio_id" value="<?= (int)$gasto['negocio_id'] ?>">
                <?php endif; ?>
                <div class="campo">
                    <label>Categoría *</label>
                    <select name="categoria_id">
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= (int)$gasto['categoria_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$categorias): ?>
                        <p class="texto-ayuda">No hay categorías activas. Crea una en
                           <a href="categorias_gasto.php">Categorías</a>.</p>
                    <?php endif; ?>
                </div>
                <div class="campo">
                    <label>Fecha *</label>
                    <input type="date" name="fecha" value="<?= e($gasto['fecha']) ?>" required>
                </div>
                <div class="campo">
                    <label>Monto *</label>
                    <input type="text" name="monto" inputmode="numeric" placeholder="Ej: 45.000"
                           value="<?= e($gasto['monto']) ?>" required>
                </div>
                <div class="campo">
                    <label>Descripción</label>
                    <input type="text" name="descripcion" value="<?= e($gasto['descripcion']) ?>"
                           placeholder="Detalle del gasto (opcional)">
                </div>
                <div class="form-acciones">
                    <button class="btn" type="submit">Guardar</button>
                    <a class="btn gris" href="gastos.php">Cancelar</a>
                    <?php if ($id): ?>
                    <button class="btn rojo" type="submit" form="form-eliminar" style="margin-left:auto"
                            onclick="return confirm('¿Eliminar este gasto? Esta acción no se puede deshacer.')">Eliminar</button>
                    <?php endif; ?>
                </div>
            </form>
            <?php if ($id): ?>
            <form method="post" id="form-eliminar">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="eliminar">
            </form>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
