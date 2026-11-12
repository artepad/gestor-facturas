<?php
/**
 * Contador de Caja (billetes y monedas) — partial reutilizado por
 * herramientas/caja.php (pantalla propia, llegando desde Herramientas) y
 * caja/index.php (embebido dentro de "Cerrar caja"). Todo el cálculo es en
 * el navegador, no hay Modelo ni endpoint detrás — por eso es un simple
 * require en vez de una vista con su propio Controlador: un solo lugar con
 * la lógica, dos pantallas que la muestran.
 *
 * Pesos unitarios de las monedas chilenas (en KILOS, como muestra la balanza):
 * $100 = 0,00757 kg · $50 = 0,007 kg · $10 = 0,0035 kg.
 * cantidad = round(peso_total / peso_unitario).
 *
 * Variable opcional: $contadorParaCierre (bool). En true (embebido en Cerrar
 * caja) agrega el botón "Usar este total", que llena el campo
 * #efectivoContado de afuera con lo que vaya calculando acá — la vendedora
 * puede seguir corrigiéndolo a mano después si contó algo distinto.
 */
$contadorParaCierre = $contadorParaCierre ?? false;

$billetes = [20000, 10000, 5000, 2000, 1000];
$colores  = [20000 => '#e8590c', 10000 => '#1565c0', 5000 => '#e63946',
             2000 => '#9b59b6', 1000 => '#27ae60'];
$monedasPeso = [100 => 0.00757, 50 => 0.007, 10 => 0.0035];   // kilos por moneda
?>
<div class="contador-caja">
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
        <?php if ($contadorParaCierre): ?>
            <button class="btn verde" type="button" id="btnUsarTotalContador">Usar este total</button>
        <?php endif; ?>
    </div>
</div>

<script>
  (function () {
    var bills = [20000, 10000, 5000, 2000, 1000];
    var coins = [500, 100, 50, 10];
    var totalActual = 0;   // para "Usar este total"; se actualiza en cada recalcular()

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
      totalActual = totBill + totMon;
      set('totalGeneral', '$' + fmt(totalActual));
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

    <?php if ($contadorParaCierre): ?>
    // Lleva el total calculado al campo de cierre de caja (afuera de este
    // partial). La vendedora puede seguir corrigiéndolo a mano si cuenta
    // algo distinto al final.
    var btnUsar = document.getElementById('btnUsarTotalContador');
    if (btnUsar) {
      btnUsar.addEventListener('click', function () {
        var campo = document.getElementById('efectivoContado');
        if (!campo) return;
        campo.value = fmt(totalActual);
        campo.dispatchEvent(new Event('input'));
        campo.focus();
      });
    }
    <?php else: ?>
    enfocar(0);   // en la pantalla propia (Herramientas), el cursor parte solo
    <?php endif; ?>
  })();
</script>
