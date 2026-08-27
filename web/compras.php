<?php
/**
 * Compras: registrar la mercadería que llega y sumarla al stock.
 *
 * Recibir una compra SUMA existencias si el negocio lleva inventario
 * (POS_DISENO.md 4.0); si no lo lleva, la compra queda igual como registro de
 * lo que se compró, pero no mueve stock.
 *
 * Una compra se puede enlazar a una factura ya escaneada por la app de
 * escritorio, para cerrar el círculo entre lo que llegó y lo que se pagó.
 *
 * Lo usan admin y vendedoras (módulo 'productos'). Todo queda auditado.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/pos.php';
require __DIR__ . '/lib/auditoria.php';

$usuario  = exigir_permiso('productos');
$pdo      = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

$negocioId = isset($_GET['negocio_id']) ? (int)$_GET['negocio_id'] : ($idsVisibles[0] ?? 0);
if (!in_array($negocioId, $idsVisibles, true)) $negocioId = $idsVisibles[0] ?? 0;

$error = '';
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        $neg = (int)($_POST['negocio_id'] ?? 0);
        if (!in_array($neg, $idsVisibles, true)) throw new RuntimeException('Negocio inválido.');

        if ($accion === 'proveedor') {
            $nombre = trim($_POST['nombre'] ?? '');
            if ($nombre === '') throw new RuntimeException('Escribe el nombre del proveedor.');
            $pdo->prepare(
                "INSERT INTO proveedores (negocio_id, nombre, rut, telefono, correo) VALUES (?,?,?,?,?)"
            )->execute([
                ($_POST['ambos'] ?? '') ? null : $neg,
                $nombre,
                trim($_POST['rut'] ?? '') ?: null,
                trim($_POST['telefono'] ?? '') ?: null,
                trim($_POST['correo'] ?? '') ?: null,
            ]);
            registrar_auditoria($pdo, $usuario, 'productos', 'crear_proveedor', null,
                'Creó el proveedor ' . $nombre, null, 'proveedor', (int)$pdo->lastInsertId());
            header('Location: compras.php?negocio_id=' . $neg . '&msg=proveedor');
            exit;
        }

        if ($accion === 'compra') {
            $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '')
                   ? $_POST['fecha'] : date('Y-m-d');
            if ($fecha > date('Y-m-d')) throw new RuntimeException('La fecha no puede ser futura.');

            // Se pega "codigo cantidad [costo]" por línea.
            $texto = trim($_POST['detalle'] ?? '');
            if ($texto === '') throw new RuntimeException('Pega lo que llegó (un producto por línea).');
            $items = [];
            foreach (preg_split('/\R/', $texto) as $linea) {
                $linea = trim($linea);
                if ($linea === '') continue;
                // Igual que el conteo: la coma también es decimal en Chile, así
                // que se separa por espacio o punto y coma.
                $p = preg_split('/[\s;]+/', $linea, -1, PREG_SPLIT_NO_EMPTY);
                if (count($p) < 2) continue;
                $cod  = $p[0];
                $cant = parsear_monto($p[1]);
                $costo = isset($p[2]) ? parsear_monto($p[2]) : null;
                if ($cant === null || $cant <= 0) continue;
                $items[] = ['codigo' => $cod, 'cantidad' => $cant, 'costo_unitario' => $costo];
            }
            if (!$items) throw new RuntimeException('No se entendió ninguna línea. Usa: código cantidad [costo].');

            $r = registrar_compra($pdo, $usuario, $neg,
                                  (int)($_POST['proveedor_id'] ?? 0) ?: null,
                                  $fecha, $items, trim($_POST['nota'] ?? ''),
                                  (int)($_POST['factura_id'] ?? 0) ?: null);
            registrar_auditoria($pdo, $usuario, 'productos', 'compra', null,
                'Registró una compra de ' . $r['cargados'] . ' producto(s)'
                . ($r['total'] > 0 ? ' por ' . clp($r['total']) : ''),
                null, 'compra', $r['id']);
            $aviso = 'Compra registrada: ' . $r['cargados'] . ' producto(s)'
                   . (negocio_controla_stock($pdo, $neg) ? ' sumados al stock.' : ' (el inventario está apagado, no se movió stock).')
                   . ($r['no_encontrados'] ? ' No están en el catálogo: '
                       . implode(', ', array_slice($r['no_encontrados'], 0, 6))
                       . (count($r['no_encontrados']) > 6 ? ' y ' . (count($r['no_encontrados']) - 6) . ' más' : '') . '.' : '');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_GET['msg'] ?? '') === 'proveedor') $aviso = 'Proveedor creado.';

$controla    = negocio_controla_stock($pdo, $negocioId);
$proveedores = proveedores_de($pdo, $negocioId);
$compras     = compras_de($pdo, $negocioId);
$verId       = isset($_GET['ver']) ? (int)$_GET['ver'] : 0;
$detalle     = $verId ? items_de_compra($pdo, $verId) : [];

// Facturas recientes del negocio, para poder enlazar la compra con la que llegó.
$st = $pdo->prepare(
    "SELECT id, proveedor, numero_factura, fecha, total FROM facturas
      WHERE negocio_id = ? AND eliminada_en IS NULL
      ORDER BY fecha DESC, id DESC LIMIT 25"
);
$st->execute([$negocioId]);
$facturas = $st->fetchAll();

function n3c($v) { return rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ','); }
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Compras'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('inventario') ?></span>
                <h2>Compras</h2>
            </div>
            <div class="cab-botones">
                <a class="btn gris" href="inventario.php?negocio_id=<?= $negocioId ?>">Inventario</a>
                <?php if (count($negocios) > 1): ?>
                <form method="get">
                    <select name="negocio_id" onchange="this.form.submit()">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= $negocioId === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= h($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <?php if ($aviso): ?><div class="panel exito"><p><?= h($aviso) ?></p></div><?php endif; ?>

        <?php if (!$controla): ?>
            <div class="panel aviso-compartido">
                <p>
                    El <strong>inventario está apagado</strong> en este negocio: las compras se
                    guardan como registro de lo que llegó, pero <strong>no suman stock</strong>.
                    <a href="inventario.php?negocio_id=<?= $negocioId ?>">Encenderlo</a> si quieres
                    llevar existencias.
                </p>
            </div>
        <?php endif; ?>

        <!-- ================= Registrar lo que llegó ================= -->
        <div class="panel">
            <h2 class="panel-titulo">Registrar mercadería recibida</h2>
            <p class="texto-ayuda">
                Pega una línea por producto: <strong>código, cantidad</strong> y, si quieres,
                el <strong>costo unitario</strong>. Los códigos que no estén en el catálogo se
                informan y no se cargan (no se inventan productos solos).
            </p>
            <form method="post">
                <input type="hidden" name="accion" value="compra">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <div class="filtros">
                    <div class="campo">
                        <label>Proveedor</label>
                        <select name="proveedor_id">
                            <option value="">— Sin especificar —</option>
                            <?php foreach ($proveedores as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= h($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <?php if ($facturas): ?>
                    <div class="campo" style="flex:1 1 260px">
                        <label>Enlazar con una factura escaneada (opcional)</label>
                        <select name="factura_id" style="width:100%">
                            <option value="">— Ninguna —</option>
                            <?php foreach ($facturas as $f): ?>
                                <option value="<?= (int)$f['id'] ?>">
                                    <?= h($f['proveedor'] ?: 'Sin proveedor') ?>
                                    <?= $f['numero_factura'] ? '· N° ' . h($f['numero_factura']) : '' ?>
                                    · <?= h(fecha_dmy($f['fecha'])) ?> · <?= clp($f['total']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="campo">
                    <label>Lo que llegó (código, cantidad y costo por línea)</label>
                    <textarea name="detalle" rows="8" required style="width:100%;font-family:monospace"
placeholder="7801234567890 24 850
1100000100 10 1200
7802820331530 6"></textarea>
                    <small class="texto-tenue">El costo es opcional; sirve para saber cuánto vale tu inventario.</small>
                </div>
                <div class="campo">
                    <label>Nota (opcional)</label>
                    <input type="text" name="nota" placeholder="Pedido del martes">
                </div>
                <div class="form-acciones">
                    <button class="btn verde" type="submit">Registrar y sumar al stock</button>
                </div>
            </form>
        </div>

        <!-- ================= Historial ================= -->
        <div class="panel">
            <h2 class="panel-titulo">Últimas compras</h2>
            <?php if (!$compras): ?>
                <p class="vacio">Todavía no hay compras registradas.</p>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>Fecha</th><th>Proveedor</th><th class="col-num">Productos</th>
                            <th class="col-num">Total</th><th>Nota</th><th>Quién</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($compras as $c): ?>
                            <tr>
                                <td><?= h(fecha_dmy($c['fecha'])) ?></td>
                                <td><?= h($c['proveedor'] ?: '—') ?></td>
                                <td class="col-num"><?= (int)$c['n_items'] ?></td>
                                <td class="col-num"><?= $c['total'] !== null ? clp($c['total']) : '—' ?></td>
                                <td class="texto-tenue"><?= h($c['nota'] ?: '—') ?></td>
                                <td class="texto-tenue"><?= h($c['usuario'] ?: '—') ?></td>
                                <td><a class="btn sm gris" href="compras.php?negocio_id=<?= $negocioId ?>&ver=<?= (int)$c['id'] ?>">Ver</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($detalle): ?>
        <div class="panel">
            <h2 class="panel-titulo">Detalle de la compra</h2>
            <div class="tabla-wrap">
                <table>
                    <thead><tr>
                        <th>Código</th><th>Producto</th>
                        <th class="col-num">Cantidad</th><th class="col-num">Costo</th><th class="col-num">Total</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($detalle as $d): ?>
                        <tr>
                            <td class="texto-tenue"><?= h($d['codigo']) ?></td>
                            <td><?= h($d['nombre']) ?></td>
                            <td class="col-num"><?= n3c($d['cantidad']) ?></td>
                            <td class="col-num"><?= $d['costo_unitario'] !== null ? clp($d['costo_unitario']) : '—' ?></td>
                            <td class="col-num"><?= $d['total'] !== null ? clp($d['total']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ================= Proveedores ================= -->
        <details class="bloque panel"<?= $proveedores ? '' : ' open' ?>>
            <summary><h2 class="panel-titulo">Proveedores (<?= count($proveedores) ?>)</h2></summary>
            <?php if ($proveedores): ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr><th>Nombre</th><th>RUT</th><th>Teléfono</th><th>Correo</th></tr></thead>
                        <tbody>
                        <?php foreach ($proveedores as $p): ?>
                            <tr>
                                <td><?= h($p['nombre']) ?>
                                    <?php if ($p['negocio_id'] === null): ?>
                                        <span class="badge-inactivo">Los dos negocios</span>
                                    <?php endif; ?>
                                </td>
                                <td class="texto-tenue"><?= h($p['rut'] ?: '—') ?></td>
                                <td class="texto-tenue"><?= h($p['telefono'] ?: '—') ?></td>
                                <td class="texto-tenue"><?= h($p['correo'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <form method="post" class="filtros">
                <input type="hidden" name="accion" value="proveedor">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <div class="campo">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" required placeholder="Distribuidora Los Andes">
                </div>
                <div class="campo">
                    <label>RUT</label>
                    <input type="text" name="rut" placeholder="76.123.456-7" style="width:130px">
                </div>
                <div class="campo">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" style="width:130px">
                </div>
                <div class="campo">
                    <label>Correo</label>
                    <input type="email" name="correo" style="width:170px">
                </div>
                <div class="campo campo-check">
                    <label><input type="checkbox" name="ambos" value="1"> Sirve para los dos negocios</label>
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit">Agregar</button>
                </div>
            </form>
        </details>
    </div>
    <?php pie_dashboard(); ?>
