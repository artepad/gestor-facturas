<?php
/**
 * Vista de app/Controllers/IngresosController::pegar().
 * Variables disponibles: $usuario, $negocios, $error.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'ingresos', 'Registrar corte'); ?>
    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-acciones">
                <h2>Registrar corte manualmente</h2>
                <a class="btn gris" href="ingresos.php">Volver</a>
            </div>
            <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
            <div class="panel">
                <form method="post">
                    <?= campo_csrf() ?>
                    <div class="campo">
                        <label>Negocio *</label>
                        <select name="negocio_id" required>
                            <?php foreach ($negocios as $n): ?>
                                <option value="<?= (int)$n['id'] ?>"
                                    <?= (int)($_POST['negocio_id'] ?? 0) === (int)$n['id'] ? 'selected' : '' ?>>
                                    <?= e($n['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Asunto del correo (opcional)</label>
                        <p class="campo-ayuda">Del asunto se obtiene el nombre de la caja (ej. "Caja Principal").</p>
                        <input type="text" name="asunto" value="<?= e($_POST['asunto'] ?? '') ?>"
                               placeholder="Corte del turno de ... de la Caja Principal">
                    </div>
                    <div class="campo">
                        <label>Contenido del correo *</label>
                        <p class="campo-ayuda">Abre el correo del corte, selecciona todo su contenido (Ctrl+A), cópialo y pégalo aquí.</p>
                        <textarea name="cuerpo" rows="14" required
                                  placeholder="Corte del turno&#10;04/Jun/2026 9:53 am al 05/Jun/2026 1:05 am&#10;Cajero : ..."><?= e($_POST['cuerpo'] ?? '') ?></textarea>
                    </div>
                    <div class="form-acciones">
                        <button class="btn" type="submit">Registrar corte</button>
                        <a class="btn gris" href="ingresos.php">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
