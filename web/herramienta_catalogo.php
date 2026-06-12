<?php
/**
 * Herramienta "Consultar Catálogo": busca productos del catálogo de un negocio
 * por nombre o código de barras y muestra su información (precio, código,
 * departamento, etc.). Pensada para el celular: incluye un escáner de código de
 * barras con la cámara (lector nativo del navegador o ZXing como respaldo).
 *
 * Es de solo consulta; la carga/edición del catálogo vive en
 * herramienta_productos.php. Desde un resultado se puede saltar al Gestor de
 * Etiquetas con el producto ya cargado ("Crear etiqueta").
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/productos.php';

$usuario  = exigir_permiso('herramientas');
$pdo      = obtener_pdo();
$negocios = negocios_visibles($usuario);

$negocioId = (int)($_GET['negocio'] ?? 0);
if ($negocioId && !puede_ver_negocio($usuario, $negocioId)) $negocioId = 0;
if (!$negocioId && $negocios) $negocioId = (int)$negocios[0]['id'];

$totalP   = $negocioId ? contar_productos($pdo, $negocioId) : 0;
$deptos   = $negocioId ? departamentos_de_negocio($pdo, $negocioId) : [];

$nombreNegocio = '';
foreach ($negocios as $n) if ((int)$n['id'] === $negocioId) $nombreNegocio = $n['nombre'];
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Consultar Catálogo'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Consultar Catálogo</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
        </div>

        <?php if (!$negocios): ?>
            <div class="panel"><p class="texto-ayuda">No tienes negocios asignados. Pídele a un administrador que te asigne uno.</p></div>
        <?php elseif ($totalP === 0): ?>
            <div class="panel">
                <p class="texto-ayuda">El negocio <strong><?= h($nombreNegocio) ?></strong> aún no tiene catálogo cargado.
                   <a href="herramienta_productos.php?negocio=<?= $negocioId ?>">Cargar la base de datos de productos →</a></p>
            </div>
        <?php else: ?>

        <div class="panel cat-panel" id="catalogo" data-negocio="<?= $negocioId ?>">
            <!-- Barra de búsqueda + escanear -->
            <div class="cat-buscar">
                <div class="cb-input">
                    <span class="cat-buscar-ic"><?= icono('lupa') ?></span>
                    <input type="text" id="catBuscar" placeholder="Nombre o código de barras…"
                           autocomplete="off" inputmode="search">
                </div>
                <button type="button" class="btn cat-escanear" id="btnEscanear">
                    <?= icono('codigo-barras') ?><span>Escanear</span>
                </button>
            </div>

            <!-- Filtros: negocio (si hay más de uno) + departamento -->
            <?php if (count($negocios) > 1 || $deptos): ?>
            <div class="cat-filtros">
                <?php if (count($negocios) > 1): ?>
                <form method="get" class="campo-inline">
                    <label for="negocio">Negocio</label>
                    <select name="negocio" id="negocio" onchange="this.form.submit()">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= (int)$n['id'] === $negocioId ? 'selected' : '' ?>>
                                <?= h($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
                <?php if ($deptos): ?>
                <div class="campo-inline">
                    <label for="catDepto">Departamento</label>
                    <select id="catDepto">
                        <option value="">Todos</option>
                        <?php foreach ($deptos as $d): ?>
                            <option value="<?= h($d) ?>"><?= h($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div id="catResultados" class="cat-resultados">
            <div class="cat-vacio">
                <span class="cat-vacio-ic"><?= icono('lupa') ?></span>
                <p>Busca por nombre, escribe un código o toca <strong>Escanear</strong>.</p>
            </div>
        </div>

        <!-- Capa del escáner (cámara) -->
        <div class="scanner" id="scanner" hidden>
            <div class="scanner-cab">
                <span id="scannerEstado">Iniciando cámara…</span>
                <button type="button" class="scanner-cerrar" id="btnCerrarScanner" aria-label="Cerrar">&times;</button>
            </div>
            <div class="scanner-video">
                <video id="scannerVideo" playsinline muted></video>
                <div class="scanner-marco"></div>
            </div>
            <p class="scanner-ayuda">Apunta la cámara al código de barras del producto.</p>
        </div>

        <?php endif; /* hay negocios y catálogo */ ?>
    </div>

    <?php if ($negocios && $totalP > 0): ?>
    <script>
      (function () {
        var raiz   = document.getElementById('catalogo');
        var neg    = raiz.dataset.negocio;
        var input  = document.getElementById('catBuscar');
        var selDep = document.getElementById('catDepto');
        var cont   = document.getElementById('catResultados');
        var timer  = null;

        function clp(n) { return '$' + Number(Math.round(n || 0)).toLocaleString('es-CL'); }
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
        function vacio(msg, cls) { return '<div class="cat-vacio ' + (cls || '') + '"><p>' + msg + '</p></div>'; }

        // ---- Búsqueda ----
        function buscar(desdeEscaneo) {
          var q = input.value.trim();
          var dep = selDep ? selDep.value : '';
          if (!q && !dep) { render([], false, ''); return; }
          fetch('catalogo_buscar.php?negocio_id=' + neg
                + '&q=' + encodeURIComponent(q) + '&departamento=' + encodeURIComponent(dep))
            .then(function (r) { return r.json(); })
            .then(function (d) { render(d.productos || [], desdeEscaneo, q); })
            .catch(function () { cont.innerHTML = '<p class="cat-vacio">No se pudo buscar. Intenta de nuevo.</p>'; });
        }

        function render(lista, desdeEscaneo, q) {
          if (!lista.length) {
            if (desdeEscaneo && q) {
              cont.innerHTML = vacio('No está en el catálogo: <strong>' + esc(q) + '</strong>', 'cat-noenc');
            } else if (input.value.trim() || (selDep && selDep.value)) {
              cont.innerHTML = vacio('Sin resultados.', '');
            } else {
              cont.innerHTML = vacio('Busca por nombre, escribe un código o toca <strong>Escanear</strong>.', '');
            }
            return;
          }
          var html = '<div class="cat-cards">';
          lista.forEach(function (p) {
            var venta = Math.round(p.precio_venta || 0);
            var liga = 'herramienta_etiquetas.php?nombre=' + encodeURIComponent(p.nombre) + '&precio=' + venta;
            html += '<div class="cat-card">'
                  +   '<div class="cc-top">'
                  +     '<div class="cc-nombre">' + esc(p.nombre) + '</div>'
                  +     '<div class="cc-venta">' + clp(p.precio_venta) + '</div>'
                  +   '</div>'
                  +   '<div class="cc-meta">'
                  +     '<span class="cc-cod">' + esc(p.codigo) + '</span>'
                  +     (p.departamento ? '<span class="cc-chip">' + esc(p.departamento) + '</span>' : '')
                  +     (p.tipo_venta ? '<span class="cc-tipo">' + esc(p.tipo_venta) + '</span>' : '')
                  +   '</div>'
                  +   '<div class="cc-precios">'
                  +     (Number(p.precio_costo) > 0 ? '<span>Costo: ' + clp(p.precio_costo) + '</span>' : '')
                  +     (Number(p.precio_mayoreo) > 0 ? '<span>Mayoreo: ' + clp(p.precio_mayoreo) + '</span>' : '')
                  +   '</div>'
                  +   '<a class="btn sm cc-etiqueta" href="' + liga + '">Crear etiqueta</a>'
                  + '</div>';
          });
          html += '</div>';
          cont.innerHTML = html;
        }

        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { buscar(false); }, 180); });
        if (selDep) selDep.addEventListener('change', function () { buscar(false); });

        // ---- Escáner de cámara (híbrido: BarcodeDetector nativo o ZXing) ----
        var overlay = document.getElementById('scanner');
        var video   = document.getElementById('scannerVideo');
        var estado  = document.getElementById('scannerEstado');
        var sc = { stream: null, reader: null, raf: null, detector: null, running: false, last: '' };

        function setEstado(t) { estado.textContent = t; }

        document.getElementById('btnEscanear').addEventListener('click', abrir);
        document.getElementById('btnCerrarScanner').addEventListener('click', cerrar);

        function abrir() {
          overlay.hidden = false;
          setEstado('Iniciando cámara…');
          iniciar().catch(function (e) {
            setEstado('No se pudo abrir la cámara. ' + (e && e.message ? e.message : ''));
          });
        }
        function cerrar() { detener(); overlay.hidden = true; }

        function iniciar() {
          if ('BarcodeDetector' in window) return iniciarNativo();
          return iniciarZxing();
        }

        function iniciarNativo() {
          sc.detector = new BarcodeDetector({
            formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf']
          });
          return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (stream) {
              sc.stream = stream; video.srcObject = stream; return video.play();
            })
            .then(function () { setEstado('Apunta al código de barras'); sc.running = true; loopNativo(); });
        }
        function loopNativo() {
          if (!sc.running) return;
          sc.detector.detect(video).then(function (codes) {
            if (codes && codes.length) onCodigo(codes[0].rawValue);
          }).catch(function () {});
          sc.raf = requestAnimationFrame(loopNativo);
        }

        function iniciarZxing() {
          return cargarZxing().then(function () {
            sc.reader = new ZXing.BrowserMultiFormatReader();
            return sc.reader.listVideoInputDevices().then(function (devs) {
              var back = devs.filter(function (d) { return /back|rear|environ|tras/i.test(d.label); })[0]
                       || devs[devs.length - 1];
              setEstado('Apunta al código de barras');
              sc.reader.decodeFromVideoDevice(back ? back.deviceId : undefined, video, function (res) {
                if (res) onCodigo(res.getText());
              });
            });
          });
        }
        function cargarZxing() {
          if (window.ZXing) return Promise.resolve();
          return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = 'assets/zxing.min.js';
            s.onload = resolve;
            s.onerror = function () { reject(new Error('No se pudo cargar el lector.')); };
            document.head.appendChild(s);
          });
        }

        function onCodigo(code) {
          code = (code || '').trim();
          if (!code || code === sc.last) return;
          sc.last = code;
          feedback();
          cerrar();
          input.value = code;
          buscar(true);
        }

        function detener() {
          sc.running = false;
          if (sc.raf) { cancelAnimationFrame(sc.raf); sc.raf = null; }
          if (sc.reader) { try { sc.reader.reset(); } catch (e) {} sc.reader = null; }
          if (sc.stream) { sc.stream.getTracks().forEach(function (t) { t.stop(); }); sc.stream = null; }
          sc.detector = null;
          setTimeout(function () { sc.last = ''; }, 1200);
        }

        function feedback() {
          if (navigator.vibrate) navigator.vibrate(80);
          try {
            var ac = new (window.AudioContext || window.webkitAudioContext)();
            var o = ac.createOscillator(), g = ac.createGain();
            o.frequency.value = 880; o.connect(g); g.connect(ac.destination); g.gain.value = 0.08;
            o.start(); setTimeout(function () { o.stop(); ac.close(); }, 120);
          } catch (e) {}
        }
      })();
    </script>
    <?php endif; ?>
    <?php pie_dashboard(); ?>
