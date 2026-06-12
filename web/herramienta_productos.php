<?php
/**
 * Herramienta "Base de Datos de Productos": administra el catálogo de productos
 * de cada negocio, que alimenta la búsqueda del Gestor de Etiquetas. El usuario
 * sube el Excel exportado desde Eleventa; el sistema lo procesa y reemplaza el
 * catálogo de ese negocio (de forma atómica, ver lib/productos.php).
 *
 * Muestra la fecha de última actualización con aviso si está vieja (naranja > 1
 * semana, rojo > 2 semanas) e incluye salvaguardas contra el borrado accidental.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/productos.php';

$usuario  = exigir_permiso('herramientas');
$pdo      = obtener_pdo();
$negocios = negocios_visibles($usuario);

// Negocio seleccionado: del POST (al subir) o del GET (al cambiar en el selector).
$negocioId = (int)($_POST['negocio_id'] ?? $_GET['negocio'] ?? 0);
if ($negocioId && !puede_ver_negocio($usuario, $negocioId)) {
    $negocioId = 0;   // no puede ver ese negocio: lo ignoramos
}
if (!$negocioId && $negocios) {
    $negocioId = (int)$negocios[0]['id'];   // primero de la lista por defecto
}

$mensaje = null;   // ['tipo' => 'ok'|'error', 'texto' => ...]

// ---- Procesar acciones (subir Excel / vaciar catálogo) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $negocioId) {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'cargar') {
        try {
            $f = $_FILES['archivo'] ?? null;
            if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Elige un archivo Excel (.xlsx) para subir.');
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('El archivo no se subió correctamente (¿muy grande?). Intenta de nuevo.');
            }
            if (!is_uploaded_file($f['tmp_name'])) {
                throw new RuntimeException('Archivo inválido.');
            }
            if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('El archivo debe ser un Excel .xlsx (exportado desde Eleventa).');
            }
            if ($f['size'] > 8 * 1024 * 1024) {
                throw new RuntimeException('El archivo es demasiado grande (máximo 8 MB).');
            }

            $parse = parsear_excel_productos($f['tmp_name']);
            reemplazar_catalogo($pdo, $negocioId, $parse, [
                'archivo'    => $f['name'],
                'usuario_id' => $usuario['id'],
            ]);
            $omit = $parse['omitidos'] > 0 ? " · {$parse['omitidos']} fila(s) sin código/nombre omitida(s)" : '';
            $mensaje = ['tipo' => 'ok',
                        'texto' => "Base de datos actualizada: {$parse['total']} productos cargados$omit."];
        } catch (Throwable $e) {
            $mensaje = ['tipo' => 'error', 'texto' => $e->getMessage()];
        }
    } elseif ($accion === 'vaciar') {
        if (trim($_POST['confirmar'] ?? '') !== 'ELIMINAR') {
            $mensaje = ['tipo' => 'error', 'texto' => 'Para vaciar el catálogo debes escribir ELIMINAR en mayúsculas.'];
        } else {
            vaciar_catalogo($pdo, $negocioId);
            $mensaje = ['tipo' => 'ok',
                        'texto' => 'La base de datos fue eliminada. Ahora no hay productos cargados; '
                                 . 'sube un Excel cuando quieras crear el catálogo de nuevo.'];
        }
    }
}

// ---- Datos para mostrar ----
$ultima  = $negocioId ? ultima_carga($pdo, $negocioId) : null;
$totalP  = $negocioId ? contar_productos($pdo, $negocioId) : 0;

// El estado depende de si HAY productos cargados ahora mismo: si el catálogo se
// vació, no decimos "Actualizada hoy" (la fecha de carga existe pero no hay base).
$estado = $totalP > 0
    ? estado_actualizacion($ultima['cargado_en'] ?? null)
    : ['nivel' => 'vacio', 'dias' => null, 'texto' => 'No hay una base de datos disponible'];

// Carga "activa": la que estampó los productos que están cargados ahora. Sirve
// para marcar cada fila del historial como Activa / Reemplazada / Eliminada.
$cargaActiva = null;
if ($totalP > 0) {
    $st = $pdo->prepare("SELECT carga_id FROM productos WHERE negocio_id = ? LIMIT 1");
    $st->execute([$negocioId]);
    $cargaActiva = (int)$st->fetchColumn();
}

// Historial de cargas (últimas 8).
$historial = [];
if ($negocioId) {
    $st = $pdo->prepare(
        "SELECT c.*, u.nombre AS usuario_nombre
         FROM producto_cargas c LEFT JOIN usuarios u ON u.id = c.cargado_por
         WHERE c.negocio_id = ? ORDER BY c.cargado_en DESC, c.id DESC LIMIT 8"
    );
    $st->execute([$negocioId]);
    $historial = $st->fetchAll();
}

$nombreNegocio = '';
foreach ($negocios as $n) if ((int)$n['id'] === $negocioId) $nombreNegocio = $n['nombre'];
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Base de Datos de Productos'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Base de Datos de Productos</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
        </div>

        <?php if (!$negocios): ?>
            <div class="panel"><p class="texto-ayuda">No tienes negocios asignados. Pídele a un administrador que te asigne uno.</p></div>
        <?php else: ?>

        <?php if ($mensaje): ?>
            <div class="aviso-flash <?= $mensaje['tipo'] === 'ok' ? 'flash-ok' : 'flash-error' ?>">
                <?= h($mensaje['texto']) ?>
            </div>
        <?php endif; ?>

        <!-- Selector de negocio -->
        <?php if (count($negocios) > 1): ?>
        <form method="get" class="selector-negocio">
            <label for="negocio">Negocio:</label>
            <select name="negocio" id="negocio" onchange="this.form.submit()">
                <?php foreach ($negocios as $n): ?>
                    <option value="<?= (int)$n['id'] ?>" <?= (int)$n['id'] === $negocioId ? 'selected' : '' ?>>
                        <?= h($n['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <!-- Estado de la base de datos -->
        <div class="panel">
            <div class="bd-estado bd-<?= $estado['nivel'] ?>">
                <span class="bd-punto"></span>
                <div class="bd-estado-txt">
                    <strong><?= h($estado['texto']) ?></strong>
                    <span class="bd-sub">
                        <?php if ($totalP > 0): ?>
                            <?= (int)$totalP ?> productos ·
                            última carga el <?= h(date('d-m-Y H:i', strtotime($ultima['cargado_en']))) ?>
                            <?php if (!empty($ultima['usuario_nombre'])): ?> por <?= h($ultima['usuario_nombre']) ?><?php endif; ?>
                        <?php else: ?>
                            Sube un Excel de Eleventa para crear el catálogo de <?= h($nombreNegocio) ?>.
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Cargar nuevo Excel -->
        <div class="panel">
            <h2 class="panel-titulo">Cargar / actualizar base de datos</h2>
            <p class="texto-ayuda">Sube el archivo Excel (<strong>.xlsx</strong>) exportado desde Eleventa.
                Reemplaza por completo el catálogo de <strong><?= h($nombreNegocio) ?></strong>. Si el
                archivo no es válido, el catálogo actual <strong>no se borra</strong>.</p>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="cargar">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <div class="bd-carga">
                    <input type="file" name="archivo" id="archivo" accept=".xlsx" required>
                    <button class="btn" type="submit">Subir y actualizar</button>
                </div>
            </form>
        </div>

        <!-- Historial (oculto por defecto, en acordeón) -->
        <?php if ($historial): ?>
        <details class="panel acordeon">
            <summary class="acordeon-titulo">
                <span>Historial de cargas</span>
                <span class="acordeon-chevron"><?= icono('chevron') ?></span>
            </summary>
            <div class="tabla-wrap">
                <table>
                    <thead><tr>
                        <th>Fecha</th><th>Archivo</th><th class="col-num">Productos</th>
                        <th class="col-num">Omitidos</th><th>Cargado por</th><th>Estado</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($historial as $i => $c): ?>
                        <?php
                        // Activa = la carga cuyos productos están cargados ahora.
                        // Eliminada = la más reciente cuando el catálogo está vacío (se vació).
                        // Reemplazada = cualquier carga anterior superada por otra.
                        if ($cargaActiva !== null && (int)$c['id'] === $cargaActiva) {
                            $badge = ['activa', 'Activa'];
                        } elseif ($cargaActiva === null && $i === 0) {
                            $badge = ['eliminada', 'Eliminada'];
                        } else {
                            $badge = ['reemplazada', 'Reemplazada'];
                        }
                        ?>
                        <tr>
                            <td><?= h(date('d-m-Y H:i', strtotime($c['cargado_en']))) ?></td>
                            <td><?= h($c['archivo_nombre'] ?: '—') ?></td>
                            <td class="col-num"><?= (int)$c['total_productos'] ?></td>
                            <td class="col-num"><?= (int)$c['total_omitidos'] ?></td>
                            <td><?= h($c['usuario_nombre'] ?: '—') ?></td>
                            <td><span class="badge-carga b-<?= $badge[0] ?>"><?= $badge[1] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <!-- Zona de peligro -->
        <?php if ($totalP > 0): ?>
        <div class="panel zona-peligro">
            <h2 class="panel-titulo">Zona de peligro</h2>
            <p class="texto-ayuda">Vaciar el catálogo de <strong><?= h($nombreNegocio) ?></strong> elimina sus
                <?= (int)$totalP ?> productos. El Gestor de Etiquetas dejará de encontrarlos hasta que vuelvas a
                cargar un Excel. Para confirmar, escribe <strong>ELIMINAR</strong>.</p>
            <form method="post" onsubmit="return confirm('¿Seguro que quieres vaciar el catálogo de este negocio?')">
                <input type="hidden" name="accion" value="vaciar">
                <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <div class="bd-carga">
                    <input type="text" name="confirmar" id="confirmar" placeholder="Escribe ELIMINAR"
                           autocomplete="off">
                    <button class="btn peligro" type="submit" id="btnVaciar" disabled>Vaciar catálogo</button>
                </div>
            </form>
        </div>
        <script>
          (function () {
            var c = document.getElementById('confirmar');
            var b = document.getElementById('btnVaciar');
            if (c && b) c.addEventListener('input', function () { b.disabled = c.value.trim() !== 'ELIMINAR'; });
          })();
        </script>
        <?php endif; ?>

        <?php endif; /* hay negocios */ ?>
    </div>
    <?php pie_dashboard(); ?>
