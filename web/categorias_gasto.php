<?php
/**
 * Categorías de gasto: CRUD simple (crear, renombrar, activar/desactivar,
 * eliminar). Compartidas entre negocios. Solo administrador. Una categoría con
 * gastos o plantillas asociadas no se puede borrar: se desactiva en su lugar.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/gastos.php';

$usuario = exigir_permiso('gastos');
$pdo = obtener_pdo();

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $cid    = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');

    if ($accion === 'crear' || $accion === 'renombrar') {
        if ($nombre === '') {
            $error = 'El nombre de la categoría es obligatorio.';
        } else {
            try {
                if ($accion === 'crear') {
                    $pdo->prepare("INSERT INTO categorias_gasto (nombre, orden) VALUES (?, ?)")
                        ->execute([$nombre, 100]);
                } else {
                    $pdo->prepare("UPDATE categorias_gasto SET nombre = ? WHERE id = ?")
                        ->execute([$nombre, $cid]);
                }
            } catch (PDOException $e) {
                $error = 'Ya existe una categoría con ese nombre.';
            }
        }
    } elseif ($accion === 'toggle') {
        $pdo->prepare("UPDATE categorias_gasto SET activo = 1 - activo WHERE id = ?")->execute([$cid]);
    } elseif ($accion === 'eliminar') {
        // Solo si no tiene gastos ni plantillas asociadas
        $usos  = (int)$pdo->query("SELECT COUNT(*) FROM gastos WHERE categoria_id = " . $cid)->fetchColumn();
        $usos += (int)$pdo->query("SELECT COUNT(*) FROM gastos_fijos WHERE categoria_id = " . $cid)->fetchColumn();
        if ($usos > 0) {
            $error = 'Esa categoría tiene gastos asociados; desactívala en lugar de borrarla.';
        } else {
            $pdo->prepare("DELETE FROM categorias_gasto WHERE id = ?")->execute([$cid]);
        }
    }
    if ($error === '') { header('Location: categorias_gasto.php'); exit; }
}

$categorias = categorias_gasto($pdo, false);
// Conteo de uso por categoría (para mostrar y decidir si se puede borrar)
$usos = [];
foreach ($pdo->query("SELECT categoria_id, COUNT(*) n FROM gastos GROUP BY categoria_id") as $r) {
    $usos[(int)$r['categoria_id']] = (int)$r['n'];
}

$editId = (int)($_GET['editar'] ?? 0);
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

        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

        <div class="panel">
            <h2>Nueva categoría</h2>
            <form method="post" class="filtros">
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
                                <input type="hidden" name="accion" value="renombrar">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <input type="text" name="nombre" value="<?= h($c['nombre']) ?>" required>
                                <button class="btn sm" type="submit">Guardar</button>
                                <a class="btn sm gris" href="categorias_gasto.php">Cancelar</a>
                            </form>
                            <?php else: ?>
                                <?= h($c['nombre']) ?>
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
                                    <input type="hidden" name="accion" value="toggle">
                                    <input type="hidden" name="id" value="<?= $cid ?>">
                                    <button class="btn sm gris" type="submit"><?= $c['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                </form>
                                <?php if ($n === 0): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar esta categoría?')">
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
