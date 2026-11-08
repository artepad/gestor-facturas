<?php
/**
 * Vista de app/Controllers/FiadosController::formulario().
 * Variables disponibles: $usuario, $negocios, $id, $cliente, $error, $titulo.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'fiados', $titulo); ?>

    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-modulo modulo-morado">
                <div class="cab-modulo-tit">
                    <span class="modulo-badge"><?= icono('fiados') ?></span>
                    <h2><?= $titulo ?></h2>
                </div>
                <a class="btn gris" href="<?= $id ? 'cliente.php?id=' . $id : 'fiados.php' ?>">Volver</a>
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
                                <?= (int)$cliente['negocio_id'] === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="negocio_id" value="<?= (int)$cliente['negocio_id'] ?>">
                <?php endif; ?>
                <div class="campo">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" required value="<?= e($cliente['nombre']) ?>">
                </div>
                <div class="campo">
                    <label>Apellido</label>
                    <input type="text" name="apellido" value="<?= e($cliente['apellido']) ?>">
                </div>
                <div class="campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" value="<?= e($cliente['telefono']) ?>"
                           placeholder="+56 9 1234 5678">
                </div>
                <div class="campo">
                    <label>Dirección</label>
                    <input type="text" name="direccion" value="<?= e($cliente['direccion']) ?>">
                </div>
                <div class="campo">
                    <label>Correo electrónico</label>
                    <input type="email" name="correo" value="<?= e($cliente['correo']) ?>">
                </div>
                <div class="form-acciones">
                    <button class="btn" type="submit">Guardar</button>
                    <a class="btn gris" href="<?= $id ? 'cliente.php?id=' . $id : 'fiados.php' ?>">Cancelar</a>
                </div>
            </form>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
