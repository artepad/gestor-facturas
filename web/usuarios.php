<?php
/**
 * Gestión de usuarios del dashboard. Solo admin.
 * Lista, crea, edita (clave opcional), asigna negocios y elimina usuarios.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_login();
if (($usuario['rol'] ?? '') !== 'admin') {
    header('Location: panel.php');
    exit;
}

$pdo = obtener_pdo();
$error = '';
$exito = '';

// --- Acciones POST ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'guardar') {
        $uid    = (int)($_POST['id'] ?? 0);
        $email  = trim($_POST['email'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $clave  = $_POST['clave'] ?? '';
        $rol    = ($_POST['rol'] ?? 'sucursal') === 'admin' ? 'admin' : 'sucursal';
        $negociosSel = array_map('intval', $_POST['negocios'] ?? []);

        if ($email === '') {
            $error = 'El correo es obligatorio.';
        } elseif (!$uid && strlen($clave) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            try {
                if ($uid) {
                    if ($clave !== '') {
                        $pdo->prepare("UPDATE usuarios SET email=?, nombre=?, rol=?, password_hash=? WHERE id=?")
                            ->execute([$email, $nombre, $rol, password_hash($clave, PASSWORD_DEFAULT), $uid]);
                    } else {
                        $pdo->prepare("UPDATE usuarios SET email=?, nombre=?, rol=? WHERE id=?")
                            ->execute([$email, $nombre, $rol, $uid]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO usuarios (email, nombre, rol, password_hash) VALUES (?,?,?,?)")
                        ->execute([$email, $nombre, $rol, password_hash($clave, PASSWORD_DEFAULT)]);
                    $uid = (int)$pdo->lastInsertId();
                }
                // Reasignar negocios
                $pdo->prepare("DELETE FROM usuario_negocio WHERE usuario_id=?")->execute([$uid]);
                if ($rol === 'sucursal' && $negociosSel) {
                    $ins = $pdo->prepare("INSERT INTO usuario_negocio (usuario_id, negocio_id) VALUES (?,?)");
                    foreach ($negociosSel as $nid) { $ins->execute([$uid, $nid]); }
                }
                $exito = 'Usuario guardado.';
            } catch (Throwable $e) {
                $error = (str_contains($e->getMessage(), 'Duplicate'))
                    ? 'Ya existe un usuario con ese correo.'
                    : 'Error al guardar.';
            }
        }
    } elseif ($accion === 'eliminar') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid === (int)$usuario['id']) {
            $error = 'No puedes eliminar tu propio usuario.';
        } else {
            $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$uid]);
            $exito = 'Usuario eliminado.';
        }
    }
}

// --- Datos ---
$usuarios = $pdo->query(
    "SELECT u.*, GROUP_CONCAT(n.nombre SEPARATOR ', ') AS negocios
     FROM usuarios u
     LEFT JOIN usuario_negocio un ON un.usuario_id = u.id
     LEFT JOIN negocios n ON n.id = un.negocio_id
     GROUP BY u.id ORDER BY u.nombre, u.email"
)->fetchAll();
$negociosTodos = $pdo->query("SELECT id, nombre FROM negocios ORDER BY nombre")->fetchAll();

// Usuario en edición
$editar = null;
if (isset($_GET['editar'])) {
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE id=?");
    $st->execute([(int)$_GET['editar']]);
    $editar = $st->fetch() ?: null;
    if ($editar) {
        $st = $pdo->prepare("SELECT negocio_id FROM usuario_negocio WHERE usuario_id=?");
        $st->execute([$editar['id']]);
        $editar['negocios_ids'] = array_map('intval', array_column($st->fetchAll(), 'negocio_id'));
    }
}

function h($v) { return htmlspecialchars((string)($v ?? '')); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios · Sistema de Gestión</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
    <?php cabecera_dashboard($usuario, 'usuarios'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Usuarios</h2>
            <a class="btn" href="usuarios.php?editar=0">+ Crear usuario</a>
        </div>

        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <?php if ($exito): ?>
            <div class="error" style="background:#e8f5e9;color:#1a7a3a;border-color:#c8e6c9"><?= h($exito) ?></div>
        <?php endif; ?>

        <?php if ($editar !== null || isset($_GET['editar'])):
            $e = $editar ?: ['id'=>0,'email'=>'','nombre'=>'','rol'=>'sucursal','negocios_ids'=>[]]; ?>
        <div class="panel" style="max-width:560px">
            <h2><?= $e['id'] ? 'Editar usuario' : 'Crear usuario' ?></h2>
            <form method="post">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                <div class="campo" style="margin-bottom:12px">
                    <label>Correo *</label>
                    <input type="email" name="email" required style="width:100%" value="<?= h($e['email']) ?>">
                </div>
                <div class="campo" style="margin-bottom:12px">
                    <label>Nombre</label>
                    <input type="text" name="nombre" style="width:100%" value="<?= h($e['nombre']) ?>">
                </div>
                <div class="campo" style="margin-bottom:12px">
                    <label>Contraseña <?= $e['id'] ? '(dejar vacío para no cambiarla)' : '(mín. 6)' ?></label>
                    <input type="password" name="clave" style="width:100%" <?= $e['id'] ? '' : 'required' ?>>
                </div>
                <div class="campo" style="margin-bottom:12px">
                    <label>Rol</label>
                    <select name="rol" id="selRol" style="width:100%">
                        <option value="admin" <?= $e['rol']==='admin'?'selected':'' ?>>Administrador (ve todo)</option>
                        <option value="sucursal" <?= $e['rol']==='sucursal'?'selected':'' ?>>Sucursal (negocios asignados)</option>
                    </select>
                </div>
                <div class="campo" id="boxNegocios" style="margin-bottom:16px">
                    <label>Negocios asignados (solo para Sucursal)</label>
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <?php foreach ($negociosTodos as $n): ?>
                            <label style="display:flex;gap:8px;font-size:14px;font-weight:400">
                                <input type="checkbox" name="negocios[]" value="<?= (int)$n['id'] ?>"
                                    <?= in_array((int)$n['id'], $e['negocios_ids'] ?? [], true) ? 'checked':'' ?>>
                                <?= h($n['nombre']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="btn" type="submit">Guardar</button>
                <a class="btn gris" href="usuarios.php">Cancelar</a>
            </form>
        </div>
        <?php endif; ?>

        <div class="tabla-wrap" style="margin-top:18px">
            <table>
                <thead><tr>
                    <th>Nombre</th><th>Correo</th><th>Rol</th><th>Negocios</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$usuarios): ?>
                    <tr><td class="vacio" colspan="5">No hay usuarios.</td></tr>
                <?php else: foreach ($usuarios as $u): ?>
                    <tr>
                        <td><?= h($u['nombre']) ?></td>
                        <td><?= h($u['email']) ?></td>
                        <td><?= $u['rol']==='admin' ? 'Administrador' : 'Sucursal' ?></td>
                        <td><?= $u['rol']==='admin' ? '<em style="color:#aaa">todos</em>' : h($u['negocios'] ?: '—') ?></td>
                        <td style="white-space:nowrap">
                            <a class="btn sm gris" href="usuarios.php?editar=<?= (int)$u['id'] ?>">Editar</a>
                            <?php if ((int)$u['id'] !== (int)$usuario['id']): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este usuario?')">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn sm" type="submit" style="background:#dc3545">Eliminar</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
    <script>
      // Mostrar/ocultar negocios según el rol
      var sel = document.getElementById('selRol');
      var box = document.getElementById('boxNegocios');
      function actualizar() { if (sel && box) box.style.display = sel.value === 'sucursal' ? '' : 'none'; }
      if (sel) { sel.addEventListener('change', actualizar); actualizar(); }
    </script>
</body>
</html>
