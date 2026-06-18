<?php
/**
 * Crear, editar o eliminar un producto de la lista de Frutas y Verduras.
 *   precio_form.php        -> crear
 *   precio_form.php?id=N   -> editar / eliminar
 * Editan admin y vendedores (cualquiera con el módulo 'precios').
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('precios');
$pdo = obtener_pdo();

// Unidades de venta comunes (frutas/verduras)
$UNIDADES = ['kilo', '½ kilo', 'unidad', 'bandeja', 'malla', 'atado', 'docena', 'caja', 'paquete'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$prod = ['nombre' => '', 'precio' => '', 'unidad' => 'kilo', 'observacion' => '', 'activo' => 1];

if ($id) {
    $st = $pdo->prepare("SELECT * FROM precios_fv WHERE id = ?");
    $st->execute([$id]);
    $prod = $st->fetch();
    if (!$prod) { header('Location: precios.php'); exit; }
    $prod['precio'] = number_format((float)$prod['precio'], 0, ',', '.');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar';

    if ($accion === 'eliminar' && $id) {
        $pdo->prepare("DELETE FROM precios_fv WHERE id = ?")->execute([$id]);
        header('Location: precios.php');
        exit;
    }

    $datos = [
        'nombre'      => trim($_POST['nombre'] ?? ''),
        'precio'      => parsear_monto($_POST['precio'] ?? ''),
        'unidad'      => trim($_POST['unidad'] ?? 'kilo'),
        'observacion' => trim($_POST['observacion'] ?? ''),
        'activo'      => isset($_POST['activo']) ? 1 : 0,
    ];
    if (!in_array($datos['unidad'], $UNIDADES, true)) $datos['unidad'] = 'kilo';

    if ($datos['nombre'] === '') {
        $error = 'El nombre del producto es obligatorio.';
    } elseif ($datos['precio'] === null || $datos['precio'] <= 0) {
        $error = 'Ingresa un precio válido mayor a 0.';
    } else {
        $obs = $datos['observacion'] !== '' ? $datos['observacion'] : null;
        if ($id) {
            $pdo->prepare(
                "UPDATE precios_fv SET nombre=?, precio=?, unidad=?, observacion=?, activo=?,
                        actualizado_por=?, actualizado_en=CURRENT_TIMESTAMP WHERE id=?"
            )->execute([$datos['nombre'], $datos['precio'], $datos['unidad'], $obs,
                        $datos['activo'], (int)$usuario['id'], $id]);
        } else {
            $pdo->prepare(
                "INSERT INTO precios_fv (nombre, precio, unidad, observacion, activo, actualizado_por)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$datos['nombre'], $datos['precio'], $datos['unidad'], $obs,
                        $datos['activo'], (int)$usuario['id']]);
        }
        header('Location: precios.php');
        exit;
    }
    // Reponer lo escrito si hubo error
    $prod = array_merge($prod, $datos);
    $prod['precio'] = $_POST['precio'] ?? '';
}

$titulo = $id ? 'Editar producto' : 'Nuevo producto';
?>
<?php cabecera_dashboard($usuario, 'precios', $titulo); ?>
    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-modulo modulo-verde">
                <div class="cab-modulo-tit">
                    <span class="modulo-badge"><?= icono('precios') ?></span>
                    <h2><?= $titulo ?></h2>
                </div>
                <a class="btn gris" href="precios.php">Volver</a>
            </div>
            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
            <div class="panel">
            <form method="post">
                <div class="campo">
                    <label>Producto *</label>
                    <input type="text" name="nombre" required value="<?= h($prod['nombre']) ?>"
                           placeholder="Ej: Tomate" autofocus>
                </div>
                <div class="campo campo-medio">
                    <label>Precio *</label>
                    <input type="text" name="precio" inputmode="numeric" placeholder="Ej: 1.200"
                           value="<?= h($prod['precio']) ?>" required>
                </div>
                <div class="campo campo-medio">
                    <label>Unidad</label>
                    <select name="unidad">
                        <?php foreach ($UNIDADES as $u): ?>
                            <option value="<?= h($u) ?>" <?= $prod['unidad'] === $u ? 'selected' : '' ?>><?= h($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label>Observación</label>
                    <input type="text" name="observacion" value="<?= h($prod['observacion']) ?>"
                           placeholder="Ej: de la feria, oferta de la semana (opcional)">
                </div>
                <div class="campo">
                    <label class="check-inline">
                        <input type="checkbox" name="activo" <?= !empty($prod['activo']) ? 'checked' : '' ?>>
                        Visible en la lista
                    </label>
                </div>
                <div class="form-acciones">
                    <button class="btn" type="submit">Guardar</button>
                    <a class="btn gris" href="precios.php">Cancelar</a>
                    <?php if ($id): ?>
                    <button class="btn rojo" type="submit" form="form-eliminar" style="margin-left:auto"
                            onclick="return confirm('¿Eliminar este producto de la lista?')">Eliminar</button>
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
