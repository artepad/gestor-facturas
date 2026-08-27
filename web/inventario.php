<?php
/**
 * Inventario: existencias por negocio, ajustes, mermas y alertas de stock bajo.
 *
 * El control de inventario es OPCIONAL y por negocio (POS_DISENO.md 4.0): nace
 * apagado y esta pantalla ofrece encenderlo. Con el control apagado el POS ni
 * menciona el stock, así que se puede vender igual sin llevar existencias.
 *
 * Lo usan admin y vendedoras (módulo 'productos'), pero encender o apagar el
 * control es solo del administrador. Todo movimiento queda auditado.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/pos.php';
require __DIR__ . '/lib/auditoria.php';

$usuario  = exigir_permiso('productos');
$pdo      = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
$esAdmin  = ($usuario['rol'] ?? '') === 'admin';

$negocioId = isset($_GET['negocio_id']) ? (int)$_GET['negocio_id'] : ($idsVisibles[0] ?? 0);
if (!in_array($negocioId, $idsVisibles, true)) $negocioId = $idsVisibles[0] ?? 0;

$error = '';
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        if (!in_array((int)($_POST['negocio_id'] ?? 0), $idsVisibles, true)) {
            throw new RuntimeException('Negocio inválido.');
        }
        $neg = (int)$_POST['negocio_id'];

        if ($accion === 'control') {
            if (!$esAdmin) throw new RuntimeException('Solo el administrador puede encender o apagar el inventario.');
            $on = isset($_POST['encender']) ? 1 : 0;
            $pdo->prepare("UPDATE negocios SET control_stock = ? WHERE id = ?")->execute([$on, $neg]);
            registrar_auditoria($pdo, $usuario, 'productos', 'control_stock', null,
                ($on ? 'Encendió' : 'Apagó') . ' el control de inventario del negocio', null, 'negocio', $neg);
            header('Location: inventario.php?negocio_id=' . $neg . '&msg=' . ($on ? 'on' : 'off'));
            exit;
        }

        if ($accion === 'ajuste') {
            $pid   = (int)($_POST['producto_id'] ?? 0);
            $tipo  = in_array($_POST['tipo'] ?? '', ['ajuste', 'merma'], true) ? $_POST['tipo'] : 'ajuste';
            $modo  = $_POST['modo'] ?? 'sumar';         // sumar | dejar_en
            $cant  = parsear_monto($_POST['cantidad'] ?? '');
            $motivo = trim($_POST['motivo'] ?? '');
            if ($cant === null) throw new RuntimeException('Escribe una cantidad válida.');
            if ($motivo === '') throw new RuntimeException('Escribe el motivo del movimiento.');

            $st = $pdo->prepare("SELECT nombre, codigo FROM productos WHERE id = ?");
            $st->execute([$pid]);
            $prod = $st->fetch();
            if (!$prod) throw new RuntimeException('Ese producto no existe.');

            // "merma" siempre resta; "ajuste" puede sumar, restar o fijar el total.
            if ($tipo === 'merma') {
                $delta = -abs($cant);
            } elseif ($modo === 'dejar_en') {
                $delta = $cant - stock_de($pdo, $pid, $neg);
            } else {
                $delta = $cant;
            }
            if (abs($delta) < 0.001) throw new RuntimeException('Ese movimiento no cambia nada.');

            $nuevo = mover_stock($pdo, $usuario, $neg, $pid, $delta, $tipo, null, null, $motivo);
            if ($nuevo === null) throw new RuntimeException('Este negocio no lleva control de inventario.');
            registrar_auditoria($pdo, $usuario, 'productos', $tipo === 'merma' ? 'merma' : 'ajuste_stock', null,
                ($tipo === 'merma' ? 'Merma de ' : 'Ajuste de ') . $prod['nombre'] .
                ': ' . ($delta > 0 ? '+' : '') . rtrim(rtrim(number_format($delta, 3, ',', '.'), '0'), ',') .
                ' → quedan ' . rtrim(rtrim(number_format($nuevo, 3, ',', '.'), '0'), ',') .
                ' · ' . $motivo, null, 'producto', $pid);
            header('Location: inventario.php?negocio_id=' . $neg . '&q=' . urlencode($prod['codigo']) . '&msg=ajuste');
            exit;
        }

        if ($accion === 'minimo') {
            $pid = (int)($_POST['producto_id'] ?? 0);
            $min = parsear_monto($_POST['minimo'] ?? '');
            fijar_stock_minimo($pdo, $pid, $neg, $min);
            header('Location: inventario.php?negocio_id=' . $neg . '&q=' . urlencode($_POST['q'] ?? '') . '&msg=minimo');
            exit;
        }

        if ($accion === 'conteo') {
            // Conteo inicial pegando "codigo cantidad" por línea.
            $texto = trim($_POST['conteo'] ?? '');
            if ($texto === '') throw new RuntimeException('Pega el conteo (un producto por línea).');
            $ok = 0; $malos = [];
            foreach (preg_split('/\R/', $texto) as $n => $linea) {
                $linea = trim($linea);
                if ($linea === '') continue;
                // Acepta "codigo cantidad", "codigo;cantidad" o "codigo,cantidad".
                // OJO: la coma tambien es decimal en Chile ("8,5"), asi que
                // primero se prueba separar por espacio o punto y coma; solo si
                // eso no da dos partes se usa la PRIMERA coma (los codigos no
                // llevan comas, las cantidades si).
                $p = preg_split('/[\s;]+/', $linea, -1, PREG_SPLIT_NO_EMPTY);
                if (count($p) < 2) {
                    $i = strpos($linea, ',');
                    if ($i === false) { $malos[] = 'línea ' . ($n + 1); continue; }
                    $p = [substr($linea, 0, $i), substr($linea, $i + 1)];
                }
                $cant = parsear_monto(array_pop($p));
                $cod  = trim(implode(' ', $p));
                if ($cant === null) { $malos[] = 'línea ' . ($n + 1); continue; }
                $st = $pdo->prepare("SELECT id FROM productos WHERE codigo = ?");
                $st->execute([$cod]);
                $pid = $st->fetchColumn();
                if (!$pid) { $malos[] = $cod; continue; }
                $delta = $cant - stock_de($pdo, (int)$pid, $neg);
                if (abs($delta) > 0.001) {
                    mover_stock($pdo, $usuario, $neg, (int)$pid, $delta, 'inicial', null, null, 'Conteo');
                }
                $ok++;
            }
            registrar_auditoria($pdo, $usuario, 'productos', 'conteo_inventario', null,
                "Cargó un conteo de inventario: $ok producto(s)", null, 'negocio', $neg);
            $aviso = "Conteo cargado: $ok producto(s) actualizado(s)."
                   . ($malos ? ' No se reconocieron: ' . implode(', ', array_slice($malos, 0, 8))
                       . (count($malos) > 8 ? ' y ' . (count($malos) - 8) . ' más' : '') . '.' : '');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

switch ($_GET['msg'] ?? '') {
    case 'on':     $aviso = 'Inventario encendido. Ahora las ventas descuentan stock.'; break;
    case 'off':    $aviso = 'Inventario apagado. El POS deja de hablar de stock.'; break;
    case 'ajuste': $aviso = 'Movimiento registrado.'; break;
    case 'minimo': $aviso = 'Aviso de stock bajo actualizado.'; break;
}

$controla = negocio_controla_stock($pdo, $negocioId);
$q    = trim($_GET['q'] ?? '');
$solo = $_GET['solo'] ?? '';
$resumen = $controla ? resumen_inventario($pdo, $negocioId) : null;
$lista   = ($controla && ($q !== '' || $solo !== '')) ? productos_con_stock($pdo, $negocioId, $q, $solo) : [];
$verKardex = isset($_GET['kardex']) ? (int)$_GET['kardex'] : 0;
$kardex = $verKardex ? kardex_producto($pdo, $verKardex, $negocioId) : [];
$prodKardex = null;
if ($verKardex) {
    $st = $pdo->prepare("SELECT * FROM productos WHERE id = ?");
    $st->execute([$verKardex]);
    $prodKardex = $st->fetch();
}

const KARDEX_ETIQUETAS = [
    'inicial'    => 'Conteo',      'venta'      => 'Venta',
    'compra'     => 'Compra',      'ajuste'     => 'Ajuste',
    'merma'      => 'Merma',       'devolucion' => 'Devolución',
];

function n3($v) { return rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ','); }
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Inventario'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('inventario') ?></span>
                <h2>Inventario</h2>
            </div>
            <div class="cab-botones">
                <a class="btn gris" href="compras.php?negocio_id=<?= $negocioId ?>">Compras</a>
                <a class="btn gris" href="herramienta_productos.php">Catálogo</a>
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
            <!-- ================= Apagado ================= -->
            <div class="panel">
                <h2 class="panel-titulo">El inventario está apagado en este negocio</h2>
                <p class="texto-ayuda">
                    Puedes vender perfectamente sin llevar existencias: el POS no menciona el
                    stock en ninguna parte. Enciéndelo solo cuando estés listo para
                    <strong>contar los productos y mantener el conteo al día</strong>.
                </p>
                <div class="aviso-compartido panel">
                    <p>
                        <strong>Ojo:</strong> un stock mal mantenido es peor que no tener stock,
                        porque da números falsos. Si recibes mercadería y no la cargas, el
                        sistema va a decir que tienes menos de lo que tienes.
                    </p>
                </div>
                <?php if ($esAdmin): ?>
                    <form method="post" onsubmit="return confirm('¿Encender el control de inventario en este negocio? Desde ahora las ventas van a descontar stock.')">
                        <input type="hidden" name="accion" value="control">
                        <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                        <input type="hidden" name="encender" value="1">
                        <button class="btn verde" type="submit">Encender el inventario aquí</button>
                    </form>
                <?php else: ?>
                    <p class="vacio">Pídele a un administrador que lo encienda.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- ================= Resumen ================= -->
            <div class="ingresos-stats stats-compactas">
                <div class="stat-card">
                    <span class="stat-label">Productos con existencias</span>
                    <span class="stat-valor"><?= (int)$resumen['con_stock'] ?></span>
                    <span class="stat-comp texto-tenue">de <?= (int)$resumen['total_catalogo'] ?> del catálogo</span>
                </div>
                <div class="stat-card t-verde">
                    <span class="stat-label">Valor a precio de costo</span>
                    <span class="stat-valor"><?= clp($resumen['valor_costo']) ?: '$0' ?></span>
                    <span class="stat-comp texto-tenue">lo que tienes invertido</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Valor a precio de venta</span>
                    <span class="stat-valor"><?= clp($resumen['valor_venta']) ?: '$0' ?></span>
                </div>
                <a class="stat-card <?= (int)$resumen['bajos'] ? 't-naranjo' : '' ?>"
                   href="inventario.php?negocio_id=<?= $negocioId ?>&solo=bajos">
                    <span class="stat-label">Bajo el mínimo</span>
                    <span class="stat-valor <?= (int)$resumen['bajos'] ? 'valor-naranjo' : '' ?>">
                        <?= (int)$resumen['bajos'] ?>
                    </span>
                    <span class="stat-comp texto-tenue">hay que reponer</span>
                </a>
                <a class="stat-card <?= (int)$resumen['negativos'] ? 't-rojo' : '' ?>"
                   href="inventario.php?negocio_id=<?= $negocioId ?>&solo=negativos">
                    <span class="stat-label">En negativo</span>
                    <span class="stat-valor <?= (int)$resumen['negativos'] ? 'valor-rojo' : '' ?>">
                        <?= (int)$resumen['negativos'] ?>
                    </span>
                    <span class="stat-comp texto-tenue">revisar el conteo</span>
                </a>
            </div>

            <!-- ================= Buscar ================= -->
            <div class="panel">
                <h2 class="panel-titulo">Buscar productos</h2>
                <form method="get" class="filtros">
                    <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                    <div class="campo" style="flex:1 1 240px">
                        <label>Código o nombre</label>
                        <input type="text" name="q" value="<?= h($q) ?>" autocomplete="off"
                               placeholder="Coca Cola o 7801234567890" style="width:100%">
                    </div>
                    <div class="campo">
                        <label>Mostrar</label>
                        <select name="solo">
                            <option value="">Todos</option>
                            <option value="con_stock" <?= $solo === 'con_stock' ? 'selected' : '' ?>>Con existencias</option>
                            <option value="bajos"     <?= $solo === 'bajos' ? 'selected' : '' ?>>Bajo el mínimo</option>
                            <option value="negativos" <?= $solo === 'negativos' ? 'selected' : '' ?>>En negativo</option>
                        </select>
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <button class="btn" type="submit">Buscar</button>
                    </div>
                </form>

                <?php if ($q === '' && $solo === ''): ?>
                    <p class="vacio">Busca un producto o elige un filtro para ver las existencias.</p>
                <?php elseif (!$lista): ?>
                    <p class="vacio">No hay productos que coincidan.</p>
                <?php else: ?>
                    <div class="tabla-wrap">
                        <table>
                            <thead><tr>
                                <th>Código</th><th>Producto</th><th>Departamento</th>
                                <th class="col-num">Stock</th><th class="col-num">Mínimo</th>
                                <th class="col-num">Valor costo</th><th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($lista as $p): ?>
                                <?php
                                $neg0 = (float)$p['stock'] < 0;
                                $bajo = !$neg0 && $p['stock_minimo'] !== null && (float)$p['stock'] <= (float)$p['stock_minimo'];
                                ?>
                                <tr>
                                    <td class="texto-tenue"><?= h($p['codigo']) ?></td>
                                    <td><?= h($p['nombre']) ?></td>
                                    <td class="texto-tenue"><?= h($p['departamento'] ?: '—') ?></td>
                                    <td class="col-num">
                                        <span class="stock-val <?= $neg0 ? 'st-neg' : ($bajo ? 'st-bajo' : '') ?>">
                                            <?= n3($p['stock']) ?>
                                        </span>
                                    </td>
                                    <td class="col-num texto-tenue"><?= $p['stock_minimo'] !== null ? n3($p['stock_minimo']) : '—' ?></td>
                                    <td class="col-num texto-tenue"><?= $p['precio_costo'] !== null ? clp((float)$p['stock'] * (float)$p['precio_costo']) : '—' ?></td>
                                    <td>
                                        <a class="btn sm gris" href="inventario.php?negocio_id=<?= $negocioId ?>&q=<?= urlencode($q) ?>&kardex=<?= (int)$p['id'] ?>">Movimientos</a>
                                    </td>
                                </tr>
                                <tr class="fila-acciones">
                                    <td colspan="7">
                                        <form method="post" class="filtros filtros-compacto">
                                            <input type="hidden" name="accion" value="ajuste">
                                            <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                                            <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">
                                            <div class="campo">
                                                <select name="tipo">
                                                    <option value="ajuste">Ajuste</option>
                                                    <option value="merma">Merma (se perdió)</option>
                                                </select>
                                            </div>
                                            <div class="campo">
                                                <select name="modo">
                                                    <option value="sumar">Sumar/restar</option>
                                                    <option value="dejar_en">Dejar en</option>
                                                </select>
                                            </div>
                                            <div class="campo">
                                                <input type="text" name="cantidad" inputmode="decimal"
                                                       placeholder="cantidad" required style="width:100px">
                                            </div>
                                            <div class="campo" style="flex:1 1 180px">
                                                <input type="text" name="motivo" placeholder="Motivo *" required style="width:100%">
                                            </div>
                                            <div class="campo">
                                                <button class="btn sm" type="submit">Aplicar</button>
                                            </div>
                                        </form>
                                        <form method="post" class="filtros filtros-compacto">
                                            <input type="hidden" name="accion" value="minimo">
                                            <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                                            <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">
                                            <input type="hidden" name="q" value="<?= h($q) ?>">
                                            <div class="campo">
                                                <input type="text" name="minimo" inputmode="decimal" style="width:110px"
                                                       value="<?= $p['stock_minimo'] !== null ? h(n3($p['stock_minimo'])) : '' ?>"
                                                       placeholder="avisar bajo">
                                            </div>
                                            <div class="campo">
                                                <button class="btn sm gris" type="submit">Guardar aviso</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($prodKardex): ?>
            <!-- ================= Kardex ================= -->
            <div class="panel">
                <h2 class="panel-titulo">
                    Movimientos de <?= h($prodKardex['nombre']) ?>
                    <span class="texto-tenue"><?= h($prodKardex['codigo']) ?></span>
                </h2>
                <?php if (!$kardex): ?>
                    <p class="vacio">Este producto todavía no tiene movimientos.</p>
                <?php else: ?>
                    <div class="tabla-wrap">
                        <table>
                            <thead><tr>
                                <th>Cuándo</th><th>Qué</th><th class="col-num">Cantidad</th>
                                <th class="col-num">Quedaron</th><th>Motivo</th><th>Quién</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($kardex as $m): ?>
                                <tr>
                                    <td class="texto-tenue"><?= h(date('d-m-Y H:i', strtotime($m['creado_en']))) ?></td>
                                    <td><?= h(KARDEX_ETIQUETAS[$m['tipo']] ?? $m['tipo']) ?></td>
                                    <td class="col-num">
                                        <span class="<?= (float)$m['cantidad'] < 0 ? 'valor-rojo' : 'valor-verde' ?>">
                                            <?= ((float)$m['cantidad'] > 0 ? '+' : '') . n3($m['cantidad']) ?>
                                        </span>
                                    </td>
                                    <td class="col-num"><?= n3($m['stock_resultante']) ?></td>
                                    <td class="texto-tenue"><?= h($m['motivo'] ?: '—') ?></td>
                                    <td class="texto-tenue"><?= h($m['usuario'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ================= Conteo inicial ================= -->
            <details class="bloque panel">
                <summary><h2 class="panel-titulo">Cargar un conteo</h2></summary>
                <p class="texto-ayuda">
                    Para la toma de inventario: pega una línea por producto con el
                    <strong>código y la cantidad contada</strong>. El sistema deja el stock
                    exactamente en esa cantidad.
                </p>
                <form method="post">
                    <input type="hidden" name="accion" value="conteo">
                    <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                    <div class="campo">
                        <label>Conteo (código y cantidad por línea)</label>
                        <textarea name="conteo" rows="8" style="width:100%;font-family:monospace"
placeholder="7801234567890 24
1100000100 8,5
7802820331530 12"></textarea>
                    </div>
                    <div class="form-acciones">
                        <button class="btn" type="submit">Cargar conteo</button>
                    </div>
                </form>
            </details>

            <?php if ($esAdmin): ?>
            <details class="bloque panel">
                <summary><h2 class="panel-titulo">Apagar el inventario</h2></summary>
                <p class="texto-ayuda">
                    El POS deja de hablar de stock y las ventas dejan de descontar. Las
                    existencias y el historial <strong>no se borran</strong>: quedan tal cual
                    por si lo vuelves a encender.
                </p>
                <form method="post" onsubmit="return confirm('¿Apagar el control de inventario en este negocio?')">
                    <input type="hidden" name="accion" value="control">
                    <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                    <button class="btn rojo" type="submit">Apagar el inventario aquí</button>
                </form>
            </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
