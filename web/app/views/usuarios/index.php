<?php
/**
 * Vista de app/Controllers/UsuariosController::index().
 * Variables disponibles: $usuario, $error, $exito, $usuarios, $negociosTodos,
 * $editar, $modoFormulario.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
require_once dirname(__DIR__, 3) . '/lib/permisos.php'; // roles_disponibles(), nombre_rol()
?>
<?php cabecera_dashboard($usuario, 'usuarios', 'Usuarios'); ?>
    <div class="contenido">
        <?php if ($modoFormulario):
            $e = $editar ?: ['id'=>0,'email'=>'','nombre'=>'','rol'=>'vendedor','negocios_ids'=>[]]; ?>
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-acciones">
                <h2><?= $e['id'] ? 'Editar usuario' : 'Crear usuario' ?></h2>
                <a class="btn gris" href="usuarios.php">Volver</a>
            </div>
            <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
            <?php if ($exito): ?><div class="mensaje-exito"><?= e($exito) ?></div><?php endif; ?>
            <div class="panel">
            <form method="post" autocomplete="off">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                <div class="campo">
                    <label>Correo *</label>
                    <input type="email" name="email" required value="<?= e($e['email']) ?>" autocomplete="off">
                </div>
                <div class="campo">
                    <label>Nombre</label>
                    <input type="text" name="nombre" value="<?= e($e['nombre']) ?>" autocomplete="off">
                </div>
                <div class="campo">
                    <label>Contraseña <?= $e['id'] ? '(dejar vacío para no cambiarla)' : '(mín. 6)' ?></label>
                    <input type="password" name="clave" <?= $e['id'] ? '' : 'required' ?> autocomplete="new-password">
                </div>
                <div class="campo">
                    <label>PIN de la caja (4 a 6 números)</label>
                    <input type="text" name="pin" inputmode="numeric" maxlength="6"
                           autocomplete="off" placeholder="<?= $e['id'] && !empty($e['pin_hash']) ? 'Ya tiene PIN — escribe uno nuevo para cambiarlo' : 'Opcional' ?>">
                    <small class="texto-tenue">
                        Sirve para desbloquear la caja en el PC, incluso sin internet.
                        Es un cerrojo local: no reemplaza la contraseña.
                    </small>
                </div>
                <div class="campo">
                    <label>Rol</label>
                    <select name="rol" id="selRol">
                        <?php foreach (\roles_disponibles() as $rolClave => $rolNombre): ?>
                            <option value="<?= e($rolClave) ?>" <?= $e['rol']===$rolClave?'selected':'' ?>><?= e($rolNombre) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo" id="boxNegocios">
                    <label>Negocios asignados</label>
                    <p class="campo-ayuda">El usuario solo verá los negocios que marques.</p>
                    <?php if ($negociosTodos): ?>
                    <div class="lista-check">
                        <?php foreach ($negociosTodos as $n): ?>
                            <label>
                                <input type="checkbox" name="negocios[]" value="<?= (int)$n['id'] ?>"
                                    <?= in_array((int)$n['id'], $e['negocios_ids'] ?? [], true) ? 'checked':'' ?>>
                                <span><?= e($n['nombre']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="campo-ayuda">Aún no hay negocios creados.</p>
                    <?php endif; ?>
                </div>
                <div class="form-acciones">
                    <button class="btn" type="submit">Guardar</button>
                    <a class="btn gris" href="usuarios.php">Cancelar</a>
                </div>
            </form>
            </div>
        </div>
        <?php else: ?>
        <div class="cab-acciones">
            <h2>Usuarios</h2>
            <a class="btn" href="usuarios.php?editar=0">+ Crear usuario</a>
        </div>
        <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($exito): ?><div class="mensaje-exito"><?= e($exito) ?></div><?php endif; ?>
        <div class="tabla-wrap">
            <table>
                <thead><tr>
                    <th>Nombre</th><th>Correo</th><th>Rol</th><th>Negocios</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$usuarios): ?>
                    <tr><td class="vacio" colspan="5">No hay usuarios.</td></tr>
                <?php else: foreach ($usuarios as $u): ?>
                    <tr>
                        <td><?= e($u['nombre']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><?= e(\nombre_rol($u['rol'])) ?></td>
                        <td><?= $u['rol']==='admin' ? '<em style="color:#aaa">todos</em>' : e($u['negocios'] ?: '—') ?></td>
                        <td class="nowrap">
                            <a class="btn sm gris" href="usuarios.php?editar=<?= (int)$u['id'] ?>">Editar</a>
                            <?php if ((int)$u['id'] !== (int)$usuario['id']): ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar este usuario?')">
                                <?= campo_csrf() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn sm rojo" type="submit">Eliminar</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <script>
      // Mostrar/ocultar negocios según el rol
      var sel = document.getElementById('selRol');
      var box = document.getElementById('boxNegocios');
      function actualizar() { if (sel && box) box.style.display = sel.value !== 'admin' ? '' : 'none'; }
      if (sel) { sel.addEventListener('change', actualizar); actualizar(); }
    </script>
    <?php pie_dashboard(); ?>
