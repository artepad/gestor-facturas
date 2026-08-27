<?php
/**
 * Promociones automáticas: las ofertas que el POS aplica solo al vender.
 *   promociones.php          -> listado
 *   promociones.php?id=N     -> editar / eliminar
 *   promociones.php?nueva=1  -> crear
 *
 * El caso típico: "4 yogures por $1.000" cuando el yogur suelto vale $300. Al
 * pasar 4 por el escáner, el POS cobra $1.000 sin que la vendedora tenga que
 * acordarse ni calcular.
 *
 * Lo usan admin y vendedoras (módulo 'productos'), igual que el catálogo.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/pos.php';
require __DIR__ . '/lib/auditoria.php';

$usuario  = exigir_permiso('productos');
$pdo      = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

const PROMO_TIPOS = [
    'pack'       => ['N por $M',            'Ej. 4 yogures por $1.000. Lo que no completa un grupo va a precio normal.'],
    'nxm'        => ['Lleve N pague M',     'Ej. lleve 3 pague 2.'],
    'precio'     => ['Precio especial',     'Ej. la Coca a $1.500 en vez de $2.000, sin importar cuántas lleve.'],
    'porcentaje' => ['% de descuento',      'Ej. 20% en todo el departamento de Lácteos.'],
];

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$nueva = isset($_GET['nueva']);
$error = '';

$promo = [
    'negocio_id' => '', 'nombre' => '', 'tipo' => 'pack', 'producto_id' => '',
    'codigo' => '', 'departamento' => '', 'cantidad' => '', 'valor' => '',
    'desde' => '', 'hasta' => '', 'activo' => 1,
];
$original = null;

if ($id) {
    $st = $pdo->prepare(
        "SELECT p.*, pr.codigo, pr.nombre AS producto_nombre
           FROM promociones p LEFT JOIN productos pr ON pr.id = p.producto_id
          WHERE p.id = ?"
    );
    $st->execute([$id]);
    $promo = $st->fetch();
    if (!$promo) { header('Location: promociones.php'); exit; }
    $original = $promo;
    $promo['valor'] = $promo['valor'] !== null ? number_format((float)$promo['valor'], 0, ',', '.') : '';
    $promo['cantidad'] = $promo['cantidad'] !== null
        ? rtrim(rtrim(number_format((float)$promo['cantidad'], 3, ',', '.'), '0'), ',') : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar';

    if ($accion === 'eliminar' && $id) {
        $pdo->prepare("DELETE FROM promociones WHERE id = ?")->execute([$id]);
        registrar_auditoria($pdo, $usuario, 'productos', 'eliminar_promocion', null,
            'Eliminó la promoción ' . $original['nombre'], null, 'promocion', $id);
        header('Location: promociones.php?msg=eliminada');
        exit;
    }

    $d = [
        'negocio_id'   => ($_POST['negocio_id'] ?? '') !== '' ? (int)$_POST['negocio_id'] : null,
        'nombre'       => trim($_POST['nombre'] ?? ''),
        'tipo'         => $_POST['tipo'] ?? 'pack',
        'codigo'       => trim($_POST['codigo'] ?? ''),
        'departamento' => trim($_POST['departamento'] ?? ''),
        'cantidad'     => parsear_monto($_POST['cantidad'] ?? ''),
        'valor'        => parsear_monto($_POST['valor'] ?? ''),
        'desde'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['desde'] ?? '') ? $_POST['desde'] : null,
        'hasta'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['hasta'] ?? '') ? $_POST['hasta'] : null,
        'activo'       => isset($_POST['activo']) ? 1 : 0,
    ];

    // El producto se identifica por su código (es lo que la vendedora conoce).
    $productoId = null;
    if ($d['codigo'] !== '') {
        $st = $pdo->prepare("SELECT id, nombre FROM productos WHERE codigo = ?");
        $st->execute([$d['codigo']]);
        if ($p = $st->fetch()) { $productoId = (int)$p['id']; }
        else { $error = 'No existe ningún producto con el código ' . $d['codigo'] . '.'; }
    }

    if ($error === '') {
        if ($d['nombre'] === '')                        $error = 'Ponle un nombre a la promoción.';
        elseif (!isset(PROMO_TIPOS[$d['tipo']]))        $error = 'Tipo de promoción inválido.';
        elseif (!$productoId && $d['departamento'] === '')
            $error = 'Indica el código del producto o el departamento al que aplica.';
        elseif ($d['valor'] === null || $d['valor'] <= 0)
            $error = 'Indica el valor de la promoción.';
        elseif (in_array($d['tipo'], ['pack', 'nxm'], true) && ($d['cantidad'] === null || $d['cantidad'] < 2))
            $error = 'Para este tipo, la cantidad debe ser 2 o más.';
        elseif ($d['tipo'] === 'nxm' && $d['valor'] >= $d['cantidad'])
            $error = 'En "lleve N pague M", M tiene que ser menor que N.';
        elseif ($d['tipo'] === 'porcentaje' && $d['valor'] >= 100)
            $error = 'El descuento tiene que ser menor que 100%.';
        elseif ($d['negocio_id'] !== null && !in_array($d['negocio_id'], $idsVisibles, true))
            $error = 'Negocio inválido.';
        elseif ($d['desde'] && $d['hasta'] && $d['desde'] > $d['hasta'])
            $error = 'La fecha "desde" no puede ser posterior a "hasta".';
    }

    if ($error === '') {
        $dep = ($productoId === null && $d['departamento'] !== '') ? $d['departamento'] : null;
        $cant = in_array($d['tipo'], ['pack', 'nxm'], true) ? $d['cantidad'] : null;
        if ($id) {
            $pdo->prepare(
                "UPDATE promociones SET negocio_id=?, nombre=?, tipo=?, producto_id=?, departamento=?,
                        cantidad=?, valor=?, desde=?, hasta=?, activo=? WHERE id=?"
            )->execute([$d['negocio_id'], $d['nombre'], $d['tipo'], $productoId, $dep,
                        $cant, $d['valor'], $d['desde'], $d['hasta'], $d['activo'], $id]);
            registrar_auditoria($pdo, $usuario, 'productos', 'editar_promocion', null,
                'Editó la promoción ' . $d['nombre'],
                cambios_json($original, $d + ['producto_id' => $productoId],
                             ['nombre','tipo','cantidad','valor','activo','desde','hasta']),
                'promocion', $id);
        } else {
            $pdo->prepare(
                "INSERT INTO promociones (negocio_id, nombre, tipo, producto_id, departamento,
                                          cantidad, valor, desde, hasta, activo, creado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$d['negocio_id'], $d['nombre'], $d['tipo'], $productoId, $dep,
                        $cant, $d['valor'], $d['desde'], $d['hasta'], $d['activo'],
                        (int)$usuario['id']]);
            $id = (int)$pdo->lastInsertId();
            registrar_auditoria($pdo, $usuario, 'productos', 'crear_promocion', null,
                'Creó la promoción ' . $d['nombre'], null, 'promocion', $id);
        }
        header('Location: promociones.php?msg=guardada');
        exit;
    }
    $promo = array_merge($promo, $_POST);
    $nueva = !$id;
}

// Listado
$lista = $pdo->query(
    "SELECT p.*, pr.codigo, pr.nombre AS producto_nombre, n.nombre AS negocio_nombre
       FROM promociones p
       LEFT JOIN productos pr ON pr.id = p.producto_id
       LEFT JOIN negocios  n  ON n.id  = p.negocio_id
      ORDER BY p.activo DESC, p.id DESC"
)->fetchAll();

$deptos = $pdo->query(
    "SELECT DISTINCT departamento FROM productos
      WHERE departamento IS NOT NULL AND departamento <> '' ORDER BY departamento"
)->fetchAll(PDO::FETCH_COLUMN);

$msg = $_GET['msg'] ?? '';
$hoy = date('Y-m-d');
$editando = $id || $nueva;
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Promociones'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('oferta') ?></span>
                <h2>Promociones</h2>
            </div>
            <div class="cab-botones">
                <a class="btn gris" href="herramienta_productos.php">Catálogo</a>
                <?php if (!$editando): ?>
                    <a class="btn verde" href="promociones.php?nueva=1">+ Nueva promoción</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($msg === 'guardada'): ?>
            <div class="panel exito"><p>Promoción guardada. El POS la aplicará sola en la próxima venta.</p></div>
        <?php elseif ($msg === 'eliminada'): ?>
            <div class="panel exito"><p>Promoción eliminada.</p></div>
        <?php endif; ?>
        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

        <?php if ($editando): ?>
        <!-- ================= Crear / editar ================= -->
        <div class="panel">
            <h2 class="panel-titulo"><?= $id ? 'Editar promoción' : 'Nueva promoción' ?></h2>
            <p class="texto-ayuda">
                El POS aplica esto <strong>solo</strong> al vender. Si pones "4 por $1.000" y el
                cliente lleva 4, cobra $1.000; si lleva 5, cobra $1.300 (el pack más uno suelto).
            </p>
            <form method="post">
                <div class="campo">
                    <label>Nombre de la promoción *</label>
                    <input type="text" name="nombre" required value="<?= h($promo['nombre']) ?>"
                           placeholder="4 yogures por $1.000">
                </div>
                <div class="campo">
                    <label>Tipo *</label>
                    <select name="tipo" id="tipoPromo">
                        <?php foreach (PROMO_TIPOS as $k => $t): ?>
                            <option value="<?= $k ?>" <?= $promo['tipo'] === $k ? 'selected' : '' ?>>
                                <?= h($t[0]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="texto-tenue" id="ayudaTipo"></small>
                </div>
                <div class="campo">
                    <label>Código del producto</label>
                    <input type="text" name="codigo" value="<?= h($promo['codigo'] ?? '') ?>"
                           autocomplete="off" placeholder="7801234567890">
                    <small class="texto-tenue">
                        <?php if (!empty($promo['producto_nombre'])): ?>
                            Actualmente: <strong><?= h($promo['producto_nombre']) ?></strong>.
                        <?php endif; ?>
                        Déjalo vacío si la promo es de un departamento completo.
                    </small>
                </div>
                <div class="campo">
                    <label>…o departamento completo</label>
                    <input type="text" name="departamento" list="listaDeptos" autocomplete="off"
                           value="<?= h($promo['departamento'] ?? '') ?>" placeholder="Lácteos">
                    <datalist id="listaDeptos">
                        <?php foreach ($deptos as $d): ?><option value="<?= h($d) ?>"></option><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="campo" id="campoCantidad">
                    <label>Cantidad (la N)</label>
                    <input type="text" name="cantidad" inputmode="numeric"
                           value="<?= h($promo['cantidad'] ?? '') ?>" placeholder="4">
                </div>
                <div class="campo">
                    <label id="lblValor">Valor *</label>
                    <input type="text" name="valor" required inputmode="numeric"
                           value="<?= h($promo['valor'] ?? '') ?>" placeholder="1.000">
                </div>
                <?php if (count($negocios) > 1): ?>
                <div class="campo">
                    <label>¿En qué negocio?</label>
                    <select name="negocio_id">
                        <option value="">En los dos negocios</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"
                                <?= (string)($promo['negocio_id'] ?? '') === (string)$n['id'] ? 'selected' : '' ?>>
                                Solo en <?= h($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="campo">
                    <label>Desde (opcional)</label>
                    <input type="date" name="desde" value="<?= h($promo['desde'] ?? '') ?>">
                </div>
                <div class="campo">
                    <label>Hasta (opcional)</label>
                    <input type="date" name="hasta" value="<?= h($promo['hasta'] ?? '') ?>">
                    <small class="texto-tenue">Sin fechas, la promoción vale hasta que la desactives.</small>
                </div>
                <div class="campo campo-check">
                    <label>
                        <input type="checkbox" name="activo" value="1" <?= !empty($promo['activo']) ? 'checked' : '' ?>>
                        Activa
                    </label>
                </div>
                <div class="form-acciones">
                    <button class="btn" type="submit" name="accion" value="guardar">Guardar</button>
                    <a class="btn gris" href="promociones.php">Cancelar</a>
                    <?php if ($id): ?>
                        <button class="btn rojo" type="submit" name="accion" value="eliminar"
                                onclick="return confirm('¿Eliminar esta promoción?')">Eliminar</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <script>
        // Ayuda contextual y campos que solo aplican a ciertos tipos.
        (function () {
            var AYUDA = <?= json_encode(array_map(fn($t) => $t[1], PROMO_TIPOS), JSON_UNESCAPED_UNICODE) ?>;
            var LBL = { pack: 'Precio del pack *', nxm: 'Cuántas paga (la M) *',
                        precio: 'Precio especial *', porcentaje: 'Porcentaje de descuento *' };
            var sel = document.getElementById('tipoPromo');
            function pintar() {
                document.getElementById('ayudaTipo').textContent = AYUDA[sel.value] || '';
                document.getElementById('lblValor').textContent = LBL[sel.value] || 'Valor *';
                document.getElementById('campoCantidad').style.display =
                    (sel.value === 'pack' || sel.value === 'nxm') ? '' : 'none';
            }
            sel.addEventListener('change', pintar);
            pintar();
        })();
        </script>
        <?php endif; ?>

        <!-- ================= Listado ================= -->
        <div class="panel">
            <h2 class="panel-titulo">Promociones (<?= count($lista) ?>)</h2>
            <?php if (!$lista): ?>
                <p class="vacio">
                    Todavía no hay promociones. Crea una y el POS la aplicará sola al vender.
                </p>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>Promoción</th><th>Oferta</th><th>Aplica a</th>
                            <th>Vigencia</th><th>Dónde</th><th>Estado</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($lista as $p): ?>
                            <?php
                            $vencida = $p['hasta'] && $p['hasta'] < $hoy;
                            $futura  = $p['desde'] && $p['desde'] > $hoy;
                            $vigente = $p['activo'] && !$vencida && !$futura;
                            ?>
                            <tr<?= $vigente ? '' : ' class="fila-inactiva"' ?>>
                                <td><?= h($p['nombre']) ?></td>
                                <td><strong><?= h(texto_promocion($p)) ?></strong></td>
                                <td>
                                    <?php if ($p['producto_nombre']): ?>
                                        <?= h($p['producto_nombre']) ?>
                                        <span class="texto-tenue"><?= h($p['codigo']) ?></span>
                                    <?php else: ?>
                                        Depto. <?= h($p['departamento']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="texto-tenue">
                                    <?php if (!$p['desde'] && !$p['hasta']): ?>Sin límite
                                    <?php else: ?>
                                        <?= $p['desde'] ? h(fecha_dmy($p['desde'])) : '…' ?>
                                        a <?= $p['hasta'] ? h(fecha_dmy($p['hasta'])) : '…' ?>
                                    <?php endif; ?>
                                </td>
                                <td class="texto-tenue"><?= h($p['negocio_nombre'] ?: 'Los dos') ?></td>
                                <td>
                                    <?php if ($vigente): ?>
                                        <span class="badge-carga b-activa">Vigente</span>
                                    <?php elseif (!$p['activo']): ?>
                                        <span class="badge-carga b-reemplazada">Desactivada</span>
                                    <?php elseif ($vencida): ?>
                                        <span class="badge-carga b-eliminada">Vencida</span>
                                    <?php else: ?>
                                        <span class="badge-carga b-reemplazada">Aún no empieza</span>
                                    <?php endif; ?>
                                </td>
                                <td><a class="btn sm" href="promociones.php?id=<?= (int)$p['id'] ?>">Editar</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php pie_dashboard(); ?>
