<?php
/**
 * Crear, editar o eliminar un gasto.
 *   gasto_form.php           -> crear
 *   gasto_form.php?id=N      -> editar / eliminar
 * Solo administrador, acotado a los negocios visibles del usuario.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/gastos.php';

$usuario = exigir_permiso('gastos');
$pdo = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
$categorias = categorias_gasto($pdo, true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$gasto = [
    'negocio_id'   => $idsVisibles[0] ?? 0,
    'categoria_id' => $categorias[0]['id'] ?? 0,
    'fecha'        => date('Y-m-d'),
    'monto'        => '',
    'descripcion'  => '',
];

if ($id) {
    $st = $pdo->prepare("SELECT * FROM gastos WHERE id = ?");
    $st->execute([$id]);
    $gasto = $st->fetch();
    if (!$gasto || !puede_ver_negocio($usuario, (int)$gasto['negocio_id'])) {
        header('Location: gastos.php');
        exit;
    }
    // El monto se muestra formateado para editar cómodo
    $gasto['monto'] = number_format((float)$gasto['monto'], 0, ',', '.');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar';

    if ($accion === 'eliminar' && $id) {
        $pdo->prepare("DELETE FROM gastos WHERE id = ?")->execute([$id]);
        header('Location: gastos.php');
        exit;
    }

    $datos = [
        'negocio_id'   => (int)($_POST['negocio_id'] ?? 0),
        'categoria_id' => (int)($_POST['categoria_id'] ?? 0),
        'fecha'        => trim($_POST['fecha'] ?? ''),
        'monto'        => parsear_monto($_POST['monto'] ?? ''),
        'descripcion'  => trim($_POST['descripcion'] ?? ''),
    ];
    $catValida = in_array($datos['categoria_id'], array_map(fn($c) => (int)$c['id'], $categorias), true);

    if (!in_array($datos['negocio_id'], $idsVisibles, true)) {
        $error = 'Selecciona un negocio válido.';
    } elseif (!$catValida) {
        $error = 'Selecciona una categoría válida.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['fecha'])) {
        $error = 'La fecha no es válida.';
    } elseif ($datos['monto'] === null || $datos['monto'] <= 0) {
        $error = 'Ingresa un monto válido mayor a 0.';
    } else {
        $desc = $datos['descripcion'] !== '' ? $datos['descripcion'] : null;
        if ($id) {
            // Al editar NO se cambia de negocio (evita fugas entre negocios)
            $pdo->prepare(
                "UPDATE gastos SET categoria_id=?, fecha=?, monto=?, descripcion=? WHERE id=?"
            )->execute([$datos['categoria_id'], $datos['fecha'], $datos['monto'], $desc, $id]);
        } else {
            $pdo->prepare(
                "INSERT INTO gastos (negocio_id, categoria_id, fecha, monto, descripcion, creado_por)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$datos['negocio_id'], $datos['categoria_id'], $datos['fecha'],
                        $datos['monto'], $desc, (int)$usuario['id']]);
        }
        header('Location: gastos.php');
        exit;
    }
    // Reponer lo escrito si hubo error (monto como texto)
    $gasto = array_merge($gasto, $datos);
    $gasto['monto'] = $_POST['monto'] ?? '';
}

$titulo = $id ? 'Editar gasto' : 'Nuevo gasto';
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
            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
            <div class="panel">
            <form method="post">
                <?php if (!$id && count($negocios) > 1): ?>
                <div class="campo">
                    <label>Negocio *</label>
                    <select name="negocio_id">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"
                                <?= (int)$gasto['negocio_id'] === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= h($n['nombre']) ?></option>
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
                                <?= h($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$categorias): ?>
                        <p class="texto-ayuda">No hay categorías activas. Crea una en
                           <a href="categorias_gasto.php">Categorías</a>.</p>
                    <?php endif; ?>
                </div>
                <div class="campo">
                    <label>Fecha *</label>
                    <input type="date" name="fecha" value="<?= h($gasto['fecha']) ?>" required>
                </div>
                <div class="campo">
                    <label>Monto *</label>
                    <input type="text" name="monto" inputmode="numeric" placeholder="Ej: 45.000"
                           value="<?= h($gasto['monto']) ?>" required>
                </div>
                <div class="campo">
                    <label>Descripción</label>
                    <input type="text" name="descripcion" value="<?= h($gasto['descripcion']) ?>"
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
                <input type="hidden" name="accion" value="eliminar">
            </form>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
