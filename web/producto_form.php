<?php
/**
 * Crear, editar o eliminar un producto del catálogo.
 *   producto_form.php        -> crear
 *   producto_form.php?id=N   -> editar / eliminar
 *
 * El catálogo es COMPARTIDO por los dos negocios (ver POS_DISENO.md 7.1): lo
 * que se cambie acá se ve en ambos locales al instante. Por eso cada cambio
 * queda en la auditoría con el antes y el después.
 *
 * Lo usan admin y vendedoras (módulo 'productos').
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/auditoria.php';

$usuario = exigir_permiso('productos');
$pdo = obtener_pdo();

// Campos que se auditan al editar (para mostrar "precio: $1.200 → $1.500").
const PRODUCTO_CAMPOS = ['codigo', 'nombre', 'precio_costo', 'precio_venta',
                         'precio_mayoreo', 'departamento', 'tipo_venta', 'activo', 'afecto_iva'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$prod = [
    'codigo' => '', 'nombre' => '', 'precio_costo' => '', 'precio_venta' => '',
    'precio_mayoreo' => '', 'departamento' => '', 'tipo_venta' => '',
    'activo' => 1, 'afecto_iva' => 1,
];
$original = null;

if ($id) {
    $st = $pdo->prepare("SELECT * FROM productos WHERE id = ?");
    $st->execute([$id]);
    $prod = $st->fetch();
    if (!$prod) { header('Location: herramienta_productos.php'); exit; }
    $original = $prod;
    foreach (['precio_costo', 'precio_venta', 'precio_mayoreo'] as $c) {
        $prod[$c] = $prod[$c] !== null ? number_format((float)$prod[$c], 0, ',', '.') : '';
    }
}

// Departamentos ya usados, para sugerirlos en vez de escribirlos a mano.
$deptos = $pdo->query(
    "SELECT DISTINCT departamento FROM productos
      WHERE departamento IS NOT NULL AND departamento <> '' ORDER BY departamento"
)->fetchAll(PDO::FETCH_COLUMN);

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar';

    if ($accion === 'eliminar' && $id) {
        // Las ventas ya hechas conservan el nombre y el precio en sus líneas
        // (venta_items guarda una copia), así que borrar no altera el historial.
        $pdo->prepare("DELETE FROM productos WHERE id = ?")->execute([$id]);
        registrar_auditoria($pdo, $usuario, 'productos', 'eliminar_producto', null,
            'Eliminó el producto ' . $original['nombre'] . ' (' . $original['codigo'] . ')',
            json_encode($original, JSON_UNESCAPED_UNICODE), 'producto', $id);
        header('Location: herramienta_productos.php?msg=eliminado');
        exit;
    }

    $datos = [
        'codigo'         => trim($_POST['codigo'] ?? ''),
        'nombre'         => trim($_POST['nombre'] ?? ''),
        'precio_costo'   => parsear_monto($_POST['precio_costo'] ?? ''),
        'precio_venta'   => parsear_monto($_POST['precio_venta'] ?? ''),
        'precio_mayoreo' => parsear_monto($_POST['precio_mayoreo'] ?? ''),
        'departamento'   => trim($_POST['departamento'] ?? ''),
        'tipo_venta'     => trim($_POST['tipo_venta'] ?? ''),
        'activo'         => isset($_POST['activo']) ? 1 : 0,
        'afecto_iva'     => isset($_POST['afecto_iva']) ? 1 : 0,
    ];

    if ($datos['codigo'] === '') {
        $error = 'El código es obligatorio (es lo que lee el escáner).';
    } elseif ($datos['nombre'] === '') {
        $error = 'El nombre del producto es obligatorio.';
    } elseif ($datos['precio_venta'] === null || $datos['precio_venta'] < 0) {
        $error = 'Ingresa un precio de venta válido.';
    } else {
        // El código identifica al producto en todo el sistema: no se repite.
        $st = $pdo->prepare("SELECT id, nombre FROM productos WHERE codigo = ? AND id <> ?");
        $st->execute([$datos['codigo'], $id]);
        if ($otro = $st->fetch()) {
            $error = 'Ya existe otro producto con el código ' . $datos['codigo']
                   . ' (' . $otro['nombre'] . ').';
        }
    }

    if ($error === '') {
        $dep = $datos['departamento'] !== '' ? $datos['departamento'] : null;
        $tip = $datos['tipo_venta'] !== ''   ? $datos['tipo_venta']   : null;
        if ($id) {
            $pdo->prepare(
                "UPDATE productos SET codigo=?, nombre=?, precio_costo=?, precio_venta=?,
                        precio_mayoreo=?, departamento=?, tipo_venta=?, activo=?, afecto_iva=?,
                        actualizado_por=?, actualizado_en=CURRENT_TIMESTAMP
                  WHERE id=?"
            )->execute([$datos['codigo'], $datos['nombre'], $datos['precio_costo'],
                        $datos['precio_venta'], $datos['precio_mayoreo'], $dep, $tip,
                        $datos['activo'], $datos['afecto_iva'], (int)$usuario['id'], $id]);

            // Solo se audita si de verdad cambió algo.
            if ($cambios = cambios_json($original, $datos, PRODUCTO_CAMPOS)) {
                registrar_auditoria($pdo, $usuario, 'productos', 'editar_producto', null,
                    'Editó el producto ' . $datos['nombre'] . ' (' . $datos['codigo'] . ')',
                    $cambios, 'producto', $id);
            }
        } else {
            $pdo->prepare(
                "INSERT INTO productos (codigo, nombre, precio_costo, precio_venta, precio_mayoreo,
                                        departamento, tipo_venta, activo, afecto_iva,
                                        creado_por, actualizado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$datos['codigo'], $datos['nombre'], $datos['precio_costo'],
                        $datos['precio_venta'], $datos['precio_mayoreo'], $dep, $tip,
                        $datos['activo'], $datos['afecto_iva'],
                        (int)$usuario['id'], (int)$usuario['id']]);
            $id = (int)$pdo->lastInsertId();
            registrar_auditoria($pdo, $usuario, 'productos', 'crear_producto', null,
                'Creó el producto ' . $datos['nombre'] . ' (' . $datos['codigo'] . ') a '
                . clp($datos['precio_venta']),
                json_encode($datos, JSON_UNESCAPED_UNICODE), 'producto', $id);
        }
        header('Location: herramienta_productos.php?msg=' . ($accion === 'guardar' ? 'guardado' : 'guardado'));
        exit;
    }
    $prod = array_merge($prod, $_POST);
}

// Quién tocó el producto por última vez (se muestra al editar).
$autor = null;
if ($id && $original) {
    $st = $pdo->prepare(
        "SELECT (SELECT nombre FROM usuarios WHERE id = p.creado_por) AS creador,
                (SELECT nombre FROM usuarios WHERE id = p.actualizado_por) AS editor,
                p.actualizado_en
           FROM productos p WHERE p.id = ?"
    );
    $st->execute([$id]);
    $autor = $st->fetch();
}

$titulo = $id ? 'Editar producto' : 'Nuevo producto';
?>
<?php cabecera_dashboard($usuario, 'herramientas', $titulo); ?>

    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-modulo modulo-turquesa">
                <div class="cab-modulo-tit">
                    <span class="modulo-badge"><?= icono('codigo-barras') ?></span>
                    <h2><?= $titulo ?></h2>
                </div>
                <a class="btn gris" href="herramienta_productos.php">Volver</a>
            </div>

            <div class="panel aviso-compartido">
                <p>
                    <strong>Este catálogo es compartido.</strong> Lo que guardes acá se verá
                    en <strong>los dos negocios</strong> al instante. Cada cambio queda
                    registrado con tu nombre.
                </p>
            </div>

            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

            <div class="panel">
            <form method="post">
                <div class="campo">
                    <label>Código de barras *</label>
                    <input type="text" name="codigo" required autocomplete="off"
                           value="<?= h($prod['codigo']) ?>" placeholder="7801234567890">
                    <small class="texto-tenue">Es lo que lee el escáner. No se puede repetir.</small>
                </div>
                <div class="campo">
                    <label>Nombre del producto *</label>
                    <input type="text" name="nombre" required autocomplete="off"
                           value="<?= h($prod['nombre']) ?>" placeholder="Coca Cola 1.5L">
                </div>
                <div class="campo">
                    <label>Precio de venta *</label>
                    <input type="text" name="precio_venta" required inputmode="numeric"
                           value="<?= h($prod['precio_venta']) ?>" placeholder="2.000">
                </div>
                <div class="campo">
                    <label>Precio de costo</label>
                    <input type="text" name="precio_costo" inputmode="numeric"
                           value="<?= h($prod['precio_costo']) ?>" placeholder="1.400">
                    <small class="texto-tenue">Opcional. Sirve para calcular tu ganancia real.</small>
                </div>
                <div class="campo">
                    <label>Precio mayoreo</label>
                    <input type="text" name="precio_mayoreo" inputmode="numeric"
                           value="<?= h($prod['precio_mayoreo']) ?>" placeholder="—">
                </div>
                <div class="campo">
                    <label>Departamento</label>
                    <input type="text" name="departamento" list="listaDeptos" autocomplete="off"
                           value="<?= h($prod['departamento']) ?>" placeholder="Bebidas">
                    <datalist id="listaDeptos">
                        <?php foreach ($deptos as $d): ?>
                            <option value="<?= h($d) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="campo">
                    <label>Tipo de venta</label>
                    <input type="text" name="tipo_venta" autocomplete="off"
                           value="<?= h($prod['tipo_venta']) ?>" placeholder="Unidad / Granel">
                </div>
                <div class="campo campo-check">
                    <label>
                        <input type="checkbox" name="activo" value="1"
                               <?= !empty($prod['activo']) ? 'checked' : '' ?>>
                        Disponible para la venta
                    </label>
                    <small class="texto-tenue">Desmárcalo para ocultarlo del POS sin borrarlo.</small>
                </div>
                <div class="campo campo-check">
                    <label>
                        <input type="checkbox" name="afecto_iva" value="1"
                               <?= !empty($prod['afecto_iva']) ? 'checked' : '' ?>>
                        Afecto a IVA
                    </label>
                </div>

                <?php if ($autor): ?>
                    <p class="texto-tenue firma-cambio">
                        <?php if ($autor['editor']): ?>
                            Última edición: <strong><?= h($autor['editor']) ?></strong>
                            <?= $autor['actualizado_en'] ? ' el ' . h(date('d-m-Y H:i', strtotime($autor['actualizado_en']))) : '' ?>.
                        <?php endif; ?>
                        <?php if ($autor['creador']): ?>
                            Creado por <strong><?= h($autor['creador']) ?></strong>.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <div class="form-acciones">
                    <button class="btn" type="submit" name="accion" value="guardar">Guardar</button>
                    <a class="btn gris" href="herramienta_productos.php">Cancelar</a>
                    <?php if ($id): ?>
                        <button class="btn rojo" type="submit" name="accion" value="eliminar"
                                onclick="return confirm('¿Eliminar este producto del catálogo de los DOS negocios? Las ventas ya hechas no se alteran.')">
                            Eliminar
                        </button>
                    <?php endif; ?>
                </div>
            </form>
            </div>
        </div>
    </div>
    <?php pie_dashboard(); ?>
