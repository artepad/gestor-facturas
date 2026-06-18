<?php
/**
 * Gastos fijos: plantillas de gasto mensual recurrente (sueldos, arriendo...).
 * Solo administrador, acotado a los negocios visibles. Desde aquí se crean/editan
 * las plantillas; los gastos reales del mes se generan con un clic en gastos.php
 * ("Generar gastos fijos del mes"), que es idempotente.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/gastos.php';

$usuario = exigir_permiso('gastos');
$pdo = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
$categorias = categorias_gasto($pdo, true);
$idsCat = array_map(fn($c) => (int)$c['id'], $categorias);

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $gid    = (int)($_POST['id'] ?? 0);

    // Verifica que una plantilla existente sea de un negocio visible
    $duenoOk = function (int $gid) use ($pdo, $usuario): bool {
        $st = $pdo->prepare("SELECT negocio_id FROM gastos_fijos WHERE id = ?");
        $st->execute([$gid]);
        $row = $st->fetch();
        return $row && puede_ver_negocio($usuario, (int)$row['negocio_id']);
    };

    if ($accion === 'toggle' && $gid && $duenoOk($gid)) {
        $pdo->prepare("UPDATE gastos_fijos SET activo = 1 - activo WHERE id = ?")->execute([$gid]);
        header('Location: gastos_fijos.php'); exit;
    } elseif ($accion === 'eliminar' && $gid && $duenoOk($gid)) {
        $pdo->prepare("DELETE FROM gastos_fijos WHERE id = ?")->execute([$gid]);
        header('Location: gastos_fijos.php'); exit;
    } elseif ($accion === 'crear' || $accion === 'editar') {
        $negocioId = (int)($_POST['negocio_id'] ?? 0);
        $catId     = (int)($_POST['categoria_id'] ?? 0);
        $desc      = trim($_POST['descripcion'] ?? '');
        $monto     = parsear_monto($_POST['monto_estimado'] ?? '');
        $diaMes    = (int)($_POST['dia_mes'] ?? 0);
        $diaMes    = ($diaMes >= 1 && $diaMes <= 31) ? $diaMes : null;

        if ($accion === 'editar' && !$duenoOk($gid)) {
            header('Location: gastos_fijos.php'); exit;
        } elseif (!in_array($negocioId, $idsVisibles, true)) {
            $error = 'Selecciona un negocio válido.';
        } elseif (!in_array($catId, $idsCat, true)) {
            $error = 'Selecciona una categoría válida.';
        } elseif ($monto === null || $monto <= 0) {
            $error = 'Ingresa un monto estimado válido.';
        } else {
            $d = $desc !== '' ? $desc : null;
            if ($accion === 'crear') {
                $pdo->prepare(
                    "INSERT INTO gastos_fijos (negocio_id, categoria_id, descripcion, monto_estimado, dia_mes)
                     VALUES (?,?,?,?,?)"
                )->execute([$negocioId, $catId, $d, $monto, $diaMes]);
            } else {
                // No se cambia de negocio al editar (evita fugas entre negocios)
                $pdo->prepare(
                    "UPDATE gastos_fijos SET categoria_id=?, descripcion=?, monto_estimado=?, dia_mes=? WHERE id=?"
                )->execute([$catId, $d, $monto, $diaMes, $gid]);
            }
            header('Location: gastos_fijos.php'); exit;
        }
    }
}

// ¿Editando una plantilla?
$edit = null;
$editId = (int)($_GET['editar'] ?? 0);
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM gastos_fijos WHERE id = ?");
    $st->execute([$editId]);
    $edit = $st->fetch();
    if (!$edit || !puede_ver_negocio($usuario, (int)$edit['negocio_id'])) $edit = null;
}

// Listado de plantillas de los negocios visibles
$fijos = [];
if ($idsVisibles) {
    $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
    $st = $pdo->prepare(
        "SELECT gf.*, cg.nombre AS categoria, n.nombre AS negocio
         FROM gastos_fijos gf
         JOIN categorias_gasto cg ON cg.id = gf.categoria_id
         JOIN negocios n ON n.id = gf.negocio_id
         WHERE gf.negocio_id IN ($ph)
         ORDER BY n.nombre, cg.nombre"
    );
    $st->execute($idsVisibles);
    $fijos = $st->fetchAll();
}
$mismoNegocio = count($negocios) === 1;
$totalMensual = 0;
foreach ($fijos as $f) { if ($f['activo']) $totalMensual += (float)$f['monto_estimado']; }

$valMonto = $edit ? number_format((float)$edit['monto_estimado'], 0, ',', '.') : '';
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

        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

        <div class="panel">
            <h2><?= $edit ? 'Editar gasto fijo' : 'Nuevo gasto fijo' ?></h2>
            <form method="post" class="filtros">
                <input type="hidden" name="accion" value="<?= $edit ? 'editar' : 'crear' ?>">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
                <?php if (!$edit && count($negocios) > 1): ?>
                <div class="campo">
                    <label>Negocio</label>
                    <select name="negocio_id">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"><?= h($n['nombre']) ?></option>
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
                                <?= h($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label>Descripción</label>
                    <input type="text" name="descripcion" value="<?= h($edit['descripcion'] ?? '') ?>"
                           placeholder="Ej: Arriendo local">
                </div>
                <div class="campo campo-medio">
                    <label>Monto estimado</label>
                    <input type="text" name="monto_estimado" inputmode="numeric" placeholder="Ej: 300.000"
                           value="<?= h($valMonto) ?>" required>
                </div>
                <div class="campo campo-medio">
                    <label>Día del mes</label>
                    <input type="number" name="dia_mes" min="1" max="31" placeholder="Ej: 5"
                           value="<?= h($edit['dia_mes'] ?? '') ?>">
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
                        <?php if (!$mismoNegocio): ?><td><?= h($f['negocio']) ?></td><?php endif; ?>
                        <td><?= h($f['categoria']) ?></td>
                        <td class="col-ocultar-movil"><?= h($f['descripcion']) ?: '—' ?></td>
                        <td class="col-ocultar-movil"><?= $f['dia_mes'] ? (int)$f['dia_mes'] : '—' ?></td>
                        <td class="monto-col valor-rojo"><?= clp($f['monto_estimado']) ?></td>
                        <td><span class="mov-badge <?= $f['activo'] ? 'mov-abono' : '' ?>"><?= $f['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
                        <td class="col-accion">
                            <span class="acciones-fila">
                                <a class="btn-icono" title="Editar" aria-label="Editar"
                                   href="gastos_fijos.php?editar=<?= $fid ?>"><?= icono('lapiz') ?></a>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="accion" value="toggle">
                                    <input type="hidden" name="id" value="<?= $fid ?>">
                                    <button class="btn sm gris" type="submit"><?= $f['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                </form>
                                <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar esta plantilla? Los gastos ya generados no se borran.')">
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
