<?php
/**
 * Historial de cambios del catálogo: quién creó, editó o eliminó cada producto,
 * con el antes y el después de cada campo.
 *
 * Solo admin: es una pantalla de supervisión. Las vendedoras pueden editar el
 * catálogo (decisión del usuario), y esta página es la que permite revisarlo.
 *
 * Lee de la tabla `auditoria` (módulo 'productos'); ver POS_DISENO.md 7.1.1.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('usuarios');   // 'usuarios' = solo administrador
$pdo = obtener_pdo();

// Etiquetas legibles de cada campo auditado y cómo mostrar su valor.
const CAMPOS_PRODUCTO = [
    'codigo'         => ['Código',            'texto'],
    'nombre'         => ['Nombre',            'texto'],
    'precio_costo'   => ['Precio de costo',   'money'],
    'precio_venta'   => ['Precio de venta',   'money'],
    'precio_mayoreo' => ['Precio mayoreo',    'money'],
    'departamento'   => ['Departamento',      'texto'],
    'tipo_venta'     => ['Tipo de venta',     'texto'],
    'activo'         => ['Disponible',        'bool'],
    'afecto_iva'     => ['Afecto a IVA',      'bool'],
];

/** Formatea un valor del historial según su tipo. */
function valor_historial($v, string $tipo): string
{
    if ($v === null || $v === '') return '—';
    if ($tipo === 'money') return clp($v) ?: '$0';
    if ($tipo === 'bool')  return ((int)$v === 1) ? 'Sí' : 'No';
    return (string)$v;
}

// ---------- Filtros ----------
$q       = trim($_GET['q'] ?? '');
$accion  = trim($_GET['accion'] ?? '');
$usuId   = isset($_GET['usuario']) ? (int)$_GET['usuario'] : 0;
$desde   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : '';
$hasta   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : '';
if ($desde && $hasta && $desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }

$pagina  = max(1, (int)($_GET['p'] ?? 1));
$porPag  = 40;

$where = ["modulo = 'productos'"];
$args  = [];
if ($q !== '') {
    $where[] = "(descripcion LIKE ? OR detalle LIKE ?)";
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
if (in_array($accion, ['crear_producto', 'editar_producto', 'eliminar_producto'], true)) {
    $where[] = "accion = ?";
    $args[] = $accion;
}
if ($usuId) { $where[] = "usuario_id = ?"; $args[] = $usuId; }
if ($desde) { $where[] = "creado_en >= ?"; $args[] = $desde . ' 00:00:00'; }
if ($hasta) { $where[] = "creado_en <= ?"; $args[] = $hasta . ' 23:59:59'; }
$sqlWhere = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM auditoria WHERE $sqlWhere");
$st->execute($args);
$total = (int)$st->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPag));
$pagina = min($pagina, $paginas);

$st = $pdo->prepare(
    "SELECT * FROM auditoria WHERE $sqlWhere
      ORDER BY id DESC LIMIT $porPag OFFSET " . (($pagina - 1) * $porPag)
);
$st->execute($args);
$filas = $st->fetchAll();

// Quiénes han tocado el catálogo (para el filtro por persona).
$autores = $pdo->query(
    "SELECT DISTINCT a.usuario_id, a.usuario_nombre
       FROM auditoria a
      WHERE a.modulo = 'productos' AND a.usuario_id IS NOT NULL
      ORDER BY a.usuario_nombre"
)->fetchAll();

/** Arma la URL de la página N conservando los filtros. */
function url_pagina(int $n): string
{
    $p = $_GET; $p['p'] = $n;
    return 'historial_productos.php?' . http_build_query($p);
}

$ETIQUETAS = [
    'crear_producto'    => ['Creó',     'b-activa'],
    'editar_producto'   => ['Editó',    'b-reemplazada'],
    'eliminar_producto' => ['Eliminó',  'b-eliminada'],
];
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Historial del catálogo'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('reloj') ?></span>
                <h2>Historial del catálogo</h2>
            </div>
            <a class="btn gris" href="herramienta_productos.php">Volver</a>
        </div>

        <div class="panel">
            <p class="texto-ayuda">
                Todo cambio en el catálogo queda registrado acá: quién lo hizo, cuándo, y
                qué cambió exactamente. Como el catálogo es <strong>compartido por los dos
                negocios</strong>, un cambio de precio afecta a ambos.
            </p>
            <form method="get" class="filtros">
                <div class="campo" style="flex:1 1 220px">
                    <label>Buscar</label>
                    <input type="text" name="q" value="<?= h($q) ?>" autocomplete="off"
                           placeholder="Nombre o código del producto" style="width:100%">
                </div>
                <div class="campo">
                    <label>Acción</label>
                    <select name="accion">
                        <option value="">Todas</option>
                        <option value="crear_producto"    <?= $accion === 'crear_producto' ? 'selected' : '' ?>>Creados</option>
                        <option value="editar_producto"   <?= $accion === 'editar_producto' ? 'selected' : '' ?>>Editados</option>
                        <option value="eliminar_producto" <?= $accion === 'eliminar_producto' ? 'selected' : '' ?>>Eliminados</option>
                    </select>
                </div>
                <div class="campo">
                    <label>Persona</label>
                    <select name="usuario">
                        <option value="0">Todas</option>
                        <?php foreach ($autores as $a): ?>
                            <option value="<?= (int)$a['usuario_id'] ?>"
                                <?= $usuId === (int)$a['usuario_id'] ? 'selected' : '' ?>>
                                <?= h($a['usuario_nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= h($desde) ?>">
                </div>
                <div class="campo">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= h($hasta) ?>">
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit">Filtrar</button>
                </div>
                <?php if ($q || $accion || $usuId || $desde || $hasta): ?>
                <div class="campo">
                    <label>&nbsp;</label>
                    <a class="btn gris" href="historial_productos.php">Limpiar</a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="panel">
            <h2 class="panel-titulo">
                <?= $total ?> cambio<?= $total === 1 ? '' : 's' ?>
                <?php if ($paginas > 1): ?>
                    <span class="texto-tenue">· página <?= $pagina ?> de <?= $paginas ?></span>
                <?php endif; ?>
            </h2>

            <?php if (!$filas): ?>
                <p class="vacio">
                    <?= $total === 0 && !$q && !$accion && !$usuId && !$desde && !$hasta
                        ? 'Todavía no hay cambios registrados en el catálogo.'
                        : 'No hay cambios que coincidan con el filtro.' ?>
                </p>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table class="tabla-historial">
                        <thead><tr>
                            <th>Cuándo</th><th>Quién</th><th>Acción</th><th>Producto</th><th>Qué cambió</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($filas as $f): ?>
                            <?php
                            $et = $ETIQUETAS[$f['accion']] ?? [$f['accion'], 'b-reemplazada'];
                            $cambios = $f['accion'] === 'editar_producto' && $f['detalle']
                                     ? json_decode($f['detalle'], true) : null;
                            ?>
                            <tr>
                                <td class="hist-fecha">
                                    <span class="hf-dia"><?= h(date('d-m-Y', strtotime($f['creado_en']))) ?></span>
                                    <span class="hf-hora"><?= h(date('H:i', strtotime($f['creado_en']))) ?></span>
                                </td>
                                <td>
                                    <?= h($f['usuario_nombre'] ?: '—') ?>
                                    <span class="texto-tenue"><?= h(nombre_rol((string)$f['rol'])) ?></span>
                                </td>
                                <td><span class="badge-carga <?= $et[1] ?>"><?= $et[0] ?></span></td>
                                <td><?= h($f['descripcion']) ?></td>
                                <td>
                                    <?php if (is_array($cambios) && $cambios): ?>
                                        <ul class="lista-cambios">
                                        <?php foreach ($cambios as $campo => $c): ?>
                                            <?php
                                            [$lbl, $tipo] = CAMPOS_PRODUCTO[$campo] ?? [$campo, 'texto'];
                                            $esPrecio = $tipo === 'money';
                                            ?>
                                            <li>
                                                <span class="lc-campo"><?= h($lbl) ?>:</span>
                                                <span class="lc-antes"><?= h(valor_historial($c['antes'] ?? null, $tipo)) ?></span>
                                                <span class="lc-flecha">→</span>
                                                <span class="lc-despues <?= $esPrecio ? 'lc-precio' : '' ?>">
                                                    <?= h(valor_historial($c['despues'] ?? null, $tipo)) ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php elseif ($f['accion'] === 'crear_producto'): ?>
                                        <span class="texto-tenue">Producto nuevo</span>
                                    <?php elseif ($f['accion'] === 'eliminar_producto'): ?>
                                        <span class="texto-tenue">Salió del catálogo</span>
                                    <?php else: ?>
                                        <span class="texto-tenue">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($paginas > 1): ?>
                    <div class="paginacion">
                        <?php if ($pagina > 1): ?>
                            <a class="btn gris sm" href="<?= h(url_pagina($pagina - 1)) ?>">← Anteriores</a>
                        <?php endif; ?>
                        <span class="texto-tenue">Página <?= $pagina ?> de <?= $paginas ?></span>
                        <?php if ($pagina < $paginas): ?>
                            <a class="btn gris sm" href="<?= h(url_pagina($pagina + 1)) ?>">Siguientes →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php pie_dashboard(); ?>
