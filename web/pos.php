<?php
/**
 * Punto de Venta: la pantalla de venta.
 *
 * ETAPA 1C. Replica el flujo de Eleventa que el usuario usa a diario
 * (ver POS_DISENO.md "Flujo real observado en Eleventa"):
 *
 *   escanear / ENTER -> agregar    Ctrl+P -> producto común    F10 -> buscar
 *   F7/F8 -> entrada/salida        DEL    -> borrar línea      F6  -> pendiente
 *   F12 (o + del numérico) -> cobrar      F2 -> cobrar sin imprimir   ESC -> cancelar
 *
 * Es una pantalla "de trabajo": sin el layout del dashboard, para que quepa
 * todo y se opere solo con el teclado. Todavía online-first; el modo offline
 * llega en la Etapa 3.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/pos.php';

$usuario = exigir_permiso('ventas');
$pdo = obtener_pdo();

// Caja obligatoria: sin turno abierto no se vende (decisión de diseño).
$sesion = sesion_abierta_de($pdo, (int)$usuario['id']);
if (!$sesion) {
    header('Location: caja.php');
    exit;
}
if (!puede_ver_negocio($usuario, (int)$sesion['negocio_id'])) {
    header('Location: home.php');
    exit;
}

$espera  = listar_espera($pdo, (int)$sesion['id']);
$ultima  = ultima_venta_del_turno($pdo, (int)$sesion['id']);
$ventas  = ventas_del_turno($pdo, (int)$sesion['id'], 50);
$resumen = resumen_caja($pdo, (int)$sesion['id']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Venta · <?= h($sesion['negocio_nombre']) ?></title>
<link rel="stylesheet" href="assets/estilo.css?v=<?= @filemtime(__DIR__ . '/assets/estilo.css') ?>">
</head>
<body class="pos-body">

<!-- ===================== Barra superior ===================== -->
<header class="pos-top">
    <div class="pos-top-izq">
        <span class="pos-marca"><?= icono('ventas') ?> Venta</span>
        <span class="pos-negocio"><?= h($sesion['negocio_nombre']) ?>
            · <?= h($sesion['caja'] ?: 'Caja') ?></span>
    </div>
    <div class="pos-top-der">
        <span class="pos-cajero"><?= h($usuario['nombre'] ?? '') ?></span>
        <button type="button" class="pos-btn-top" id="btnEspera">
            En espera <span class="pos-chip" id="chipEspera"><?= count($espera) ?></span>
        </button>
        <button type="button" class="pos-btn-top" id="btnVentas">Ventas del día</button>
        <a class="pos-btn-top" href="caja.php">Caja</a>
        <a class="pos-btn-top" href="home.php">Salir</a>
    </div>
</header>

<!-- ===================== Entrada de código + acciones ===================== -->
<div class="pos-barra">
    <label class="pos-cod-lbl" for="codigo"><?= icono('codigo-barras') ?> Código del producto</label>
    <input type="text" id="codigo" class="pos-cod" autocomplete="off" autofocus
           placeholder="Escanea o escribe el código y presiona Enter">
    <button type="button" class="btn" id="btnAgregar">Agregar (Enter)</button>
</div>

<div class="pos-acciones">
    <button type="button" class="pos-tecla" data-accion="comun"><b>Ctrl+P</b> Producto común</button>
    <button type="button" class="pos-tecla" data-accion="buscar"><b>F10</b> Buscar</button>
    <button type="button" class="pos-tecla" data-accion="entrada"><b>F7</b> Entrada</button>
    <button type="button" class="pos-tecla" data-accion="salida"><b>F8</b> Salida</button>
    <button type="button" class="pos-tecla" data-accion="borrar"><b>Supr</b> Borrar línea</button>
    <button type="button" class="pos-tecla" data-accion="pendiente"><b>F6</b> Pendiente</button>
    <button type="button" class="pos-tecla pos-tecla-rojo" data-accion="eliminar">Eliminar venta</button>
</div>

<!-- ===================== Carrito ===================== -->
<main class="pos-main">
    <table class="pos-tabla" id="tablaCarrito">
        <thead>
            <tr>
                <th class="c-cod">Código</th>
                <th>Descripción</th>
                <th class="c-num">Precio</th>
                <th class="c-num">Cant.</th>
                <th class="c-num">Importe</th>
                <th class="c-x"></th>
            </tr>
        </thead>
        <tbody id="cuerpoCarrito"></tbody>
    </table>
    <p class="pos-vacio" id="carritoVacio">
        Escanea un producto para empezar. También puedes buscarlo con <b>F10</b>
        o usar <b>Ctrl+P</b> para un producto común.
    </p>
</main>

<!-- ===================== Pie: última venta + total + cobrar ===================== -->
<footer class="pos-pie">
    <div class="pos-ultima">
        <span class="pos-ultima-tit">Última venta<?= $ultima ? ' · N° ' . (int)$ultima['folio'] : '' ?></span>
        <div class="pos-ultima-datos">
            <span>Total: <b id="uTotal"><?= $ultima ? clp($ultima['total']) : '$0' ?></b></span>
            <span>Pagó con: <b id="uPago"><?= $ultima && $ultima['pago_con'] !== null ? clp($ultima['pago_con']) : '$0' ?></b></span>
            <span>Cambio: <b id="uVuelto"><?= $ultima ? clp($ultima['vuelto']) : '$0' ?></b></span>
        </div>
    </div>
    <div class="pos-cuenta">
        <span id="nItems">0 productos en la venta actual.</span>
    </div>
    <div class="pos-total-caja">
        <span class="pos-total-lbl">Total</span>
        <span class="pos-total" id="totalVenta">$0</span>
    </div>
    <button type="button" class="pos-cobrar" id="btnCobrar">Cobrar <span>F12</span></button>
</footer>

<!-- ===================== Modales ===================== -->
<div class="pos-modal-fondo" id="modalFondo" hidden></div>

<!-- Cobrar -->
<section class="pos-modal" id="modalCobrar" hidden aria-label="Cobrar">
    <h2>Cobrar</h2>
    <div class="cobrar-total" id="cobrarTotal">$0</div>
    <div class="cobrar-medios">
        <button type="button" class="medio activo" data-medio="efectivo">Efectivo</button>
        <button type="button" class="medio" data-medio="tarjeta">Tarjeta</button>
        <button type="button" class="medio" data-medio="transferencia">Transferencia</button>
    </div>
    <div class="cobrar-efectivo" id="bloqueEfectivo">
        <label for="pagoCon">Pagó con</label>
        <input type="text" id="pagoCon" inputmode="numeric" autocomplete="off" placeholder="0">
        <div class="cobrar-vuelto">Su cambio: <b id="vuelto">$0</b></div>
        <div class="cobrar-rapidos" id="rapidos"></div>
    </div>
    <div class="pos-modal-err" id="cobrarErr" hidden></div>
    <div class="pos-modal-btns">
        <button type="button" class="btn verde grande" id="btnCobrarOk">Cobrar <span>F2</span></button>
        <button type="button" class="btn gris" data-cerrar>Cancelar (Esc)</button>
    </div>
</section>

<!-- Producto común -->
<section class="pos-modal" id="modalComun" hidden aria-label="Producto común">
    <h2>Producto común</h2>
    <p class="pos-modal-ayuda">Para lo que no tiene código: pan, verduras, granel…</p>
    <label for="comunNombre">Descripción</label>
    <input type="text" id="comunNombre" autocomplete="off" placeholder="Pan">
    <label for="comunPrecio">Precio</label>
    <input type="text" id="comunPrecio" inputmode="numeric" autocomplete="off" placeholder="1.850">
    <label for="comunCant">Cantidad</label>
    <input type="text" id="comunCant" inputmode="decimal" autocomplete="off" value="1">
    <div class="pos-modal-err" id="comunErr" hidden></div>
    <div class="pos-modal-btns">
        <button type="button" class="btn verde" id="btnComunOk">Agregar</button>
        <button type="button" class="btn gris" data-cerrar>Cancelar (Esc)</button>
    </div>
</section>

<!-- Buscar -->
<section class="pos-modal pos-modal-ancho" id="modalBuscar" hidden aria-label="Buscar producto">
    <h2>Buscar producto</h2>
    <input type="text" id="buscarQ" autocomplete="off" placeholder="Escribe parte del nombre o del código">
    <div class="buscar-res" id="buscarRes"></div>
    <div class="pos-modal-btns">
        <button type="button" class="btn gris" data-cerrar>Cerrar (Esc)</button>
    </div>
</section>

<!-- Tickets en espera -->
<section class="pos-modal pos-modal-ancho" id="modalEspera" hidden aria-label="Ventas en espera">
    <h2>Ventas en espera</h2>
    <div class="espera-lista" id="esperaLista"></div>
    <div class="pos-modal-btns">
        <button type="button" class="btn gris" data-cerrar>Cerrar (Esc)</button>
    </div>
</section>

<!-- Dejar pendiente -->
<section class="pos-modal" id="modalPendiente" hidden aria-label="Dejar en espera">
    <h2>Dejar esta venta en espera</h2>
    <p class="pos-modal-ayuda">Ponle un nombre para reconocerla después (opcional).</p>
    <label for="pendEtiqueta">Nombre del ticket</label>
    <input type="text" id="pendEtiqueta" autocomplete="off" placeholder="Sra. María">
    <div class="pos-modal-btns">
        <button type="button" class="btn verde" id="btnPendOk">Dejar en espera</button>
        <button type="button" class="btn gris" data-cerrar>Cancelar (Esc)</button>
    </div>
</section>

<!-- Entrada / salida de efectivo -->
<section class="pos-modal" id="modalMov" hidden aria-label="Movimiento de efectivo">
    <h2 id="movTitulo">Salida de efectivo</h2>
    <label for="movMonto">Monto</label>
    <input type="text" id="movMonto" inputmode="numeric" autocomplete="off" placeholder="5.000">
    <label for="movMotivo" id="movMotivoLbl">Motivo *</label>
    <input type="text" id="movMotivo" autocomplete="off" placeholder="Pago pan proveedor">
    <div class="pos-modal-err" id="movErr" hidden></div>
    <div class="pos-modal-btns">
        <button type="button" class="btn verde" id="btnMovOk">Registrar</button>
        <button type="button" class="btn gris" data-cerrar>Cancelar (Esc)</button>
    </div>
</section>

<!-- Ventas del día -->
<section class="pos-modal pos-modal-ancho" id="modalVentas" hidden aria-label="Ventas del turno">
    <h2>Ventas de este turno</h2>
    <div class="espera-lista" id="ventasLista"></div>
    <div class="pos-modal-btns">
        <button type="button" class="btn gris" data-cerrar>Cerrar (Esc)</button>
    </div>
</section>

<div class="pos-toast" id="toast" hidden></div>

<script>
(function () {
'use strict';

// ---------- Estado ----------
var carrito = [];          // líneas de la venta actual
var sel = -1;              // índice de la línea seleccionada (para Supr)
var medio = 'efectivo';
var cobrando = false;      // evita cobrar dos veces con doble clic/tecla
var espera = <?= json_encode($espera, JSON_UNESCAPED_UNICODE) ?>;
var ventas = <?= json_encode(array_map(fn($v) => [
        'folio' => (int)$v['folio'], 'total' => (float)$v['total'],
        'hora' => substr((string)$v['fecha'], 11, 5),
        'n' => (int)$v['n_items'], 'medios' => $v['medios'],
    ], $ventas), JSON_UNESCAPED_UNICODE) ?>;

var $ = function (id) { return document.getElementById(id); };

// ---------- Formato chileno ----------
function clp(n) {
    n = Math.round(Number(n) || 0);
    return '$' + n.toLocaleString('es-CL');
}
// "5.000" / "$5.000" -> 5000  (en CLP el punto separa miles)
function num(txt) {
    var s = String(txt == null ? '' : txt).replace(/[$\s.]/g, '').replace(',', '.');
    var v = parseFloat(s);
    return isNaN(v) ? null : v;
}
function uuid4() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        var r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
}

function toast(msg, malo) {
    var t = $('toast');
    t.textContent = msg;
    t.className = 'pos-toast' + (malo ? ' malo' : '');
    t.hidden = false;
    clearTimeout(t._t);
    t._t = setTimeout(function () { t.hidden = true; }, 2600);
}

// ---------- Carrito ----------
function totalCarrito() {
    return carrito.reduce(function (s, l) {
        return s + l.cantidad * l.precio_unitario - (l.descuento || 0);
    }, 0);
}

function pintarCarrito() {
    var tb = $('cuerpoCarrito');
    tb.innerHTML = '';
    carrito.forEach(function (l, i) {
        var tr = document.createElement('tr');
        if (i === sel) tr.className = 'sel';
        tr.tabIndex = 0;
        tr.addEventListener('click', function () { sel = i; pintarCarrito(); });
        var imp = l.cantidad * l.precio_unitario - (l.descuento || 0);
        [[l.codigo || '—', 'c-cod'], [l.nombre, ''], [clp(l.precio_unitario), 'c-num'],
         [(l.cantidad % 1 === 0 ? l.cantidad : l.cantidad.toFixed(3)), 'c-num'],
         [clp(imp), 'c-num']].forEach(function (par) {
            var td = document.createElement('td');
            td.className = par[1];
            td.textContent = par[0];
            tr.appendChild(td);
        });
        var tdx = document.createElement('td');
        tdx.className = 'c-x';
        var bx = document.createElement('button');
        bx.type = 'button'; bx.className = 'pos-x'; bx.textContent = '×';
        bx.title = 'Quitar';
        bx.addEventListener('click', function (e) { e.stopPropagation(); quitar(i); });
        tdx.appendChild(bx); tr.appendChild(tdx);
        tb.appendChild(tr);
    });
    $('carritoVacio').hidden = carrito.length > 0;
    $('totalVenta').textContent = clp(totalCarrito());
    var n = carrito.reduce(function (s, l) { return s + l.cantidad; }, 0);
    $('nItems').textContent = (carrito.length === 0)
        ? '0 productos en la venta actual.'
        : (carrito.length + ' línea(s) · ' + (n % 1 === 0 ? n : n.toFixed(3)) + ' producto(s) en la venta actual.');
}

function agregarLinea(p) {
    // Si ya está el mismo producto con el mismo precio, suma la cantidad.
    var iguales = carrito.findIndex(function (l) {
        return l.producto_id && p.producto_id && l.producto_id === p.producto_id
            && l.precio_unitario === p.precio_unitario;
    });
    if (iguales >= 0) {
        carrito[iguales].cantidad += (p.cantidad || 1);
        sel = iguales;
    } else {
        carrito.push({
            producto_id: p.producto_id || null,
            codigo: p.codigo || null,
            nombre: p.nombre,
            departamento: p.departamento || null,
            cantidad: p.cantidad || 1,
            precio_unitario: p.precio_unitario,
            descuento: 0,
            costo_unitario: (p.costo_unitario == null ? null : p.costo_unitario),
            afecto_iva: p.afecto_iva == null ? 1 : p.afecto_iva
        });
        sel = carrito.length - 1;
    }
    pintarCarrito();
}

function quitar(i) {
    carrito.splice(i, 1);
    if (sel >= carrito.length) sel = carrito.length - 1;
    pintarCarrito();
}

function vaciar() { carrito = []; sel = -1; pintarCarrito(); }

// ---------- Comunicación con el servidor ----------
function api(accion, datos, metodo) {
    var url = 'pos_api.php?accion=' + encodeURIComponent(accion);
    var op = { method: metodo || 'POST', headers: { 'Content-Type': 'application/json' } };
    if (op.method === 'POST') op.body = JSON.stringify(datos || {});
    return fetch(url, op).then(function (r) {
        return r.json().catch(function () { return { error: 'Respuesta inesperada del servidor.' }; })
            .then(function (j) {
                if (!r.ok || j.error) throw new Error(j.error || ('Error ' + r.status));
                return j;
            });
    });
}

// ---------- Escanear / agregar por código ----------
function agregarPorCodigo() {
    var q = $('codigo').value.trim();
    if (!q) return;
    // "3*1200" o "2x1200" = cantidad por precio, sin producto del catálogo.
    var m = q.match(/^(\d+(?:[.,]\d+)?)\s*[*xX]\s*(\d+(?:[.,]\d+)?)$/);
    if (m) {
        var c = parseFloat(m[1].replace(',', '.')), pr = num(m[2]);
        if (c > 0 && pr !== null) {
            agregarLinea({ nombre: 'Varios', precio_unitario: pr, cantidad: c });
            $('codigo').value = '';
            return;
        }
    }
    api('buscar&q=' + encodeURIComponent(q), null, 'GET').then(function (j) {
        if (!j.resultados.length) {
            toast('No se encontró "' + q + '". Usa F10 para buscar o Ctrl+P.', true);
            $('codigo').select();
            return;
        }
        if (j.exacto) {
            agregarLinea(j.resultados[0]);
            $('codigo').value = '';
        } else {
            $('codigo').value = '';
            abrirBuscar(q);
        }
    }).catch(function (e) { toast(e.message, true); });
}

// ---------- Modales ----------
var modalActivo = null;
function abrirModal(id, foco) {
    cerrarModal();
    modalActivo = id;
    $('modalFondo').hidden = false;
    $(id).hidden = false;
    if (foco) setTimeout(function () { var f = $(foco); if (f) { f.focus(); f.select && f.select(); } }, 30);
}
function cerrarModal() {
    if (!modalActivo) return;
    $(modalActivo).hidden = true;
    $('modalFondo').hidden = true;
    modalActivo = null;
    $('codigo').focus();
}
Array.prototype.forEach.call(document.querySelectorAll('[data-cerrar]'), function (b) {
    b.addEventListener('click', cerrarModal);
});
$('modalFondo').addEventListener('click', cerrarModal);

// ---------- Cobrar ----------
function abrirCobrar() {
    if (!carrito.length) { toast('No hay productos en la venta.', true); return; }
    medio = 'efectivo';
    Array.prototype.forEach.call(document.querySelectorAll('.medio'), function (b) {
        b.classList.toggle('activo', b.dataset.medio === 'efectivo');
    });
    $('bloqueEfectivo').hidden = false;
    $('cobrarTotal').textContent = clp(totalCarrito());
    $('pagoCon').value = '';
    $('cobrarErr').hidden = true;
    pintarVuelto();
    pintarRapidos();
    abrirModal('modalCobrar', 'pagoCon');
}

// Botones de billetes: el exacto y los billetes chilenos que alcanzan.
function pintarRapidos() {
    var t = totalCarrito(), cont = $('rapidos');
    cont.innerHTML = '';
    var vals = [t];
    [1000, 2000, 5000, 10000, 20000].forEach(function (b) {
        if (b >= t) vals.push(b);
        var techo = Math.ceil(t / b) * b;      // el siguiente múltiplo del billete
        if (techo > t) vals.push(techo);
    });
    vals.filter(function (v, i, a) { return v > 0 && a.indexOf(v) === i; })
        .sort(function (a, b) { return a - b; }).slice(0, 5)
        .forEach(function (v) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'rapido';
            b.textContent = (v === t ? 'Justo ' : '') + clp(v);
            b.addEventListener('click', function () {
                $('pagoCon').value = String(Math.round(v));
                pintarVuelto();
                $('pagoCon').focus();
            });
            cont.appendChild(b);
        });
}

function pintarVuelto() {
    var t = totalCarrito(), p = num($('pagoCon').value);
    var v = (p === null) ? 0 : p - t;
    $('vuelto').textContent = clp(v > 0 ? v : 0);
    $('vuelto').className = (p !== null && p + 1 < t) ? 'falta' : '';
}
$('pagoCon').addEventListener('input', pintarVuelto);

Array.prototype.forEach.call(document.querySelectorAll('.medio'), function (b) {
    b.addEventListener('click', function () {
        medio = b.dataset.medio;
        Array.prototype.forEach.call(document.querySelectorAll('.medio'), function (o) {
            o.classList.toggle('activo', o === b);
        });
        $('bloqueEfectivo').hidden = (medio !== 'efectivo');
    });
});

function cobrar() {
    if (cobrando || !carrito.length) return;
    var t = totalCarrito();
    var pago = (medio === 'efectivo') ? num($('pagoCon').value) : null;
    if (medio === 'efectivo' && pago !== null && pago + 1 < t) {
        $('cobrarErr').textContent = 'El monto recibido es menor que el total.';
        $('cobrarErr').hidden = false;
        return;
    }
    cobrando = true;
    $('btnCobrarOk').disabled = true;
    api('cobrar', { uuid: uuid4(), items: carrito, medio: medio, pago_con: pago })
        .then(function (j) {
            var v = j.venta;
            $('uTotal').textContent = clp(v.total);
            $('uPago').textContent = clp(v.pago_con || 0);
            $('uVuelto').textContent = clp(v.vuelto || 0);
            document.querySelector('.pos-ultima-tit').textContent = 'Última venta · N° ' + v.folio;
            ventas.unshift({ folio: v.folio, total: v.total, hora: new Date().toTimeString().slice(0, 5),
                             n: carrito.length, medios: medio });
            vaciar();
            cerrarModal();
            toast('Venta N° ' + v.folio + ' cobrada' +
                  (v.vuelto > 0 ? ' · Cambio ' + clp(v.vuelto) : ''));
        })
        .catch(function (e) {
            $('cobrarErr').textContent = e.message;
            $('cobrarErr').hidden = false;
        })
        .then(function () { cobrando = false; $('btnCobrarOk').disabled = false; });
}
$('btnCobrarOk').addEventListener('click', cobrar);
$('btnCobrar').addEventListener('click', abrirCobrar);
$('pagoCon').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); cobrar(); }
});

// ---------- Producto común ----------
function abrirComun() {
    $('comunNombre').value = ''; $('comunPrecio').value = ''; $('comunCant').value = '1';
    $('comunErr').hidden = true;
    abrirModal('modalComun', 'comunNombre');
}
function agregarComun() {
    var n = $('comunNombre').value.trim(), p = num($('comunPrecio').value),
        c = num($('comunCant').value);
    if (!n) { $('comunErr').textContent = 'Escribe una descripción.'; $('comunErr').hidden = false; return; }
    if (p === null || p < 0) { $('comunErr').textContent = 'Escribe un precio válido.'; $('comunErr').hidden = false; return; }
    if (c === null || c <= 0) c = 1;
    agregarLinea({ nombre: n, precio_unitario: p, cantidad: c });
    cerrarModal();
}
$('btnComunOk').addEventListener('click', agregarComun);
['comunNombre', 'comunPrecio', 'comunCant'].forEach(function (id) {
    $(id).addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); agregarComun(); }
    });
});

// ---------- Buscar ----------
var tBuscar = null;
function abrirBuscar(q) {
    $('buscarQ').value = q || '';
    $('buscarRes').innerHTML = '';
    abrirModal('modalBuscar', 'buscarQ');
    if (q) buscar();
}
function buscar() {
    var q = $('buscarQ').value.trim();
    if (q.length < 2) { $('buscarRes').innerHTML = '<p class="pos-vacio">Escribe al menos 2 letras.</p>'; return; }
    api('buscar&q=' + encodeURIComponent(q), null, 'GET').then(function (j) {
        var c = $('buscarRes');
        c.innerHTML = '';
        if (!j.resultados.length) { c.innerHTML = '<p class="pos-vacio">Sin resultados.</p>'; return; }
        j.resultados.forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'buscar-item';
            b.innerHTML = '<span class="bi-nom"></span><span class="bi-cod"></span><span class="bi-pre"></span>';
            b.querySelector('.bi-nom').textContent = p.nombre;
            b.querySelector('.bi-cod').textContent = p.codigo || '';
            b.querySelector('.bi-pre').textContent = clp(p.precio_unitario);
            b.addEventListener('click', function () { agregarLinea(p); cerrarModal(); });
            c.appendChild(b);
        });
    }).catch(function (e) { toast(e.message, true); });
}
$('buscarQ').addEventListener('input', function () {
    clearTimeout(tBuscar); tBuscar = setTimeout(buscar, 220);
});
$('buscarQ').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        var p = $('buscarRes').querySelector('.buscar-item');
        if (p) p.click();
    }
});

// ---------- Tickets en espera ----------
function chip() { $('chipEspera').textContent = espera.length; }

function pintarEspera() {
    var c = $('esperaLista');
    c.innerHTML = '';
    if (!espera.length) { c.innerHTML = '<p class="pos-vacio">No hay ventas en espera.</p>'; return; }
    espera.forEach(function (t) {
        var fila = document.createElement('div');
        fila.className = 'espera-fila';
        var info = document.createElement('div');
        info.className = 'espera-info';
        info.innerHTML = '<b></b><span></span>';
        info.querySelector('b').textContent = t.etiqueta || ('Ticket ' + t.numero);
        info.querySelector('span').textContent = clp(t.total_estimado);
        var bt = document.createElement('button');
        bt.type = 'button'; bt.className = 'btn'; bt.textContent = 'Retomar';
        bt.addEventListener('click', function () {
            if (carrito.length && !confirm('Hay una venta en curso. ¿Reemplazarla por este ticket?')) return;
            api('espera_tomar', { id: t.id }).then(function (j) {
                carrito = j.ticket.items || []; sel = carrito.length - 1;
                espera = j.espera; chip(); pintarCarrito(); cerrarModal();
                toast('Ticket retomado.');
            }).catch(function (e) { toast(e.message, true); });
        });
        var bd = document.createElement('button');
        bd.type = 'button'; bd.className = 'btn rojo'; bd.textContent = 'Descartar';
        bd.addEventListener('click', function () {
            if (!confirm('¿Descartar este ticket? Se perderá lo que tenía.')) return;
            api('espera_descartar', { id: t.id }).then(function (j) {
                espera = j.espera; chip(); pintarEspera();
                toast('Ticket descartado.');
            }).catch(function (e) { toast(e.message, true); });
        });
        fila.appendChild(info); fila.appendChild(bt); fila.appendChild(bd);
        c.appendChild(fila);
    });
}
$('btnEspera').addEventListener('click', function () { pintarEspera(); abrirModal('modalEspera'); });

function abrirPendiente() {
    if (!carrito.length) { toast('No hay productos que dejar en espera.', true); return; }
    $('pendEtiqueta').value = '';
    abrirModal('modalPendiente', 'pendEtiqueta');
}
function dejarPendiente() {
    api('espera_guardar', { items: carrito, etiqueta: $('pendEtiqueta').value.trim() })
        .then(function (j) {
            espera = j.espera; chip(); vaciar(); cerrarModal();
            toast('Venta en espera guardada.');
        }).catch(function (e) { toast(e.message, true); });
}
$('btnPendOk').addEventListener('click', dejarPendiente);
$('pendEtiqueta').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); dejarPendiente(); }
});

// ---------- Entrada / salida de efectivo ----------
var movTipo = 'salida';
function abrirMov(tipo) {
    movTipo = tipo;
    $('movTitulo').textContent = (tipo === 'entrada' ? 'Entrada de efectivo' : 'Salida de efectivo');
    $('movMotivoLbl').textContent = (tipo === 'salida' ? 'Motivo *' : 'Motivo (opcional)');
    $('movMonto').value = ''; $('movMotivo').value = ''; $('movErr').hidden = true;
    abrirModal('modalMov', 'movMonto');
}
function guardarMov() {
    api('movimiento', { tipo: movTipo, monto: $('movMonto').value, motivo: $('movMotivo').value })
        .then(function (j) { cerrarModal(); toast(j.mensaje); })
        .catch(function (e) { $('movErr').textContent = e.message; $('movErr').hidden = false; });
}
$('btnMovOk').addEventListener('click', guardarMov);
['movMonto', 'movMotivo'].forEach(function (id) {
    $(id).addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); guardarMov(); }
    });
});

// ---------- Ventas del turno ----------
$('btnVentas').addEventListener('click', function () {
    var c = $('ventasLista');
    c.innerHTML = '';
    if (!ventas.length) { c.innerHTML = '<p class="pos-vacio">Aún no hay ventas en este turno.</p>'; }
    ventas.forEach(function (v) {
        var f = document.createElement('div');
        f.className = 'espera-fila';
        var i = document.createElement('div');
        i.className = 'espera-info';
        i.innerHTML = '<b></b><span></span>';
        i.querySelector('b').textContent = 'N° ' + v.folio + ' · ' + v.hora;
        i.querySelector('span').textContent = v.n + ' producto(s) · ' + (v.medios || '');
        var m = document.createElement('div');
        m.className = 'espera-monto';
        m.textContent = clp(v.total);
        f.appendChild(i); f.appendChild(m);
        c.appendChild(f);
    });
    abrirModal('modalVentas');
});

// ---------- Botones de la barra de acciones ----------
Array.prototype.forEach.call(document.querySelectorAll('[data-accion]'), function (b) {
    b.addEventListener('click', function () { accion(b.dataset.accion); });
});
$('btnAgregar').addEventListener('click', agregarPorCodigo);
$('codigo').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); agregarPorCodigo(); }
});

function accion(a) {
    if (a === 'comun')     return abrirComun();
    if (a === 'buscar')    return abrirBuscar($('codigo').value.trim());
    if (a === 'entrada')   return abrirMov('entrada');
    if (a === 'salida')    return abrirMov('salida');
    if (a === 'pendiente') return abrirPendiente();
    if (a === 'borrar') {
        if (sel >= 0 && sel < carrito.length) quitar(sel);
        else toast('Selecciona una línea primero.', true);
        return;
    }
    if (a === 'eliminar') {
        if (!carrito.length) return;
        if (confirm('¿Eliminar toda la venta en curso?')) { vaciar(); toast('Venta eliminada.'); }
    }
}

// ---------- Teclado (el corazón de la velocidad) ----------
// F12 y F1 los reserva el navegador y NO se pueden interceptar desde una
// página normal: por eso el POS se abre en modo kiosco/app (ver POS_DISENO.md)
// y además se deja el + del teclado numérico como alternativa garantizada.
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { if (modalActivo) { e.preventDefault(); cerrarModal(); } return; }

    // Dentro de un modal solo actúan Esc y F2 (cobrar).
    if (modalActivo) {
        if (e.key === 'F2' && modalActivo === 'modalCobrar') { e.preventDefault(); cobrar(); }
        return;
    }

    if (e.key === 'F5') { e.preventDefault(); return; }   // no perder el carrito por error
    if (e.ctrlKey && (e.key === 'p' || e.key === 'P')) { e.preventDefault(); return accion('comun'); }

    switch (e.key) {
        case 'F10': e.preventDefault(); return accion('buscar');
        case 'F7':  e.preventDefault(); return accion('entrada');
        case 'F8':  e.preventDefault(); return accion('salida');
        case 'F6':  e.preventDefault(); return accion('pendiente');
        case 'F12': e.preventDefault(); return abrirCobrar();
        case 'Delete': e.preventDefault(); return accion('borrar');
        case 'ArrowUp':
            if (carrito.length) { e.preventDefault(); sel = Math.max(0, sel - 1); pintarCarrito(); }
            return;
        case 'ArrowDown':
            if (carrito.length) { e.preventDefault(); sel = Math.min(carrito.length - 1, sel + 1); pintarCarrito(); }
            return;
    }
    // "+" del teclado numérico: alternativa a F12 que ningún navegador se reserva.
    if (e.key === '+' && (e.location === 3 || e.code === 'NumpadAdd')) {
        e.preventDefault(); return abrirCobrar();
    }
});

// El foco vuelve siempre al campo de código: el lector "teclea" ahí.
document.addEventListener('click', function (e) {
    if (modalActivo) return;
    if (e.target.closest('button, a, input, .pos-tabla')) return;
    $('codigo').focus();
});

// Aviso al salir con una venta a medias o tickets sin cobrar.
window.addEventListener('beforeunload', function (e) {
    if (carrito.length || espera.length) { e.preventDefault(); e.returnValue = ''; }
});

pintarCarrito();
chip();
})();
</script>
</body>
</html>
