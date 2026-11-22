<?php
/**
 * Vista de app/Controllers/ProductosController::listado() — puerta de
 * entrada del módulo Productos (productos.php).
 * Variables disponibles: $usuario, $negocios, $filtros, $pagina, $porPag,
 * $total, $paginas, $filas, $deptos, $error, $msg.
 *
 * Cada fila trae, además de los campos de siempre, "editor" (quién la editó
 * por última vez), "unidades_vendidas" y "ultima_venta" (resumen de ventas
 * ya filtrado por negocio si corresponde) — todo calculado en
 * App\Models\Producto::listado(), sin tocar ninguna tabla del POS.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

$q            = $filtros['q'] ?? '';
$fDepto       = $filtros['departamento'] ?? '';
$fEstado      = $filtros['estado'] ?? '';
$fMovimiento  = $filtros['movimiento'] ?? '';
$fNegocio     = (int)($filtros['negocio'] ?? 0);
$ordenActual  = $filtros['orden'] ?: 'nombre';
$dirActual    = strtoupper($filtros['dir'] ?: 'ASC') === 'ASC' ? 'ASC' : 'DESC';

$filtrosActivos = $q !== '' || $fDepto !== '' || $fEstado !== '' || $fMovimiento !== '' || $fNegocio !== 0;

/** Arma la URL de la página N conservando los filtros y el orden activos. */
function url_pagina_producto(int $n): string
{
    $p = $_GET; $p['p'] = $n;
    return 'productos.php?' . http_build_query($p);
}

/** Arma la URL para ordenar por una columna (invierte la dirección si ya estaba activa). */
$urlOrden = function (string $campo) use ($ordenActual, $dirActual): string {
    $nuevaDir = ($ordenActual === $campo && $dirActual === 'ASC') ? 'DESC' : 'ASC';
    $p = $_GET; $p['orden'] = $campo; $p['dir'] = $nuevaDir; unset($p['p']);
    return 'productos.php?' . http_build_query($p);
};
/** Flechita que indica la columna y dirección de orden activas. */
$flecha = function (string $campo) use ($ordenActual, $dirActual): string {
    if ($ordenActual !== $campo) return '';
    return $dirActual === 'ASC' ? ' ▲' : ' ▼';
};

/** Unidades vendidas, sin ceros decimales de sobra (los productos a granel venden fracciones de kilo). */
$fmtUnidades = function ($v): string {
    $n = (float)$v;
    if ($n == 0) return '0';
    $txt = number_format($n, 2, ',', '.');
    return rtrim(rtrim($txt, '0'), ',');
};

/** Solo el primer nombre, para que "Modificado por" ocupe menos espacio en esta tabla. */
$primerNombre = function (string $nombre): string {
    $partes = preg_split('/\s+/', trim($nombre));
    return $partes[0] !== '' ? $partes[0] : $nombre;
};

/** El Excel de Eleventa deja literalmente "- Sin Departamento -" en vez de vacío; acá se achica a un simple "-". */
$fmtDepto = function (?string $d): string {
    $d = trim((string)$d);
    return ($d === '' || $d === '- Sin Departamento -') ? '—' : $d;
};
?>
<?php cabecera_dashboard($usuario, 'productos', 'Productos'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('productos') ?></span>
                <h2>Productos</h2>
            </div>
            <div class="cab-modulo-der">
                <a class="btn gris" href="historial_productos.php">Historial</a>
                <?php if (puede($usuario, 'usuarios')): ?>
                    <a class="btn gris" href="herramienta_unificar_productos.php">Unificar catálogos</a>
                <?php endif; ?>
                <?php if (puede($usuario, 'usuarios')): ?>
                    <a class="btn gris" href="herramienta_productos.php">Cargar Excel</a>
                <?php endif; ?>
                <a class="btn gris" href="producto_exportar.php">Descargar (Excel)</a>
                <a class="btn-pastilla btn-pastilla-turquesa" href="producto_form.php">
                    <?= icono('productos') ?> Nuevo producto
                </a>
            </div>
        </div>

        <p class="texto-ayuda">
            El catálogo es <strong>compartido por los dos negocios</strong>: lo que cambies acá se ve
            en ambos, y cada cambio queda registrado con tu nombre.
        </p>

        <?php if ($error): ?>
            <div class="aviso-flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($msg === 'guardado'): ?>
            <div class="aviso-flash flash-ok">Producto guardado. El cambio ya está en los dos negocios.</div>
        <?php elseif ($msg === 'eliminado'): ?>
            <div class="aviso-flash flash-ok">Producto eliminado del catálogo.</div>
        <?php endif; ?>

        <div class="panel panel-filtros<?= $filtrosActivos ? '' : ' colapsado' ?>" id="panelFiltros">
            <button type="button" class="panel-filtros-cab" id="btnToggleFiltros"
                    aria-expanded="<?= $filtrosActivos ? 'true' : 'false' ?>">
                <h2>Filtros</h2>
                <span class="chevron"><?= icono('chevron') ?></span>
            </button>
            <form class="filtros" method="get">
                <div class="campo campo-busqueda">
                    <label>Buscar</label>
                    <input type="text" name="q" value="<?= h($q) ?>" autocomplete="off"
                           placeholder="Nombre o código de barras">
                </div>
                <div class="campo">
                    <label>Departamento</label>
                    <select name="departamento" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($deptos as $d): ?>
                            <option value="<?= h($d) ?>" <?= $fDepto === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label>Estado</label>
                    <select name="estado" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="activo"   <?= $fEstado === 'activo'   ? 'selected' : '' ?>>Activos</option>
                        <option value="inactivo" <?= $fEstado === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
                    </select>
                </div>
                <div class="campo">
                    <label>Sin movimiento</label>
                    <select name="movimiento" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="30"    <?= $fMovimiento === '30'    ? 'selected' : '' ?>>30+ días sin vender</option>
                        <option value="90"    <?= $fMovimiento === '90'    ? 'selected' : '' ?>>90+ días sin vender</option>
                        <option value="180"   <?= $fMovimiento === '180'   ? 'selected' : '' ?>>180+ días sin vender</option>
                        <option value="nunca" <?= $fMovimiento === 'nunca' ? 'selected' : '' ?>>Nunca registradas</option>
                    </select>
                </div>
                <?php if (count($negocios) > 1): ?>
                <div class="campo">
                    <label>Ventas de</label>
                    <select name="negocio" onchange="this.form.submit()">
                        <option value="0">Los dos negocios</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= $fNegocio === (int)$n['id'] ? 'selected' : '' ?>>
                                Solo <?= h($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Filtrar</button>
                    <a class="btn gris" href="productos.php">Limpiar</a>
                </div>
            </form>
        </div>

        <?php if ($fMovimiento === '90' || $fMovimiento === '180'): ?>
            <p class="campo-ayuda">
                El sistema recién empezó a registrar ventas por este medio: es normal que esta franja
                muestre pocos resultados (o ninguno) por ahora — se va a ir llenando con el tiempo.
            </p>
        <?php endif; ?>

        <div class="resumen">
            <?= $total ?> producto(s)<?= $paginas > 1 ? " · página $pagina de $paginas" : '' ?>
        </div>

        <div class="tabla-wrap tabla-productos">
            <table>
                <thead>
                    <tr>
                        <th><a href="<?= h($urlOrden('codigo')) ?>">Código<?= $flecha('codigo') ?></a></th>
                        <th><a href="<?= h($urlOrden('nombre')) ?>">Producto<?= $flecha('nombre') ?></a></th>
                        <th class="col-ocultar-movil col-nowrap col-centro">Departamento</th>
                        <th class="col-num col-precio"><a href="<?= h($urlOrden('precio_venta')) ?>">Venta<?= $flecha('precio_venta') ?></a></th>
                        <th class="col-nowrap"><a href="<?= h($urlOrden('actualizado_en')) ?>">Últ. cambio<?= $flecha('actualizado_en') ?></a></th>
                        <th class="col-ocultar-movil col-nowrap">Modificado por</th>
                        <th class="col-num"><a href="<?= h($urlOrden('unidades')) ?>">Vendidos<?= $flecha('unidades') ?></a></th>
                        <th class="col-nowrap"><a href="<?= h($urlOrden('ultima_venta')) ?>">Últ. venta<?= $flecha('ultima_venta') ?></a></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$filas): ?>
                    <tr><td class="vacio" colspan="9">No hay productos con esos filtros.</td></tr>
                <?php else: foreach ($filas as $p): ?>
                    <tr<?= $p['activo'] ? '' : ' class="fila-inactiva"' ?>>
                        <td><?= h($p['codigo']) ?></td>
                        <td>
                            <?= h($p['nombre']) ?>
                            <?php if (!$p['activo']): ?><span class="badge-inactivo">Inactivo</span><?php endif; ?>
                        </td>
                        <td class="col-ocultar-movil col-nowrap col-centro"><?= h($fmtDepto($p['departamento'])) ?></td>
                        <td class="col-num col-precio"><?= clp($p['precio_venta']) ?></td>
                        <td class="col-nowrap">
                            <?= $p['actualizado_en'] ? h(date('d-m-Y', strtotime($p['actualizado_en']))) : '—' ?>
                        </td>
                        <td class="col-ocultar-movil col-nowrap">
                            <?php if ($p['editor']): ?>
                                <?= chip_usuario($primerNombre($p['editor']), $p['editor_color'], $p['editor']) ?>
                            <?php else: ?><span class="texto-tenue">—</span><?php endif; ?>
                        </td>
                        <td class="col-num"><span class="chip-vendidos"><?= h($fmtUnidades($p['unidades_vendidas'])) ?></span></td>
                        <td class="col-nowrap col-venta"><?= punto_venta($p['ultima_venta']) ?></td>
                        <td>
                            <span class="acciones-fila">
                                <a class="btn-icono" href="producto_form.php?id=<?= (int)$p['id'] ?>"
                                   title="Editar" aria-label="Editar"><?= icono('lapiz') ?></a>
                                <a class="btn-icono" href="historial_productos.php?q=<?= urlencode($p['codigo']) ?>"
                                   title="Historial de cambios" aria-label="Historial de cambios"><?= icono('reloj') ?></a>
                                <form method="post" class="inline-form">
                                    <?= campo_csrf() ?>
                                    <input type="hidden" name="accion" value="toggle_activo">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <input type="hidden" name="nuevo" value="<?= $p['activo'] ? '0' : '1' ?>">
                                    <button class="btn-icono" type="submit"
                                            title="<?= $p['activo'] ? 'Marcar inactivo' : 'Reactivar' ?>"
                                            aria-label="<?= $p['activo'] ? 'Marcar inactivo' : 'Reactivar' ?>"><?= icono('activar') ?></button>
                                </form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($paginas > 1): ?>
            <div class="paginacion">
                <?php if ($pagina > 1): ?>
                    <a class="btn gris sm" href="<?= h(url_pagina_producto($pagina - 1)) ?>">← Anteriores</a>
                <?php endif; ?>
                <span class="texto-tenue">Página <?= $pagina ?> de <?= $paginas ?></span>
                <?php if ($pagina < $paginas): ?>
                    <a class="btn gris sm" href="<?= h(url_pagina_producto($pagina + 1)) ?>">Siguientes →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
      // Panel de Filtros: plegable para aprovechar espacio (mismo patrón que Facturas/Gastos/Ingresos).
      (function () {
        var panel = document.getElementById('panelFiltros');
        var btn = document.getElementById('btnToggleFiltros');
        if (!panel || !btn) return;
        btn.addEventListener('click', function () {
          var abierto = !panel.classList.contains('colapsado');
          panel.classList.toggle('colapsado');
          btn.setAttribute('aria-expanded', abierto ? 'false' : 'true');
        });
      })();
    </script>
    <?php pie_dashboard(); ?>
