<?php
/**
 * Vista de app/Controllers/FiadosController::listado().
 * Variables disponibles: $usuario, $negocios, $fNegocio, $fTexto, $clientes, $totalDeuda, $viendo.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'fiados', 'Fiados'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-morado">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('fiados') ?></span>
                <h2>Fiados</h2>
            </div>
            <a class="btn" href="cliente_form.php">+ Nuevo cliente</a>
        </div>

        <div class="panel">
            <h2>Filtros</h2>
            <form class="filtros" method="get">
                <div class="campo campo-busqueda">
                    <label>Buscar cliente</label>
                    <input type="text" name="q" value="<?= e($fTexto) ?>"
                           placeholder="Nombre, apellido o teléfono" autofocus>
                </div>
                <?php if (count($negocios) > 1): ?>
                <div class="campo campo-medio">
                    <label>Negocio</label>
                    <select name="negocio" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"
                                <?= $fNegocio === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Buscar</button>
                    <a class="btn gris" href="fiados.php">Limpiar</a>
                </div>
            </form>
        </div>

        <div class="resumen">
            <span><?= count($clientes) ?> cliente(s) ·
                Total adeudado: <strong class="saldo-deuda"><?= clp($totalDeuda) ?></strong></span>
            <span class="badge-negocio">
                <?= icono('negocios') ?> Viendo: <strong><?= e($viendo) ?></strong>
            </span>
        </div>

        <div class="tabla-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th class="col-ocultar-movil">Teléfono</th>
                        <?php if (count($negocios) > 1): ?><th class="col-ocultar-movil">Negocio</th><?php endif; ?>
                        <th class="monto-col">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$clientes): ?>
                    <tr><td class="vacio" colspan="4">No hay clientes. Crea el primero con "+ Nuevo cliente".</td></tr>
                <?php else: foreach ($clientes as $c):
                    $saldo = (float)$c['saldo'];
                    $clase = $saldo > 0 ? 'saldo-deuda' : 'saldo-ok'; ?>
                    <tr class="fila-click" onclick="location.href='cliente.php?id=<?= (int)$c['id'] ?>'">
                        <td><?= e(trim($c['nombre'] . ' ' . $c['apellido'])) ?></td>
                        <td class="col-ocultar-movil"><?= e($c['telefono']) ?: '—' ?></td>
                        <?php if (count($negocios) > 1): ?>
                            <td class="col-ocultar-movil"><?= e($c['negocio_nombre']) ?></td>
                        <?php endif; ?>
                        <td class="monto-col <?= $clase ?>"><?= clp($saldo) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
