<?php
/**
 * Fiados: listado de clientes de cada negocio con su saldo pendiente.
 * Saldo = total fiado - total abonado (cuenta corriente). Acceso admin+sucursal,
 * siempre acotado a los negocios visibles del usuario.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('fiados');
$pdo = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

// --- Filtros ---
$fNegocio = isset($_GET['negocio']) ? (int)$_GET['negocio'] : 0;
$fTexto   = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if (empty($idsVisibles)) {
    $where[] = '0=1';
} else {
    $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
    $where[] = "c.negocio_id IN ($ph)";
    array_push($params, ...$idsVisibles);
}
if ($fNegocio && in_array($fNegocio, $idsVisibles, true)) {
    $where[] = 'c.negocio_id = ?';
    $params[] = $fNegocio;
}
if ($fTexto !== '') {
    $where[] = '(c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)';
    $params[] = "%$fTexto%"; $params[] = "%$fTexto%"; $params[] = "%$fTexto%";
}
$where[] = 'c.activo = 1';

$sql = "SELECT c.*, n.nombre AS negocio_nombre,
          (SELECT COALESCE(SUM(f.monto),0) FROM fiados f WHERE f.cliente_id = c.id)
        - (SELECT COALESCE(SUM(a.monto),0) FROM abonos a WHERE a.cliente_id = c.id) AS saldo
        FROM clientes c JOIN negocios n ON n.id = c.negocio_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY saldo DESC, c.nombre, c.apellido";
$st = $pdo->prepare($sql);
$st->execute($params);
$clientes = $st->fetchAll();

$totalDeuda = 0;
foreach ($clientes as $c) { $totalDeuda += max(0, (float)$c['saldo']); }

// Texto de "viendo"
if ($fNegocio) {
    foreach ($negocios as $n) { if ((int)$n['id'] === $fNegocio) $viendo = $n['nombre']; }
} elseif (count($negocios) === 1) {
    $viendo = $negocios[0]['nombre'];
} else {
    $viendo = 'Todos los negocios';
}
$viendo = $viendo ?? 'Todos los negocios';
?>
<?php cabecera_dashboard($usuario, 'fiados', 'Fiados'); ?>

    <div class="contenido">
        <div class="cab-acciones">
            <h2>Fiados</h2>
            <a class="btn" href="cliente_form.php">+ Nuevo cliente</a>
        </div>

        <div class="panel">
            <h2>Filtros</h2>
            <form class="filtros" method="get">
                <div class="campo campo-busqueda">
                    <label>Buscar cliente</label>
                    <input type="text" name="q" value="<?= h($fTexto) ?>"
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
                                <?= h($n['nombre']) ?>
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
            <?= count($clientes) ?> cliente(s) ·
            Total adeudado: <strong class="saldo-deuda"><?= clp($totalDeuda) ?></strong>
            <span class="badge-negocio" style="margin-left:8px">
                <?= icono('negocios') ?> Viendo: <strong><?= h($viendo) ?></strong>
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
                        <td><?= h(trim($c['nombre'] . ' ' . $c['apellido'])) ?></td>
                        <td class="col-ocultar-movil"><?= h($c['telefono']) ?: '—' ?></td>
                        <?php if (count($negocios) > 1): ?>
                            <td class="col-ocultar-movil"><?= h($c['negocio_nombre']) ?></td>
                        <?php endif; ?>
                        <td class="monto-col <?= $clase ?>"><?= clp($saldo) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
