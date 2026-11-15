<?php
/**
 * Vista de app/Controllers/GastosController::panel().
 * Variables disponibles: $usuario, $negocios, $mismoNegocio, $fNegocio, $fDesde,
 * $fHasta, $fCategoria, $categorias, $flash, $gastos, $viendo, $pagina, $porPag,
 * $total, $paginas.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

/** Arma la URL de la página N conservando los filtros activos. */
function url_pagina_gasto(int $n): string
{
    $p = $_GET; $p['p'] = $n;
    return 'gastos.php?' . http_build_query($p);
}
?>
<?php cabecera_dashboard($usuario, 'gastos', 'Gastos'); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-rojo">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('gastos') ?></span>
                <h2>Gastos</h2>
            </div>
            <div class="cab-modulo-der">
                <span class="badge-negocio">
                    <?= icono('negocios') ?>
                    Viendo: <strong><?= e($viendo) ?></strong>
                </span>
                <a class="btn gris" href="categorias_gasto.php">Categorías</a>
                <a class="btn-pastilla btn-pastilla-rojo" href="gasto_form.php">
                    <?= icono('gastos') ?> Registrar gasto
                </a>
            </div>
        </div>

        <?php if ($flash): ?><div class="aviso-ok"><?= e($flash) ?></div><?php endif; ?>

        <!-- Filtros (plegable: mismo patrón que Facturas/Fiados) -->
        <?php // $fDesde/$fHasta siempre vienen con un valor por defecto (mes actual) desde el
        // Controlador, así que para saber si el usuario realmente filtró hay que mirar el GET crudo.
        $filtrosActivos = (isset($_GET['desde']) || isset($_GET['hasta']) || $fNegocio !== 0 || $fCategoria !== 0); ?>
        <div class="panel panel-filtros<?= $filtrosActivos ? '' : ' colapsado' ?>" id="panelFiltros">
            <button type="button" class="panel-filtros-cab" id="btnToggleFiltros"
                    aria-expanded="<?= $filtrosActivos ? 'true' : 'false' ?>">
                <h2>Filtros</h2>
                <span class="chevron"><?= icono('chevron') ?></span>
            </button>
            <form class="filtros" method="get">
                <?php if (!$mismoNegocio): ?>
                <div class="campo">
                    <label>Negocio</label>
                    <select name="negocio">
                        <option value="0">Todos los negocios</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= $fNegocio === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="campo">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= e($fDesde) ?>">
                </div>
                <div class="campo">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= e($fHasta) ?>">
                </div>
                <div class="campo">
                    <label>Categoría</label>
                    <select name="categoria">
                        <option value="0">Todas</option>
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $fCategoria === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= e($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Filtrar</button>
                    <a class="btn gris" href="gastos.php">Limpiar</a>
                </div>
            </form>
        </div>
        <script>
          // Panel de Filtros plegable: mismo patrón que panel_facturas.php / fiados.php.
          (function () {
            var panel = document.getElementById('panelFiltros');
            var btn = document.getElementById('btnToggleFiltros');
            if (!panel || !btn) return;
            btn.addEventListener('click', function () {
              var abierto = !panel.classList.contains('colapsado');
              panel.classList.toggle('colapsado', abierto);
              btn.setAttribute('aria-expanded', abierto ? 'false' : 'true');
            });
          })();
        </script>

        <div class="resumen">
            <?= $total ?> gasto(s)<?= $paginas > 1 ? " · página $pagina de $paginas" : '' ?>
        </div>

        <!-- Tabla de gastos -->
        <div class="tabla-wrap">
            <table class="tabla-gastos">
                <thead><tr>
                    <th>Fecha</th>
                    <th>Categoría</th>
                    <th class="col-ocultar-movil">Descripción</th>
                    <?php if (!$mismoNegocio): ?><th class="col-ocultar-movil">Negocio</th><?php endif; ?>
                    <th class="monto-col">Monto</th>
                </tr></thead>
                <tbody>
                <?php if (!$gastos): ?>
                    <tr><td class="vacio" colspan="5">No hay gastos en el período. Registra el primero con "+ Registrar gasto".</td></tr>
                <?php else: foreach ($gastos as $g): ?>
                    <tr class="fila-click" onclick="location.href='gasto_form.php?id=<?= (int)$g['id'] ?>'">
                        <td class="nowrap"><?= e(fecha_dmy($g['fecha'])) ?></td>
                        <td><?= e($g['categoria']) ?></td>
                        <td class="col-ocultar-movil"><?= e($g['descripcion']) ?: '—' ?></td>
                        <?php if (!$mismoNegocio): ?><td class="col-ocultar-movil"><?= e($g['negocio']) ?></td><?php endif; ?>
                        <td class="monto-col valor-rojo"><?= clp($g['monto']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($paginas > 1): ?>
            <div class="paginacion">
                <?php if ($pagina > 1): ?>
                    <a class="btn gris sm" href="<?= e(url_pagina_gasto($pagina - 1)) ?>">← Anteriores</a>
                <?php endif; ?>
                <span class="texto-tenue">Página <?= $pagina ?> de <?= $paginas ?></span>
                <?php if ($pagina < $paginas): ?>
                    <a class="btn gris sm" href="<?= e(url_pagina_gasto($pagina + 1)) ?>">Siguientes →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
