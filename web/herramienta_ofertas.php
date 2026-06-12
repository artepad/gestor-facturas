<?php
/**
 * Herramienta "Etiquetas de Ofertas": arma una cola de hasta 4 ofertas (de 4
 * tipos distintos) y genera una hoja A4 lista para imprimir o guardar como PDF
 * (ver ofertas_imprimir.php). La cola se maneja en el navegador (JS) y al
 * generar se envía como JSON.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('herramientas');
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Etiquetas de Ofertas'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Etiquetas de Ofertas</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
        </div>

        <div class="panel">
            <p class="texto-ayuda">Elige el tipo de oferta, completa los datos y pulsa
                <strong>Agregar a la cola</strong>. Puedes juntar hasta 4 ofertas por hoja.</p>

            <!-- Selector de tipo -->
            <div class="oferta-tipos">
                <button type="button" class="tipo-btn t-normal activo" data-tipo="normal">Oferta normal</button>
                <button type="button" class="tipo-btn t-percentage" data-tipo="percentage">Descuento %</button>
                <button type="button" class="tipo-btn t-quantity" data-tipo="quantity">Por cantidad</button>
                <button type="button" class="tipo-btn t-daily" data-tipo="daily">Producto del día</button>
            </div>

            <!-- Campos dinámicos por tipo -->
            <div class="oferta-form" data-tipo="normal">
                <div class="campo"><label>Producto</label><input type="text" id="n_producto" maxlength="120" autocomplete="off"></div>
                <div class="oferta-fila">
                    <div class="campo"><label>Precio antes</label><input type="text" id="n_antes" inputmode="numeric" placeholder="$0" autocomplete="off"></div>
                    <div class="campo"><label>Precio ahora</label><input type="text" id="n_ahora" inputmode="numeric" placeholder="$0" autocomplete="off"></div>
                </div>
            </div>

            <div class="oferta-form" data-tipo="percentage" hidden>
                <div class="oferta-fila">
                    <div class="campo campo-ancho"><label>Producto</label><input type="text" id="p_producto" maxlength="120" autocomplete="off"></div>
                    <div class="campo"><label>Descuento</label>
                        <select id="p_desc">
                            <?php foreach (['5%','10%','15%','20%','30%','40%','50%'] as $d): ?>
                                <option <?= $d === '10%' ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="oferta-fila">
                    <div class="campo"><label>Precio antes</label><input type="text" id="p_antes" inputmode="numeric" placeholder="$0" autocomplete="off"></div>
                    <div class="campo"><label>Precio ahora</label><input type="text" id="p_ahora" inputmode="numeric" placeholder="$0" autocomplete="off"></div>
                </div>
            </div>

            <div class="oferta-form" data-tipo="quantity" hidden>
                <div class="campo"><label>Producto</label><input type="text" id="q_producto" maxlength="120" autocomplete="off"></div>
                <div class="oferta-fila">
                    <div class="campo"><label>Cantidad</label><input type="text" id="q_cantidad" inputmode="numeric" value="3" autocomplete="off"></div>
                    <div class="campo"><label>Precio total</label><input type="text" id="q_precio" inputmode="numeric" placeholder="$0" autocomplete="off"></div>
                </div>
            </div>

            <div class="oferta-form" data-tipo="daily" hidden>
                <div class="campo"><label>Producto</label><input type="text" id="d_producto" maxlength="120" autocomplete="off"></div>
                <div class="campo campo-medio"><label>Precio</label><input type="text" id="d_precio" inputmode="numeric" placeholder="$0" autocomplete="off"></div>
            </div>

            <div class="oferta-error" id="ofertaMsg"></div>

            <div class="form-acciones">
                <button class="btn" type="button" id="btnAgregar">+ Agregar a la cola</button>
            </div>
        </div>

        <!-- Cola de ofertas -->
        <div class="panel">
            <h2 class="panel-titulo">Ofertas en cola · <span id="colaCont">0 de 4</span></h2>
            <div class="cola-ofertas" id="cola"></div>
            <div class="form-acciones">
                <button class="btn" type="button" id="btnGenerar" disabled>Generar ofertas</button>
                <button class="btn gris" type="button" id="btnLimpiar">Limpiar</button>
            </div>
        </div>
    </div>

    <form id="formGenerar" method="post" action="ofertas_imprimir.php" target="_blank">
        <input type="hidden" name="ofertas" id="ofertasJson">
    </form>

    <script>
      (function () {
        var TIPOS = {
          normal:     { etq: 'Oferta normal',    color: '#e74c3c' },
          percentage: { etq: 'Descuento %',      color: '#f39c12' },
          quantity:   { etq: 'Por cantidad',     color: '#9b59b6' },
          daily:      { etq: 'Producto del día', color: '#3498db' }
        };
        var cola = [];
        var tipoActivo = 'normal';
        var MAX = 4;

        function num(v) { var n = parseInt(String(v).replace(/[^\d]/g, ''), 10); return isNaN(n) ? 0 : n; }
        function fmt(n) { return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
        function msg(t) { document.getElementById('ofertaMsg').textContent = t || ''; }

        // --- Selector de tipo ---
        document.querySelectorAll('.tipo-btn').forEach(function (b) {
          b.addEventListener('click', function () {
            tipoActivo = b.dataset.tipo;
            document.querySelectorAll('.tipo-btn').forEach(function (x) { x.classList.toggle('activo', x === b); });
            document.querySelectorAll('.oferta-form').forEach(function (f) { f.hidden = (f.dataset.tipo !== tipoActivo); });
            msg('');
          });
        });

        function val(id) { return document.getElementById(id).value.trim(); }

        // --- Validar y construir la oferta del formulario activo ---
        function leerOferta() {
          if (tipoActivo === 'normal') {
            var prod = val('n_producto'), a = num(val('n_antes')), b = num(val('n_ahora'));
            if (!prod || !a || !b) return msg('Completa producto, precio antes y precio ahora.'), null;
            return { tipo: 'normal', producto: prod, precio_antes: a, precio_ahora: b };
          }
          if (tipoActivo === 'percentage') {
            var prod = val('p_producto'), a = num(val('p_antes')), b = num(val('p_ahora'));
            if (!prod || !a || !b) return msg('Completa producto y los dos precios.'), null;
            return { tipo: 'percentage', producto: prod, porcentaje: val('p_desc'), precio_antes: a, precio_ahora: b };
          }
          if (tipoActivo === 'quantity') {
            var prod = val('q_producto'), c = num(val('q_cantidad')), p = num(val('q_precio'));
            if (!prod || !c || !p) return msg('Completa producto, cantidad y precio total.'), null;
            return { tipo: 'quantity', producto: prod, cantidad: c, precio: p };
          }
          if (tipoActivo === 'daily') {
            var prod = val('d_producto'), p = num(val('d_precio'));
            if (!prod || !p) return msg('Completa producto y precio.'), null;
            return { tipo: 'daily', producto: prod, precio: p };
          }
          return null;
        }

        function limpiarCampos() {
          ['n_producto','n_antes','n_ahora','p_producto','p_antes','p_ahora',
           'q_producto','q_precio','d_producto','d_precio'].forEach(function (id) {
            document.getElementById(id).value = '';
          });
          document.getElementById('q_cantidad').value = '3';
          document.getElementById('p_desc').value = '10%';
        }

        function resumen(o) {
          if (o.tipo === 'normal')     return '$' + fmt(o.precio_antes) + ' → $' + fmt(o.precio_ahora);
          if (o.tipo === 'percentage') return o.porcentaje + ' · $' + fmt(o.precio_ahora);
          if (o.tipo === 'quantity')   return o.cantidad + ' x $' + fmt(o.precio);
          if (o.tipo === 'daily')      return '$' + fmt(o.precio);
          return '';
        }

        function pintarCola() {
          document.getElementById('colaCont').textContent = cola.length + ' de ' + MAX;
          var cont = document.getElementById('cola');
          cont.innerHTML = '';
          for (var i = 0; i < MAX; i++) {
            var slot = document.createElement('div');
            slot.className = 'cola-slot';
            if (i < cola.length) {
              var o = cola[i], c = TIPOS[o.tipo];
              slot.classList.add('llena');
              slot.style.borderTopColor = c.color;
              slot.innerHTML =
                '<div class="cs-top"><span class="cs-tipo" style="color:' + c.color + '">' + c.etq + '</span>' +
                '<button class="cs-x" data-i="' + i + '" title="Quitar">&times;</button></div>' +
                '<div class="cs-prod"></div><div class="cs-precio"></div>';
              slot.querySelector('.cs-prod').textContent = o.producto;
              slot.querySelector('.cs-precio').textContent = resumen(o);
            } else {
              slot.innerHTML = '<div class="cs-vacio"><span>' + (i + 1) + '</span>Disponible</div>';
            }
            cont.appendChild(slot);
          }
          cont.querySelectorAll('.cs-x').forEach(function (b) {
            b.addEventListener('click', function () { cola.splice(+b.dataset.i, 1); pintarCola(); });
          });
          document.getElementById('btnGenerar').disabled = cola.length === 0;
        }

        document.getElementById('btnAgregar').addEventListener('click', function () {
          if (cola.length >= MAX) return msg('La cola ya tiene 4 ofertas. Quita una para agregar otra.');
          var o = leerOferta();
          if (!o) return;
          cola.push(o); limpiarCampos(); msg(''); pintarCola();
        });

        document.getElementById('btnLimpiar').addEventListener('click', function () {
          cola = []; limpiarCampos(); msg(''); pintarCola();
        });

        document.getElementById('btnGenerar').addEventListener('click', function () {
          if (!cola.length) return;
          document.getElementById('ofertasJson').value = JSON.stringify(cola);
          document.getElementById('formGenerar').submit();
        });

        pintarCola();
      })();
    </script>
    <?php pie_dashboard(); ?>
