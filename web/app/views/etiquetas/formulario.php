<?php
/**
 * Vista de app/Controllers/EtiquetasController::formulario().
 * Variables disponibles: $usuario, $negocios, $negocioId, $totalP, $estado,
 * $preNombre, $prePrecio, $FILAS.
 *
 * El buscador autocompleta desde la Base de Datos de Productos (catálogo de
 * Eleventa): se escribe o escanea el código y se llena la primera fila libre.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Gestor de Etiquetas'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Gestor de Etiquetas</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
        </div>

        <form method="post" action="etiquetas_imprimir.php" target="_blank" class="panel et-panel">

            <!-- Buscador del catálogo + selector de negocio -->
            <?php if (count($negocios) > 1 || $totalP > 0): ?>
            <div class="et-buscar-fila">
                <?php if ($totalP > 0): ?>
                    <div class="et-buscar" data-negocio="<?= $negocioId ?>">
                        <span class="et-buscar-ic"><?= icono('lupa') ?></span>
                        <input type="text" id="etBuscar" placeholder="Buscar producto por nombre o código…" autocomplete="off">
                        <div class="et-resultados" id="etResultados" hidden></div>
                    </div>
                <?php else: ?>
                    <p class="et-hint et-hint-grande">Este negocio no tiene catálogo cargado.
                       <a href="herramienta_productos.php?negocio=<?= $negocioId ?>">Cargar productos →</a></p>
                <?php endif; ?>
                <?php if (count($negocios) > 1): ?>
                    <select class="et-negocio-sel" onchange="location.href='herramienta_etiquetas.php?negocio='+this.value">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= (int)$n['id'] === $negocioId ? 'selected' : '' ?>>
                                <?= h($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <?php if ($totalP > 0 && ($estado['nivel'] === 'naranja' || $estado['nivel'] === 'rojo')): ?>
                <div class="aviso-flash flash-<?= $estado['nivel'] === 'rojo' ? 'error' : 'aviso' ?>">
                    <?= h($estado['texto']) ?>. <a href="herramienta_productos.php?negocio=<?= $negocioId ?>">Actualizar →</a>
                </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- 14 etiquetas en dos columnas (espejo de la hoja A4) -->
            <div class="et-grid">
                <?php for ($i = 1; $i <= $FILAS; $i++): ?>
                    <?php $vN = $i === 1 ? $preNombre : ''; $vP = $i === 1 ? $prePrecio : ''; ?>
                    <div class="et-fila<?= $i === 1 && $preNombre !== '' ? ' et-fila-nueva' : '' ?>">
                        <span class="et-n"><?= sprintf('%02d', $i) ?></span>
                        <input type="text" name="nombre[]" class="et-nombre" maxlength="120"
                               placeholder="Producto" autocomplete="off" value="<?= h($vN) ?>">
                        <span class="et-precio-wrap"><span class="et-peso">$</span><input type="text"
                               name="precio[]" class="et-precio" inputmode="numeric" placeholder="0"
                               autocomplete="off" value="<?= h($vP) ?>"></span>
                    </div>
                <?php endfor; ?>
            </div>

            <div class="form-acciones et-acciones">
                <button class="btn" type="submit">Generar etiquetas</button>
                <button class="btn gris" type="reset">Limpiar</button>
            </div>
        </form>
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

        // Coloca un producto en la primera fila vacía.
        function agregar(p) {
          var filas = document.querySelectorAll('.et-fila');
          for (var i = 0; i < filas.length; i++) {
            var nom = filas[i].querySelector('.et-nombre');
            var pre = filas[i].querySelector('.et-precio');
            if (!nom.value.trim() && !pre.value.trim()) {
              nom.value = p.nombre;
              pre.value = p.precio_venta ? Math.round(p.precio_venta) : '';
              filas[i].classList.add('et-fila-nueva');
              setTimeout(function (f) { return function () { f.classList.remove('et-fila-nueva'); }; }(filas[i]), 900);
              return;
            }
          }
          alert('La hoja ya tiene las 14 etiquetas llenas.');
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
