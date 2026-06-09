<?php
/**
 * Crear o editar un cliente de fiados.
 *   cliente_form.php           -> crear
 *   cliente_form.php?id=N       -> editar
 * Acceso admin+sucursal, acotado a los negocios visibles del usuario.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_login();
$pdo = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cliente = [
    'negocio_id' => $idsVisibles[0] ?? 0,
    'nombre' => '', 'apellido' => '', 'telefono' => '',
    'direccion' => '', 'correo' => '',
];

if ($id) {
    $st = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $st->execute([$id]);
    $cliente = $st->fetch();
    // No existe o no es de un negocio visible -> fuera
    if (!$cliente || !puede_ver_negocio($usuario, (int)$cliente['negocio_id'])) {
        header('Location: fiados.php');
        exit;
    }
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $datos = [
        'negocio_id' => (int)($_POST['negocio_id'] ?? 0),
        'nombre'     => trim($_POST['nombre'] ?? ''),
        'apellido'   => trim($_POST['apellido'] ?? ''),
        'telefono'   => trim($_POST['telefono'] ?? ''),
        'direccion'  => trim($_POST['direccion'] ?? ''),
        'correo'     => trim($_POST['correo'] ?? ''),
    ];
    // El negocio elegido debe ser uno que el usuario puede ver
    if (!in_array($datos['negocio_id'], $idsVisibles, true)) {
        $error = 'Selecciona un negocio válido.';
    } elseif ($datos['nombre'] === '') {
        $error = 'El nombre del cliente es obligatorio.';
    } else {
        if ($id) {
            // Al editar NO se permite cambiar de negocio (evita fugas entre negocios)
            $pdo->prepare(
                "UPDATE clientes SET nombre=?, apellido=?, telefono=?, direccion=?, correo=? WHERE id=?"
            )->execute([$datos['nombre'], $datos['apellido'], $datos['telefono'],
                        $datos['direccion'], $datos['correo'], $id]);
        } else {
            $pdo->prepare(
                "INSERT INTO clientes (negocio_id, nombre, apellido, telefono, direccion, correo)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$datos['negocio_id'], $datos['nombre'], $datos['apellido'],
                        $datos['telefono'], $datos['direccion'], $datos['correo']]);
            $id = (int)$pdo->lastInsertId();
        }
        header('Location: cliente.php?id=' . $id);
        exit;
    }
    $cliente = array_merge($cliente, $datos);
}

$titulo = $id ? 'Editar cliente' : 'Nuevo cliente';
?>
<?php cabecera_dashboard($usuario, 'fiados', $titulo); ?>

    <div class="contenido">
        <div class="cab-acciones">
            <h2><?= $titulo ?></h2>
            <a class="btn gris" href="<?= $id ? 'cliente.php?id=' . $id : 'fiados.php' ?>">Volver</a>
        </div>

        <div class="panel form-angosto">
            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
            <form method="post">
                <?php if (!$id && count($negocios) > 1): ?>
                <div class="campo">
                    <label>Negocio *</label>
                    <select name="negocio_id">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"
                                <?= (int)$cliente['negocio_id'] === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= h($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="negocio_id" value="<?= (int)$cliente['negocio_id'] ?>">
                <?php endif; ?>
                <div class="campo">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" required value="<?= h($cliente['nombre']) ?>">
                </div>
                <div class="campo">
                    <label>Apellido</label>
                    <input type="text" name="apellido" value="<?= h($cliente['apellido']) ?>">
                </div>
                <div class="campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" value="<?= h($cliente['telefono']) ?>"
                           placeholder="+56 9 1234 5678">
                </div>
                <div class="campo">
                    <label>Dirección</label>
                    <input type="text" name="direccion" value="<?= h($cliente['direccion']) ?>">
                </div>
                <div class="campo">
                    <label>Correo electrónico</label>
                    <input type="email" name="correo" value="<?= h($cliente['correo']) ?>">
                </div>
                <button class="btn" type="submit">Guardar</button>
            </form>
        </div>
    </div>
    <?php pie_dashboard(); ?>
