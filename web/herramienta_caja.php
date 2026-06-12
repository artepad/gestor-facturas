<?php
/**
 * Herramienta "Contador de Caja": cuenta billetes (por cantidad) y monedas
 * (las de $100/$50/$10 por peso en gramos; $500 por cantidad) y muestra el
 * total de efectivo. Todo el cálculo es en el navegador (sin guardar nada).
 *
 * Pesos unitarios de las monedas chilenas (en KILOS, como muestra la balanza):
 * $100 = 0,00757 kg · $50 = 0,007 kg · $10 = 0,0035 kg.
 * cantidad = round(peso_total / peso_unitario).
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('herramientas');

$billetes = [20000, 10000, 5000, 2000, 1000];
$colores  = [20000 => '#e8590c', 10000 => '#1565c0', 5000 => '#e63946',
             2000 => '#9b59b6', 1000 => '#27ae60'];
$monedasPeso = [100 => 0.00757, 50 => 0.007, 10 => 0.0035];   // kilos por moneda
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Contador de Caja'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Contador de Caja</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
        </div>

        <div class="caja-cols">
            <!-- BILLETES -->
            <div class="panel sec-billetes">
                <h2 class="panel-titulo">Billetes</h2>
                <table class="tabla-caja">
                    <thead><tr>
                        <th>Denominación</th><th class="col-num">Cantidad</th><th class="col-num">Subtotal</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($billetes as $d): ?>
                        <tr>
                            <td class="denom" style="color:<?= $colores[$d] ?>">$<?= number_format($d, 0, ',', '.') ?></td>
                            <td class="col-num"><input type="text" inputmode="numeric" id="b<?= $d ?>" data-denom="<?= $d ?>" class="in-bill" placeholder="0"></td>
                            <td class="col-num val" id="sb<?= $d ?>">$0</td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="fila-total">
                            <td>Total billetes</td><td></td>
                            <td class="col-num total-val" id="totalBills">$0</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- MONEDAS -->
            <div class="panel sec-monedas">
                <h2 class="panel-titulo">Monedas</h2>
                <table class="tabla-caja">
                    <thead><tr>
                        <th>Denom.</th><th class="col-num">Peso (kg)</th>
                        <th class="col-num">Cantidad</th><th class="col-num">Subtotal</th>
                    </tr></thead>
                    <tbody>
                        <tr>
                            <td class="denom">$500</td>
                            <td class="col-num sin-peso">—</td>
                            <td class="col-num"><input type="text" inputmode="numeric" id="cq500" data-denom="500" class="in-coinqty" placeholder="0"></td>
                            <td class="col-num val" id="cv500">$0</td>
                        </tr>
                    <?php foreach ($monedasPeso as $d => $w): ?>
                        <tr>
                            <td class="denom">$<?= number_format($d, 0, ',', '.') ?></td>
                            <td class="col-num"><input type="text" inputmode="decimal" id="cw<?= $d ?>" data-denom="<?= $d ?>" data-weight="<?= $w ?>" class="in-coinw" placeholder="0"></td>
                            <td class="col-num"><input type="text" inputmode="numeric" id="cq<?= $d ?>" data-denom="<?= $d ?>" data-weight="<?= $w ?>" class="in-coinqty" placeholder="0"></td>
                            <td class="col-num val" id="cv<?= $d ?>">$0</td>
                        </tr>
                    <?php endforeach; ?>
                        <!-- Fila vacía para igualar la altura con la tabla de billetes
                             (6 filas). El campo invisible reserva el mismo alto de fila. -->
                        <tr class="fila-vacia" aria-hidden="true">
                            <td class="denom">&nbsp;</td>
                            <td></td>
                            <td><input type="text" tabindex="-1" disabled></td>
                            <td></td>
                        </tr>
                        <tr class="fila-total">
                            <td>Total monedas</td><td></td><td></td>
                            <td class="col-num total-val" id="totalCoins">$0</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel caja-total bloque-sep">
            <div class="caja-total-label">Total general en caja</div>
            <div class="caja-total-valor" id="totalGeneral">$0</div>
        </div>

        <div class="acciones-rapidas bloque-sep">
            <button class="btn gris" type="button" id="btnLimpiar">Limpiar todo</button>
        </div>
    </div>

    <script>
      (function () {
        var bills = [20000, 10000, 5000, 2000, 1000];
        var coins = [500, 100, 50, 10];

        function fmt(n) {
          n = Math.round(n);
          return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        function entero(v) {
          var n = parseInt(String(v).replace(/[^\d]/g, ''), 10);
          return isNaN(n) ? 0 : n;
        }
        function decimal(v) {
          var n = parseFloat(String(v).replace(',', '.'));
          return isNaN(n) ? 0 : n;
        }
        function set(id, t) { var e = document.getElementById(id); if (e) e.textContent = t; }
        function val(id) { var e = document.getElementById(id); return e ? e.value : ''; }

        function recalcular() {
          var totBill = 0;
          bills.forEach(function (d) {
            var s = entero(val('b' + d)) * d;
            set('sb' + d, '$' + fmt(s));
            totBill += s;
          });
          set('totalBills', '$' + fmt(totBill));

          var totMon = 0;
          coins.forEach(function (d) {
            var v = entero(val('cq' + d)) * d;
            set('cv' + d, '$' + fmt(v));
            totMon += v;
          });
          set('totalCoins', '$' + fmt(totMon));
          set('totalGeneral', '$' + fmt(totBill + totMon));
        }

        // Billetes: solo recalcular
        document.querySelectorAll('.in-bill').forEach(function (el) {
          el.addEventListener('input', recalcular);
        });

        // Cantidad de monedas: si la moneda tiene peso, actualiza su peso estimado
        document.querySelectorAll('.in-coinqty').forEach(function (el) {
          el.addEventListener('input', function () {
            var w = el.dataset.weight;
            if (w) {
              var peso = entero(el.value) * parseFloat(w);   // kilos
              var campo = document.getElementById('cw' + el.dataset.denom);
              if (campo) campo.value = peso ? (Math.round(peso * 1000) / 1000).toString().replace('.', ',') : '';
            }
            recalcular();
          });
        });

        // Peso de monedas -> cantidad (cantidad = round(peso / peso_unitario))
        document.querySelectorAll('.in-coinw').forEach(function (el) {
          el.addEventListener('input', function () {
            var unit = parseFloat(el.dataset.weight);
            var q = Math.round(decimal(el.value) / unit);
            var campo = document.getElementById('cq' + el.dataset.denom);
            if (campo) campo.value = q ? q : '';
            recalcular();
          });
        });

        document.getElementById('btnLimpiar').addEventListener('click', function () {
          document.querySelectorAll('.tabla-caja input').forEach(function (i) { i.value = ''; });
          recalcular();
          enfocar(0);
        });

        // Navegación con Enter en el orden de conteo: billetes (mayor→menor),
        // luego $500, y el PESO de $100/$50/$10 (sus cantidades se calculan solas).
        var orden = ['b20000', 'b10000', 'b5000', 'b2000', 'b1000',
                     'cq500', 'cw100', 'cw50', 'cw10'];
        function enfocar(i) {
          var el = document.getElementById(orden[i]);
          if (el) { el.focus(); el.select(); }
        }
        orden.forEach(function (id, i) {
          var el = document.getElementById(id);
          if (!el) return;
          el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); enfocar(i + 1); }
          });
        });

        recalcular();
        enfocar(0);   // el cursor parte en el billete de mayor denominación
      })();
    </script>
    <?php pie_dashboard(); ?>
