<?php
/**
 * Frutas y Verduras: lista de precios compartida para ambos negocios. La compra
 * la hace una persona para todos, así que la lista es única (sin negocio_id).
 * Editan admin y vendedores; todos consultan. Pensada para mirarse desde el
 * celular: tarjetas grandes con nombre, precio + unidad, fecha y observación.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('precios');
$pdo = obtener_pdo();
$puedeEditar = puede($usuario, 'precios');   // admin y vendedor

$q = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM precios_fv WHERE activo = 1";
$par = [];
if ($q !== '') { $sql .= " AND nombre LIKE ?"; $par[] = "%$q%"; }
$sql .= " ORDER BY nombre";
$st = $pdo->prepare($sql);
$st->execute($par);
$productos = $st->fetchAll();

// Última actualización de toda la lista (para mostrarla arriba)
$ultima = $pdo->query("SELECT MAX(actualizado_en) FROM precios_fv WHERE activo = 1")->fetchColumn();
?>
<?php cabecera_dashboard($usuario, 'precios', 'Frutas y verduras'); ?>
    <div class="contenido">
        <div class="cab-modulo modulo-verde">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('precios') ?></span>
                <h2>Frutas y verduras</h2>
            </div>
            <?php if ($puedeEditar): ?>
            <a class="btn" href="precio_form.php">+ Nuevo producto</a>
            <?php endif; ?>
        </div>

        <div class="panel">
            <form class="filtros" method="get">
                <div class="campo campo-busqueda">
                    <label>Buscar producto</label>
                    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Ej: tomate, palta…" autofocus>
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Buscar</button>
                    <?php if ($q !== ''): ?><a class="btn gris" href="precios.php">Limpiar</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="resumen">
            <span><?= count($productos) ?> producto(s)</span>
            <?php if ($ultima): ?>
            <span class="badge-negocio">Actualizado: <strong><?= h(date('d-m-Y', strtotime($ultima))) ?></strong></span>
            <?php endif; ?>
        </div>

        <?php if (!$productos): ?>
            <div class="panel"><p class="vacio" style="margin:0">
                <?= $q !== '' ? 'No se encontraron productos con ese nombre.'
                              : 'Aún no hay precios cargados.' . ($puedeEditar ? ' Agrega el primero con "+ Nuevo producto".' : '') ?>
            </p></div>
        <?php else: ?>
        <div class="precios-grid">
            <?php foreach ($productos as $p): ?>
            <div class="precio-card">
                <div class="precio-cab">
                    <span class="precio-nombre"><?= h($p['nombre']) ?></span>
                    <?php if ($puedeEditar): ?>
                    <a class="btn-icono" title="Editar" aria-label="Editar"
                       href="precio_form.php?id=<?= (int)$p['id'] ?>"><?= icono('lapiz') ?></a>
                    <?php endif; ?>
                </div>
                <div class="precio-valor">
                    <?= clp($p['precio']) ?> <span class="precio-unidad">/ <?= h($p['unidad']) ?></span>
                </div>
                <?php if (!empty($p['observacion'])): ?>
                <div class="precio-obs"><?= h($p['observacion']) ?></div>
                <?php endif; ?>
                <div class="precio-fecha">Actualizado: <?= h(date('d-m-Y', strtotime($p['actualizado_en']))) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
