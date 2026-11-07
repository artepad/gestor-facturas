<?php
/**
 * Vista de app/Controllers/InventarioController::index().
 *
 * Variables disponibles: $usuario, $negocios, $negocioId, $esAdmin, $error,
 * $aviso, $controla, $resumen, $q, $solo, $lista, $verKardex, $kardex,
 * $prodKardex, $baseAntiguo, $baseNuevo, $csrfToken.
 *
 * Mismo criterio que app/views/caja/index.php: reusa clp()/icono() de
 * lib/ui.php (formato y SVG) pero con encabezado propio, sin
 * cabecera_dashboard()/pie_dashboard() (esas arman el sidebar con links al
 * sitio viejo). El sidebar completo vuelve en la Fase 9.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

function n3inv($v) { return rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ','); }

const KARDEX_ETIQUETAS_VISTA = [
    'inicial'    => 'Conteo',      'venta'      => 'Venta',
    'compra'     => 'Compra',      'ajuste'     => 'Ajuste',
    'merma'      => 'Merma',       'devolucion' => 'Devolución',
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventario (vista previa) · Minimark</title>
<link rel="stylesheet" href="<?= e($baseAntiguo) ?>/assets/estilo.css">
</head>
<body>
<div style="background:#22313f;color:#fff;padding:10px 20px;font-size:14px;display:flex;justify-content:space-between;align-items:center">
    <span>🧪 Vista previa de la migración (Fase 6) — misma lógica que <code>inventario.php</code>, interfaz todavía sin el menú lateral.</span>
    <a href="<?= e($baseAntiguo) ?>/inventario.php" style="color:#9fd3ff">Volver a la pantalla actual</a>
</div>
<div class="contenido" style="max-width:1100px;margin:0 auto;padding:20px">
    <div class="cab-modulo modulo-turquesa">
        <div class="cab-modulo-tit">
            <span class="modulo-badge"><?= icono('inventario') ?></span>
            <h2>Inventario</h2>
        </div>
        <div class="cab-botones">
            <a class="btn gris" href="<?= e($baseNuevo) ?>/compras/?negocio_id=<?= $negocioId ?>">Compras</a>
            <a class="btn gris" href="<?= e($baseAntiguo) ?>/herramienta_productos.php">Catálogo</a>
            <?php if (count($negocios) > 1): ?>
            <form method="get">
                <select name="negocio_id" onchange="this.form.submit()">
                    <?php foreach ($negocios as $n): ?>
                        <option value="<?= (int)$n['id'] ?>" <?= $negocioId === (int)$n['id'] ? 'selected' : '' ?>>
                            <?= e($n['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($aviso): ?><div class="panel exito"><p><?= e($aviso) ?></p></div><?php endif; ?>

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
                <form method="post" action="<?= e($baseNuevo) ?>/inventario/" onsubmit="return confirm('¿Encender el control de inventario en este negocio? Desde ahora las ventas van a descontar stock.')">
                    <?= campo_csrf() ?>
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
               href="<?= e($baseNuevo) ?>/inventario/?negocio_id=<?= $negocioId ?>&solo=bajos">
                <span class="stat-label">Bajo el mínimo</span>
                <span class="stat-valor <?= (int)$resumen['bajos'] ? 'valor-naranjo' : '' ?>">
                    <?= (int)$resumen['bajos'] ?>
                </span>
                <span class="stat-comp texto-tenue">hay que reponer</span>
            </a>
            <a class="stat-card <?= (int)$resumen['negativos'] ? 't-rojo' : '' ?>"
               href="<?= e($baseNuevo) ?>/inventario/?negocio_id=<?= $negocioId ?>&solo=negativos">
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
                    <input type="text" name="q" value="<?= e($q) ?>" autocomplete="off"
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
                                <td class="texto-tenue"><?= e($p['codigo']) ?></td>
                                <td><?= e($p['nombre']) ?></td>
                                <td class="texto-tenue"><?= e($p['departamento'] ?: '—') ?></td>
                                <td class="col-num">
                                    <span class="stock-val <?= $neg0 ? 'st-neg' : ($bajo ? 'st-bajo' : '') ?>">
                                        <?= n3inv($p['stock']) ?>
                                    </span>
                                </td>
                                <td class="col-num texto-tenue"><?= $p['stock_minimo'] !== null ? n3inv($p['stock_minimo']) : '—' ?></td>
                                <td class="col-num texto-tenue"><?= $p['precio_costo'] !== null ? clp((float)$p['stock'] * (float)$p['precio_costo']) : '—' ?></td>
                                <td>
                                    <a class="btn sm gris" href="<?= e($baseNuevo) ?>/inventario/?negocio_id=<?= $negocioId ?>&q=<?= urlencode($q) ?>&kardex=<?= (int)$p['id'] ?>">Movimientos</a>
                                </td>
                            </tr>
                            <tr class="fila-acciones">
                                <td colspan="7">
                                    <form method="post" action="<?= e($baseNuevo) ?>/inventario/" class="filtros filtros-compacto">
                                        <?= campo_csrf() ?>
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
                                    <form method="post" action="<?= e($baseNuevo) ?>/inventario/" class="filtros filtros-compacto">
                                        <?= campo_csrf() ?>
                                        <input type="hidden" name="accion" value="minimo">
                                        <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                                        <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">
                                        <input type="hidden" name="q" value="<?= e($q) ?>">
                                        <div class="campo">
                                            <input type="text" name="minimo" inputmode="decimal" style="width:110px"
                                                   value="<?= $p['stock_minimo'] !== null ? e(n3inv($p['stock_minimo'])) : '' ?>"
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
                Movimientos de <?= e($prodKardex['nombre']) ?>
                <span class="texto-tenue"><?= e($prodKardex['codigo']) ?></span>
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
                                <td class="texto-tenue"><?= e(date('d-m-Y H:i', strtotime($m['creado_en']))) ?></td>
                                <td><?= e(KARDEX_ETIQUETAS_VISTA[$m['tipo']] ?? $m['tipo']) ?></td>
                                <td class="col-num">
                                    <span class="<?= (float)$m['cantidad'] < 0 ? 'valor-rojo' : 'valor-verde' ?>">
                                        <?= ((float)$m['cantidad'] > 0 ? '+' : '') . n3inv($m['cantidad']) ?>
                                    </span>
                                </td>
                                <td class="col-num"><?= n3inv($m['stock_resultante']) ?></td>
                                <td class="texto-tenue"><?= e($m['motivo'] ?: '—') ?></td>
                                <td class="texto-tenue"><?= e($m['usuario'] ?: '—') ?></td>
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
            <form method="post" action="<?= e($baseNuevo) ?>/inventario/">
                <?= campo_csrf() ?>
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
            <form method="post" action="<?= e($baseNuevo) ?>/inventario/" onsubmit="return confirm('¿Apagar el control de inventario en este negocio?')">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="control">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <button class="btn rojo" type="submit">Apagar el inventario aquí</button>
            </form>
        </details>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script>
Array.prototype.forEach.call(document.querySelectorAll('input[inputmode=numeric]'), function (el) {
    el.addEventListener('input', function () {
        var v = el.value.replace(/[^0-9.]/g, '');
        if (v !== el.value) el.value = v;
    });
});
</script>
</body>
</html>
