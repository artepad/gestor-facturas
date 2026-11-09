<?php
/**
 * Vista de app/Controllers/NegociosController::formulario().
 * Variables disponibles: $usuario, $id, $negocio, $error, $titulo.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'negocios', $titulo); ?>

    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-acciones">
                <h2><?= $titulo ?></h2>
                <a class="btn gris" href="negocios.php">Volver</a>
            </div>
            <?php if ($error): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>
            <div class="panel">
            <form method="post">
                <?= campo_csrf() ?>
                <div class="campo">
                    <label>Nombre del negocio *</label>
                    <input type="text" name="nombre" required
                           value="<?= e($negocio['nombre']) ?>">
                </div>
                <div class="campo">
                    <label>RUT</label>
                    <input type="text" name="rut"
                           value="<?= e($negocio['rut']) ?>" placeholder="76.123.456-7">
                </div>
                <div class="campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono"
                           value="<?= e($negocio['telefono']) ?>" placeholder="+56 9 1234 5678">
                </div>
                <div class="campo">
                    <label>Dirección</label>
                    <input type="text" name="direccion"
                           value="<?= e($negocio['direccion']) ?>">
                </div>
                <div class="campo">
                    <label>Correo electrónico</label>
                    <input type="email" name="correo"
                           value="<?= e($negocio['correo']) ?>">
                </div>
                <label class="check-inline">
                    <input type="checkbox" name="activo" <?= $negocio['activo'] ? 'checked' : '' ?>>
                    Negocio activo
                </label>
                <div class="form-acciones">
                    <button class="btn" type="submit">Guardar</button>
                    <a class="btn gris" href="negocios.php">Cancelar</a>
                </div>
            </form>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
