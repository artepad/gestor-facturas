<?php
/**
 * Vista de app/Controllers/NegociosController::tokens().
 * Variables disponibles: $usuario, $id, $negocio, $tokenNuevo, $maquinas.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'negocios', 'Tokens · ' . $negocio['nombre']); ?>

    <div class="contenido">
        <div class="cab-acciones">
            <h2>PCs y tokens · <?= e($negocio['nombre']) ?></h2>
            <a class="btn gris" href="negocios.php">Volver</a>
        </div>

        <?php if ($tokenNuevo): ?>
            <div class="panel exito">
                <h2>Token generado — cópialo ahora</h2>
                <p class="token-aviso">
                    Este token NO se vuelve a mostrar. Pégalo en el
                    <code>config.yaml</code> del PC, en <code>sincronizacion.token</code>.
                </p>
                <div class="token-box"><?= e($tokenNuevo) ?></div>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2>Generar token para un PC</h2>
            <form method="post" class="filtros">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="generar">
                <div class="campo">
                    <label>Nombre del PC (ej. Caja Principal)</label>
                    <input type="text" name="nombre_pc" placeholder="PC Principal" style="min-width:240px">
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn verde" type="submit">Generar token</button>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2>PCs vinculados</h2>
            <?php if (!$maquinas): ?>
                <p class="vacio">Aún no hay PCs. Genera un token arriba.</p>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>PC</th><th>Última sincronización</th><th>Token</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($maquinas as $m): ?>
                            <tr>
                                <td><?= e($m['nombre']) ?></td>
                                <td><?= $m['ultima_sync'] ? e($m['ultima_sync']) : '—' ?></td>
                                <td class="token-oculto">•••••• (oculto por seguridad)</td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar este PC? Su token dejará de funcionar.')">
                                        <?= campo_csrf() ?>
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="maquina_id" value="<?= (int)$m['id'] ?>">
                                        <button class="btn sm rojo" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php pie_dashboard(); ?>
