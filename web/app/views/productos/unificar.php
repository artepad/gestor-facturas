<?php
/**
 * Vista de app/Controllers/ProductosController::unificar() — paso 1 (sin ?id).
 * Variables disponibles: $usuario, $negocios, $historial, $error.
 *
 * Elige los dos negocios y sube sus dos Excel de Eleventa para compararlos
 * antes de fusionarlos en el catálogo compartido. No escribe nada todavía:
 * eso ocurre en la pantalla de revisión (unificar_revision.php), después de
 * resolver cada diferencia.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'productos', 'Unificar catálogos'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Unificar catálogos</h2>
            <a class="btn gris" href="productos.php">Volver</a>
        </div>

        <?php if ($error): ?>
            <div class="aviso-flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if (count($negocios) < 2): ?>
            <div class="panel"><p class="texto-ayuda">Necesitas al menos dos negocios creados para poder compararlos.</p></div>
        <?php else: ?>

        <div class="panel">
            <h2 class="panel-titulo"><?= icono('comparar') ?> Comparar los dos Excel</h2>
            <p class="texto-ayuda">
                Usa esto cuando cada negocio tenga su propio Excel de Eleventa y quieras
                fusionarlos en un solo catálogo sin que uno le pise los precios al otro.
                Sube los dos archivos: el sistema cruza los productos por código de barras
                (y por nombre, para los que no calcen) y te deja decidir cada diferencia
                antes de guardar nada. <strong>No se toca el catálogo real hasta que confirmes.</strong>
            </p>
            <form method="post" enctype="multipart/form-data" class="form-angosto" style="max-width:420px">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="comparar">
                <div class="campo">
                    <label>Primer negocio</label>
                    <select name="negocio_a_id" required>
                        <option value="">Elige un negocio</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"><?= h($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label>Excel del primer negocio (.xlsx)</label>
                    <input type="file" name="archivo_a" accept=".xlsx" required>
                </div>
                <div class="campo">
                    <label>Segundo negocio</label>
                    <select name="negocio_b_id" required>
                        <option value="">Elige un negocio</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"><?= h($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label>Excel del segundo negocio (.xlsx)</label>
                    <input type="file" name="archivo_b" accept=".xlsx" required>
                </div>
                <button class="btn" type="submit">Comparar</button>
            </form>
        </div>

        <?php if ($historial): ?>
        <details class="panel acordeon">
            <summary class="acordeon-titulo">
                <span>Unificaciones anteriores</span>
                <span class="acordeon-chevron"><?= icono('chevron') ?></span>
            </summary>
            <div class="tabla-wrap">
                <table>
                    <thead><tr>
                        <th>Fecha</th><th>Negocios</th><th>Estado</th>
                        <th class="col-num">Coinciden</th><th class="col-num">Conflictos</th>
                        <th class="col-num">A revisar</th><th>Hecho por</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($historial as $u): ?>
                        <tr>
                            <td><?= h(date('d-m-Y H:i', strtotime($u['creado_en']))) ?></td>
                            <td><?= h($u['negocio_a_nombre']) ?> + <?= h($u['negocio_b_nombre']) ?></td>
                            <td>
                                <?php if ($u['estado'] === 'aplicada'): ?>
                                    <span class="badge-inactivo badge-aplicada">Aplicada</span>
                                <?php elseif ($u['estado'] === 'descartada'): ?>
                                    <span class="badge-inactivo">Descartada</span>
                                <?php else: ?>
                                    <span class="badge-inactivo badge-revisando">En revisión</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-num"><?= (int)$u['total_igual'] ?></td>
                            <td class="col-num"><?= (int)$u['total_conflicto'] ?></td>
                            <td class="col-num"><?= (int)$u['total_revisar'] ?></td>
                            <td><?= h($u['creado_por_nombre'] ?: '—') ?></td>
                            <td><a class="btn sm" href="herramienta_unificar_productos.php?id=<?= (int)$u['id'] ?>">Ver</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
