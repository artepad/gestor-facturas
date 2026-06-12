<?php
/**
 * Herramienta "Gestor de Etiquetas": crea etiquetas de precio para los
 * productos. El usuario llena nombre + precio de cada producto y al generar se
 * abre una hoja lista para imprimir o guardar como PDF (ver etiquetas_imprimir.php).
 *
 * El buscador autocompleta nombre y precio desde la Base de Datos de Productos
 * (catálogo de Eleventa): se escribe o escanea el código de barras y se llena la
 * siguiente fila libre. El catálogo se administra en herramienta_productos.php.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/productos.php';

$usuario  = exigir_permiso('herramientas');
$pdo      = obtener_pdo();
$negocios = negocios_visibles($usuario);

// Negocio del que se busca el catálogo. Por GET para poder cambiarlo.
$negocioId = (int)($_GET['negocio'] ?? 0);
if ($negocioId && !puede_ver_negocio($usuario, $negocioId)) $negocioId = 0;
if (!$negocioId && $negocios) $negocioId = (int)$negocios[0]['id'];

// Estado del catálogo (para avisar si está desactualizado).
$ultima = $negocioId ? ultima_carga($pdo, $negocioId) : null;
$totalP = $negocioId ? contar_productos($pdo, $negocioId) : 0;
$estado = estado_actualizacion($ultima['cargado_en'] ?? null);

$FILAS = 14;   // una hoja A4 = 14 etiquetas (2 columnas x 7 filas)

// Precarga de la primera fila al venir desde "Crear etiqueta" del catálogo.
$preNombre = trim((string)($_GET['nombre'] ?? ''));
$prePrecio = preg_replace('/[^\d]/', '', (string)($_GET['precio'] ?? ''));
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Gestor de Etiquetas'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Gestor de Etiquetas</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
        </div>

        <!-- Buscador desde la base de datos de productos -->
        <div class="panel">
            <div class="et-buscar-cab">
                <h2 class="panel-titulo">Buscar en la base de datos</h2>
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
            </div>

            <?php if ($totalP === 0): ?>
                <p class="texto-ayuda">Este negocio aún no tiene catálogo cargado.
                   <a href="herramienta_productos.php?negocio=<?= $negocioId ?>">Cargar la base de datos de productos →</a></p>
            <?php else: ?>
                <?php if ($estado['nivel'] === 'naranja' || $estado['nivel'] === 'rojo'): ?>
                    <div class="aviso-flash flash-<?= $estado['nivel'] === 'rojo' ? 'error' : 'aviso' ?>" style="margin-bottom:12px;">
                        <?= h($estado['texto']) ?>.
                        <a href="herramienta_productos.php?negocio=<?= $negocioId ?>">Actualizar →</a>
                    </div>
                <?php endif; ?>
                <p class="texto-ayuda">Escanea el código de barras o escribe el nombre y elige el producto:
                   se agrega a la primera fila libre. <strong>Enter</strong> agrega el primer resultado
                   (ideal para escanear varios seguidos).</p>
                <div class="et-buscar" data-negocio="<?= $negocioId ?>">
                    <span class="et-buscar-ic"><?= icono('lupa') ?></span>
                    <input type="text" id="etBuscar" placeholder="Código de barras o nombre del producto…"
                           autocomplete="off">
                    <div class="et-resultados" id="etResultados" hidden></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <p class="texto-ayuda">Escribe el nombre y el precio de cada producto. Al generar se abre una
                hoja lista para <strong>imprimir o guardar como PDF</strong> (hasta 14 etiquetas por hoja).
                Las filas vacías se omiten.</p>

            <form method="post" action="etiquetas_imprimir.php" target="_blank">
                <table class="tabla-etiquetas">
                    <thead><tr>
                        <th class="et-col-num">#</th>
                        <th>Producto</th>
                        <th class="col-num">Precio</th>
                    </tr></thead>
                    <tbody>
                    <?php for ($i = 1; $i <= $FILAS; $i++): ?>
                        <?php $vN = $i === 1 ? $preNombre : ''; $vP = $i === 1 ? $prePrecio : ''; ?>
                        <tr<?= $i === 1 && $preNombre !== '' ? ' class="et-fila-nueva"' : '' ?>>
                            <td class="et-num"><?= sprintf('%02d', $i) ?></td>
                            <td><input type="text" name="nombre[]" class="et-nombre" maxlength="120"
                                       placeholder="Nombre del producto" autocomplete="off" value="<?= h($vN) ?>"></td>
                            <td class="col-num">
                                <span class="et-peso">$</span><input type="text" name="precio[]"
                                    class="et-precio" inputmode="numeric" placeholder="0" autocomplete="off" value="<?= h($vP) ?>">
                            </td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>

                <div class="form-acciones">
                    <button class="btn" type="submit">Generar etiquetas</button>
                    <button class="btn gris" type="reset">Limpiar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
      (function () {
        var box = document.querySelector('.et-buscar');
        if (!box) return;                          // sin catálogo no hay buscador
        var negocio = box.dataset.negocio;
        var input = document.getElementById('etBuscar');
        var caja  = document.getElementById('etResultados');
        var ultimos = [];
        var timer = null;

        function fmt(n) { return Number(n || 0).toLocaleString('es-CL'); }

        // Coloca un producto en la primera fila vacía (o reemplaza la última si está llena).
        function agregar(p) {
          var nombres = document.querySelectorAll('.et-nombre');
          var precios = document.querySelectorAll('.et-precio');
          var i = 0;
          for (; i < nombres.length; i++) { if (!nombres[i].value.trim() && !precios[i].value.trim()) break; }
          if (i >= nombres.length) { alert('La hoja ya tiene las 14 etiquetas llenas.'); return; }
          nombres[i].value = p.nombre;
          precios[i].value = p.precio_venta ? Math.round(p.precio_venta) : '';
          nombres[i].closest('tr').classList.add('et-fila-nueva');
          setTimeout(function () { nombres[i].closest('tr').classList.remove('et-fila-nueva'); }, 900);
        }

        function pintar(lista) {
          ultimos = lista;
          caja.innerHTML = '';
          if (!lista.length) { caja.innerHTML = '<div class="et-res-vacio">Sin coincidencias</div>'; caja.hidden = false; return; }
          lista.forEach(function (p) {
            var d = document.createElement('div');
            d.className = 'et-res';
            d.innerHTML = '<span class="er-cod"></span><span class="er-nom"></span><span class="er-precio"></span>';
            d.querySelector('.er-cod').textContent = p.codigo;
            d.querySelector('.er-nom').textContent = p.nombre;
            d.querySelector('.er-precio').textContent = p.precio_venta ? '$' + fmt(Math.round(p.precio_venta)) : '—';
            d.addEventListener('click', function () { agregar(p); input.value = ''; caja.hidden = true; input.focus(); });
            caja.appendChild(d);
          });
          caja.hidden = false;
        }

        function buscar() {
          var q = input.value.trim();
          if (q.length < 2) { caja.hidden = true; ultimos = []; return; }
          fetch('productos_buscar.php?negocio_id=' + encodeURIComponent(negocio) + '&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (d) { pintar(d.productos || []); })
            .catch(function () { caja.hidden = true; });
        }

        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(buscar, 180); });
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            if (ultimos.length) { agregar(ultimos[0]); input.value = ''; caja.hidden = true; ultimos = []; }
          } else if (e.key === 'Escape') { caja.hidden = true; }
        });
        document.addEventListener('click', function (e) { if (!box.contains(e.target)) caja.hidden = true; });
      })();
    </script>
    <?php pie_dashboard(); ?>
