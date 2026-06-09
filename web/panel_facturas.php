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
    $where[] = 'f.proveedor = ?';   // viene de un combo: coincidencia exacta
    $params[] = $fProveedor;
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

// Lista de proveedores existentes (para el combo), limitada a los negocios
// que el usuario puede ver.
$proveedores = [];
if (!empty($idsVisibles)) {
    $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
    $stp = $pdo->prepare(
        "SELECT DISTINCT proveedor FROM facturas
         WHERE negocio_id IN ($ph) AND eliminada_en IS NULL
           AND proveedor IS NOT NULL AND proveedor <> ''
         ORDER BY proveedor"
    );
    $stp->execute($idsVisibles);
    $proveedores = $stp->fetchAll(PDO::FETCH_COLUMN);
}

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
<?php cabecera_dashboard($usuario, 'facturas', 'Facturas'); ?>

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
            <?php $fechasActivas = ($fDesde !== '' || $fHasta !== ''); ?>
            <form class="filtros<?= $fechasActivas ? ' mostrar-fechas' : '' ?>" method="get">
                <div class="campo campo-busqueda">
                    <label>Búsqueda</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($fTexto) ?>"
                           placeholder="N° Factura, proveedor, razón social" autofocus>
                    <!-- Interruptor de fechas: queda justo debajo de la búsqueda. -->
                    <label class="toggle-fechas">
                        <input type="checkbox" id="toggleFechas" <?= $fechasActivas ? 'checked' : '' ?>>
                        Filtrar por fechas
                    </label>
                </div>
                <?php if (count($negocios) > 1): ?>
                <div class="campo campo-medio">
                    <label>Negocio</label>
                    <!-- Al elegir un negocio se busca solo, sin tocar "Buscar". -->
                    <select name="negocio" onchange="this.form.submit()">
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
                <div class="campo campo-medio">
                    <label>Proveedor</label>
                    <!-- Combo con los proveedores existentes; busca solo al elegir. -->
                    <select name="proveedor" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($proveedores as $prov): ?>
                            <option value="<?= htmlspecialchars($prov) ?>"
                                <?= $fProveedor === $prov ? 'selected' : '' ?>>
                                <?= htmlspecialchars($prov) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Desde/Hasta: ocultos por defecto; aparecen al marcar el interruptor.
                     En móvil quedan ocultos (junto con el interruptor) por CSS. -->
                <div class="campo campo-fecha">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= htmlspecialchars($fDesde) ?>">
                </div>
                <div class="campo campo-fecha">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= htmlspecialchars($fHasta) ?>">
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Buscar</button>
                    <a class="btn gris" href="panel_facturas.php">Limpiar</a>
                </div>
            </form>
        </div>

        <div class="resumen"><?= count($facturas) ?> factura(s)</div>

        <div class="tabla-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="nowrap">Fecha</th>
                        <th>Proveedor</th>
                        <th>N° Factura</th>
                        <th class="total">Total</th>
                        <th class="col-ocultar-movil">Razón Social</th>
                        <?php if (count($negocios) > 1): ?><th class="col-ocultar-movil">Negocio</th><?php endif; ?>
                        <th class="celda-estado">Estado</th>
                        <th class="col-ocultar-movil">PDF</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$facturas): ?>
                    <tr><td class="vacio" colspan="8">No hay facturas con esos filtros.</td></tr>
                <?php else: foreach ($facturas as $f):
                    [$color, $txtEstado] = estado_factura($f); ?>
                    <tr class="fila-click" onclick="location.href='factura.php?id=<?= (int)$f['id'] ?>'">
                        <td class="nowrap"><?= htmlspecialchars(fecha_dmy($f['fecha'])) ?></td>
                        <td><?= htmlspecialchars($f['proveedor']) ?></td>
                        <td><?= htmlspecialchars($f['numero_factura'] ?? '') ?></td>
                        <td class="total"><?= clp($f['total']) ?></td>
                        <td class="col-ocultar-movil"><?= htmlspecialchars($f['razon_social'] ?? '') ?></td>
                        <?php if (count($negocios) > 1): ?>
                            <td class="col-ocultar-movil"><?= htmlspecialchars($f['negocio_nombre']) ?></td>
                        <?php endif; ?>
                        <td class="celda-estado"><span class="estado"><span class="punto <?= $color ?>"></span><span class="estado-txt"><?= $txtEstado ?></span></span></td>
                        <td class="col-ocultar-movil">
                            <?php if ($f['ruta_pdf']): ?>
                                <a href="ver_pdf.php?id=<?= (int)$f['id'] ?>" target="_blank"
                                   onclick="event.stopPropagation()">Ver</a>
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
    <script>
      // El interruptor "Filtrar por fechas" muestra/oculta los campos Desde/Hasta.
      (function () {
        var chk = document.getElementById('toggleFechas');
        if (!chk) return;
        var form = chk.closest('form');
        chk.addEventListener('change', function () {
          form.classList.toggle('mostrar-fechas', chk.checked);
          if (!chk.checked) {  // al desactivar, limpiar las fechas elegidas
            form.querySelector('input[name="desde"]').value = '';
            form.querySelector('input[name="hasta"]').value = '';
          }
        });
      })();
    </script>
    <?php pie_dashboard(); ?>
