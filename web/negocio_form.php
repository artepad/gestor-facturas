<?php
/**
 * Crear o editar el perfil de un negocio. Solo admin.
 *   negocio_form.php          -> crear
 *   negocio_form.php?id=N     -> editar
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('negocios');

$pdo = obtener_pdo();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$negocio = [
    'nombre' => '', 'rut' => '', 'telefono' => '',
    'direccion' => '', 'correo' => '', 'activo' => 1,
];
if ($id) {
    $st = $pdo->prepare("SELECT * FROM negocios WHERE id = ?");
    $st->execute([$id]);
    $negocio = $st->fetch();
    if (!$negocio) { header('Location: negocios.php'); exit; }
}

$error = '';

/** Genera un slug único a partir del nombre. */
function generar_slug(PDO $pdo, string $nombre, int $excluirId = 0): string
{
    $base = strtolower(trim($nombre));
    $base = preg_replace('/[áàä]/u', 'a', $base);
    $base = preg_replace('/[éèë]/u', 'e', $base);
    $base = preg_replace('/[íìï]/u', 'i', $base);
    $base = preg_replace('/[óòö]/u', 'o', $base);
    $base = preg_replace('/[úùü]/u', 'u', $base);
    $base = preg_replace('/ñ/u', 'n', $base);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-') ?: 'negocio';
    $slug = $base;
    $i = 2;
    while (true) {
        $st = $pdo->prepare("SELECT id FROM negocios WHERE slug = ? AND id <> ?");
        $st->execute([$slug, $excluirId]);
        if (!$st->fetch()) return $slug;
        $slug = "$base-$i"; $i++;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $datos = [
        'nombre'    => trim($_POST['nombre'] ?? ''),
        'rut'       => trim($_POST['rut'] ?? ''),
        'telefono'  => trim($_POST['telefono'] ?? ''),
        'direccion' => trim($_POST['direccion'] ?? ''),
        'correo'    => trim($_POST['correo'] ?? ''),
        'activo'    => isset($_POST['activo']) ? 1 : 0,
    ];
    if ($datos['nombre'] === '') {
        $error = 'El nombre del negocio es obligatorio.';
        $negocio = array_merge($negocio, $datos);
    } else {
        if ($id) {
            $pdo->prepare(
                "UPDATE negocios SET nombre=?, rut=?, telefono=?, direccion=?, correo=?, activo=? WHERE id=?"
            )->execute([$datos['nombre'], $datos['rut'], $datos['telefono'],
                        $datos['direccion'], $datos['correo'], $datos['activo'], $id]);
        } else {
            $slug = generar_slug($pdo, $datos['nombre']);
            $pdo->prepare(
                "INSERT INTO negocios (slug, nombre, rut, telefono, direccion, correo, activo)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$slug, $datos['nombre'], $datos['rut'], $datos['telefono'],
                        $datos['direccion'], $datos['correo'], $datos['activo']]);
        }
        header('Location: negocios.php');
        exit;
    }
}

$titulo = $id ? 'Editar negocio' : 'Crear negocio';
?>
<?php cabecera_dashboard($usuario, 'negocios', $titulo); ?>

    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-acciones">
                <h2><?= $titulo ?></h2>
                <a class="btn gris" href="negocios.php">Volver</a>
            </div>
            <?php if ($error): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>
            <div class="panel">
            <form method="post">
                <div class="campo">
                    <label>Nombre del negocio *</label>
                    <input type="text" name="nombre" required
                           value="<?= h($negocio['nombre']) ?>">
                </div>
                <div class="campo">
                    <label>RUT</label>
                    <input type="text" name="rut"
                           value="<?= h($negocio['rut']) ?>" placeholder="76.123.456-7">
                </div>
                <div class="campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono"
                           value="<?= h($negocio['telefono']) ?>" placeholder="+56 9 1234 5678">
                </div>
                <div class="campo">
                    <label>Dirección</label>
                    <input type="text" name="direccion"
                           value="<?= h($negocio['direccion']) ?>">
                </div>
                <div class="campo">
                    <label>Correo electrónico</label>
                    <input type="email" name="correo"
                           value="<?= h($negocio['correo']) ?>">
                </div>
                <label class="check-inline">
                    <input type="checkbox" name="activo" <?= $negocio['activo'] ? 'checked' : '' ?>>
                    Negocio activo
                </label>
                <div class="form-acciones">
                    <button class="btn" type="submit">Guardar</button>
                    <a class="btn gris" href="negocios.php">Cancelar</a>
                </div>
            </form>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
