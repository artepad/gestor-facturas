<?php
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_login();
$pdo = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

// --- Leer filtros ---
$fNegocio   = isset($_GET['negocio']) ? (int)$_GET['negocio'] : 0;
$fProveedor = trim($_GET['proveedor'] ?? '');
$fDesde     = trim($_GET['desde'] ?? '');
$fHasta     = trim($_GET['hasta'] ?? '');
$fTexto     = trim($_GET['q'] ?? '');

// --- Construir consulta segura ---
$where = [];
$params = [];

// Restriccion de negocio: SIEMPRE limitada a los visibles del usuario
if (empty($idsVisibles)) {
    $where[] = '0=1';   // sucursal sin negocios asignados no ve nada
} else {
    $placeholders = implode(',', array_fill(0, count($idsVisibles), '?'));
    $where[] = "f.negocio_id IN ($placeholders)";
    array_push($params, ...$idsVisibles);
}
// Filtro de negocio especifico (solo si es uno que puede ver)
if ($fNegocio && in_array($fNegocio, $idsVisibles, true)) {
    $where[] = 'f.negocio_id = ?';
    $params[] = $fNegocio;
}
if ($fProveedor !== '') {
    $where[] = 'f.proveedor LIKE ?';
    $params[] = "%$fProveedor%";
}
if ($fDesde !== '') { $where[] = 'f.fecha >= ?'; $params[] = $fDesde; }
if ($fHasta !== '') { $where[] = 'f.fecha <= ?'; $params[] = $fHasta; }
if ($fTexto !== '') {
    $where[] = '(f.proveedor LIKE ? OR f.razon_social LIKE ? OR f.numero_factura LIKE ?)';
    $params[] = "%$fTexto%"; $params[] = "%$fTexto%"; $params[] = "%$fTexto%";
}
$where[] = 'f.eliminada_en IS NULL';

$sql = "SELECT f.*, n.nombre AS negocio_nombre
        FROM facturas f JOIN negocios n ON n.id = f.negocio_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY f.fecha DESC, f.id DESC LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$facturas = $st->fetchAll();
// clp(), fecha_dmy() y estado_factura() viven en lib/ui.php

// Texto que indica qué negocio se está viendo
$nombrePorId = [];
foreach ($negocios as $n) { $nombrePorId[(int)$n['id']] = $n['nombre']; }
if ($fNegocio && isset($nombrePorId[$fNegocio])) {
    $viendo = $nombrePorId[$fNegocio];
} elseif (count($negocios) === 1) {
    $viendo = $negocios[0]['nombre'];   // un solo negocio: mostrar su nombre
} else {
    $viendo = 'Todos los negocios';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facturas · Sistema de Gestión</title>
    <link rel="stylesheet" href="assets/estilo.css">
</head>
<body>
    <?php cabecera_dashboard($usuario, 'facturas'); ?>

    <div class="contenido">
        <div class="cab-acciones">
            <h2>Facturas</h2>
            <span class="badge-negocio">
                <?= icono('negocios') ?>
                Viendo: <strong><?= htmlspecialchars($viendo) ?></strong>
            </span>
        </div>
        <div class="panel">
            <h2>Filtros</h2>
            <form class="filtros" method="get">
                <div class="campo">
                    <label>Búsqueda</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($fTexto) ?>"
                           placeholder="Proveedor, razón social, N°">
                </div>
                <?php if (count($negocios) > 1): ?>
                <div class="campo">
                    <label>Negocio</label>
                    <select name="negocio">
                        <option value="0">Todos</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"
                                <?= $fNegocio === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="campo">
                    <label>Proveedor</label>
                    <input type="text" name="proveedor" value="<?= htmlspecialchars($fProveedor) ?>">
                </div>
                <div class="campo">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= htmlspecialchars($fDesde) ?>">
                </div>
                <div class="campo">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= htmlspecialchars($fHasta) ?>">
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit">Buscar</button>
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <a class="btn gris" href="panel.php">Limpiar</a>
                </div>
            </form>
        </div>

        <div class="resumen"><?= count($facturas) ?> factura(s)</div>

        <div class="tabla-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Proveedor</th>
                        <th>N° Factura</th>
                        <th class="total">Total</th>
                        <th>Razón Social</th>
                        <?php if (count($negocios) > 1): ?><th>Negocio</th><?php endif; ?>
                        <th>Estado</th>
                        <th>PDF</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$facturas): ?>
                    <tr><td class="vacio" colspan="8">No hay facturas con esos filtros.</td></tr>
                <?php else: foreach ($facturas as $f):
                    [$color, $txtEstado] = estado_factura($f); ?>
                    <tr>
                        <td><?= htmlspecialchars(fecha_dmy($f['fecha'])) ?></td>
                        <td><?= htmlspecialchars($f['proveedor']) ?></td>
                        <td><?= htmlspecialchars($f['numero_factura'] ?? '') ?></td>
                        <td class="total"><?= clp($f['total']) ?></td>
                        <td><?= htmlspecialchars($f['razon_social'] ?? '') ?></td>
                        <?php if (count($negocios) > 1): ?>
                            <td><?= htmlspecialchars($f['negocio_nombre']) ?></td>
                        <?php endif; ?>
                        <td><span class="estado"><span class="punto <?= $color ?>"></span><?= $txtEstado ?></span></td>
                        <td>
                            <?php if ($f['ruta_pdf']): ?>
                                <a href="ver_pdf.php?id=<?= (int)$f['id'] ?>" target="_blank">Ver</a>
                            <?php else: ?>
                                <span style="color:#aaa">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
</body>
</html>
