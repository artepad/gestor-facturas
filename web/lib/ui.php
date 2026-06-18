<?php
/**
 * Helpers de interfaz compartidos por las páginas del dashboard.
 */

/** Escapa texto para mostrarlo seguro dentro del HTML. */
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''));
}

/** Íconos SVG (stroke con currentColor para que tomen el color del tema).
 *  $extra agrega clases CSS al <svg> (ej. para alternar dos íconos en un botón). */
function icono(string $nombre, string $extra = ''): string
{
    $svg = [
        'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V10"/><path d="M9 21v-6h6v6"/>',
        'facturas' => '<path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
        'admin'    => '<path d="M3 6h18M3 12h18M3 18h18"/><circle cx="9" cy="6" r="2.4" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="2.4" fill="currentColor" stroke="none"/><circle cx="8" cy="18" r="2.4" fill="currentColor" stroke="none"/>',
        'negocios' => '<path d="M3 21h18"/><path d="M5 21V8l7-4 7 4v13"/><path d="M9 21v-6h6v6"/>',
        'usuarios' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5"/><path d="M16 5a3 3 0 0 1 0 6"/><path d="M18 20c0-2-1-3.5-2.5-4.3"/>',
        'salir'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'menu'     => '<path d="M3 12h18M3 6h18M3 18h18"/>',
        'flecha-izq' => '<path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>',
        'chevron'  => '<path d="M6 9l6 6 6-6"/>',
        'reloj'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'fiados'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>',
        'basurero' => '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
        'lapiz'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
        'documento'=> '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
        'ingresos' => '<circle cx="12" cy="12" r="9"/><path d="M12 6.5v11"/><path d="M14.6 9c-.5-.9-1.5-1.3-2.6-1.3-1.4 0-2.6.8-2.6 2 0 1.2 1.1 1.7 2.6 2.1 1.5.4 2.6.9 2.6 2.1 0 1.2-1.2 2-2.6 2-1.1 0-2.1-.4-2.6-1.3"/>',
        'gastos'   => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'categorias'=> '<path d="M3 6h18M3 12h18M3 18h18"/><circle cx="7" cy="6" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="17" cy="18" r="1.6" fill="currentColor" stroke="none"/>',
        'precios'  => '<path d="M12 7c0-2.2 1.8-4 4-4 0 2.2-1.8 4-4 4z"/><path d="M12 7c-.6-1.7-2.2-2.8-4-2.8-2.4 0-4 1.9-4 4.3 0 3.6 4 8.5 8 10.5 4-2 8-6.9 8-10.5 0-1.3-.5-2.4-1.3-3.1"/>',
        'herramientas' => '<path d="M14.5 5.5a3.6 3.6 0 0 0-4.8 4.8l-4.9 4.9a1.6 1.6 0 0 0 2.2 2.2l4.9-4.9a3.6 3.6 0 0 0 4.8-4.8l-2.4 2.4-2.2-2.2 2.4-2.4z"/>',
        'calculadora' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8"/><path d="M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01M8 18h4"/>',
        'etiqueta'  => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0l-6.2-6.2a2 2 0 0 1-.6-1.4V5a2 2 0 0 1 2-2h7.2a2 2 0 0 1 1.4.6l6.2 6.2a2 2 0 0 1 0 2.8z"/><circle cx="8.5" cy="8.5" r="1.5"/>',
        'telefono'  => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.6a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.4-1.1a2 2 0 0 1 2.1-.5c.8.3 1.7.5 2.6.6a2 2 0 0 1 1.7 2z"/>',
        'ubicacion' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'correo'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'oferta'    => '<path d="M19 5L5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'inventario'=> '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7l8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'lupa'      => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'codigo-barras' => '<path d="M3 5v14M6 5v14M9.5 5v14M13 5v14M16 5v14M18 5v14M21 5v14"/>',
    ];
    $d = $svg[$nombre] ?? '';
    $clase = 'ic' . ($extra !== '' ? ' ' . $extra : '');
    return '<svg class="' . $clase . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
         . $d . '</svg>';
}

/**
 * Abre el layout completo: documento HTML + franjas + header oscuro + sidebar +
 * área de contenido. Cada página solo agrega su <div class="contenido">…</div>.
 */
function cabecera_dashboard(array $usuario, string $activo = 'facturas', string $titulo = 'Minimark'): void
{
    $esAdmin = ($usuario['rol'] ?? '') === 'admin';
    $nombre = htmlspecialchars($usuario['nombre'] ?? '');
    $rol = htmlspecialchars(nombre_rol($usuario['rol'] ?? ''));
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($titulo) ?> · Minimark</title>
    <?php /* La fecha del archivo en la URL fuerza al navegador a recargar el CSS
             apenas cambia (evita tener que hacer Ctrl+F5 tras cada ajuste). */
       $cssV = @filemtime(__DIR__ . '/../assets/estilo.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/estilo.css?v=<?= $cssV ?>">
</head>
<body>
    <?php
    $item = function (string $id, string $txt, string $href) use ($activo) {
        $cls = 'nav-item' . ($activo === $id ? ' activo' : '');
        return "<a class=\"$cls\" href=\"$href\" title=\"$txt\">"
             . icono($id) . "<span class=\"txt\">$txt</span></a>";
    };
    $sub = function (string $id, string $txt, string $href) use ($activo) {
        $cls = 'subnav-item' . ($activo === $id ? ' activo' : '');
        return "<a class=\"$cls\" href=\"$href\">$txt</a>";
    };
    $adminAbierto = in_array($activo, ['negocios', 'usuarios'], true);
    ?>
    <!-- Barras superiores (franja verde + header oscuro), como en el escritorio -->
    <div class="franja"></div>
    <header class="topbar">
      <button class="hamburguesa" id="btnMovil" aria-label="Abrir menú">
        <?= icono('menu') ?>
      </button>
      <h1>Minimark<span class="topbar-sub">Plataforma de gestión</span></h1>
      <div class="topbar-reloj" aria-label="Fecha y hora actual">
        <?= icono('reloj') ?>
        <span class="reloj-fecha" id="relojFecha"></span>
        <span class="reloj-hora" id="relojHora"></span>
      </div>
    </header>

    <div class="app" id="app">
    <script>
      // El modo "colapsado" (íconos sin texto) es solo de escritorio; en móvil
      // el menú es un overlay de ancho completo, así que ahí no se aplica.
      if (localStorage.getItem('sidebar') === 'colapsado'
          && !window.matchMedia('(max-width: 768px)').matches)
        document.getElementById('app').classList.add('colapsado');
    </script>
      <aside class="sidebar">
        <div class="sidebar-top">
          <button class="toggle" id="btnToggle" aria-label="Contraer o cerrar el menú">
            <?= icono('menu', 'ic-menu') ?>
            <?= icono('flecha-izq', 'ic-cerrar') ?>
          </button>
        </div>
        <nav class="nav">
          <?= $item('home', 'Home', 'home.php') ?>
          <?php if (puede($usuario, 'ingresos')): ?><?= $item('ingresos', 'Ingresos', 'ingresos.php') ?><?php endif; ?>
          <?php if (puede($usuario, 'gastos')): ?><?= $item('gastos', 'Gastos', 'gastos.php') ?><?php endif; ?>
          <?php if (puede($usuario, 'facturas')): ?><?= $item('facturas', 'Facturas', 'panel_facturas.php') ?><?php endif; ?>
          <?php if (puede($usuario, 'fiados')): ?><?= $item('fiados', 'Fiados', 'fiados.php') ?><?php endif; ?>
          <?php if (puede($usuario, 'precios')): ?><?= $item('precios', 'Frutas y verduras', 'precios.php') ?><?php endif; ?>
          <?php if (puede($usuario, 'herramientas')): ?><?= $item('herramientas', 'Herramientas', 'herramientas.php') ?><?php endif; ?>
          <?php if (puede($usuario, 'negocios') || puede($usuario, 'usuarios')): ?>
            <div class="nav-grupo <?= $adminAbierto ? 'abierto' : '' ?>" id="grupoAdmin">
              <button class="nav-item grupo-toggle" id="btnAdmin" type="button" title="Administración">
                <?= icono('admin') ?>
                <span class="txt">Administración</span>
                <span class="chevron"><?= icono('chevron') ?></span>
              </button>
              <div class="subnav">
                <?php if (puede($usuario, 'negocios')): ?><?= $sub('negocios', 'Negocios', 'negocios.php') ?><?php endif; ?>
                <?php if (puede($usuario, 'usuarios')): ?><?= $sub('usuarios', 'Usuarios', 'usuarios.php') ?><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
          <div class="usuario-mini" title="<?= $nombre ?>">
            <span class="avatar"><?= strtoupper(substr($nombre, 0, 1) ?: 'U') ?></span>
            <span class="txt">
              <span class="u-nombre"><?= $nombre ?></span>
              <span class="u-rol"><?= $rol ?></span>
            </span>
          </div>
          <a class="nav-item salir" href="logout.php" title="Cerrar sesión">
            <?= icono('salir') ?><span class="txt">Cerrar sesión</span>
          </a>
        </div>
      </aside>
      <div class="backdrop" id="backdrop"></div>
      <div class="main">
        <div class="main-scroll">
    <?php
}

/** Cierra el layout + footer (franja azul) + script de interacción. */
function pie_dashboard(): void
{
    ?>
        </div><!-- .main-scroll -->
        <div class="footer-texto">Minimark · Plataforma de gestión</div>
      </div><!-- .main -->
    </div><!-- .app -->
    <div class="franja-azul"></div>
    <script>
      (function () {
        var app = document.getElementById('app');
        var t = document.getElementById('btnToggle');
        var m = document.getElementById('btnMovil');
        var b = document.getElementById('backdrop');
        var ga = document.getElementById('grupoAdmin');
        var ba = document.getElementById('btnAdmin');
        var esMovil = function () { return window.matchMedia('(max-width: 768px)').matches; };
        if (t) t.addEventListener('click', function () {
          // En móvil este botón CIERRA el menú; en escritorio lo colapsa.
          if (esMovil()) { app.classList.remove('movil-abierto'); return; }
          app.classList.toggle('colapsado');
          localStorage.setItem('sidebar',
            app.classList.contains('colapsado') ? 'colapsado' : 'expandido');
        });
        if (m) m.addEventListener('click', function () {
          app.classList.toggle('movil-abierto');
        });
        if (b) b.addEventListener('click', function () {
          app.classList.remove('movil-abierto');
        });
        // El modo colapsado no aplica en móvil: lo quitamos al pasar a móvil y
        // lo restauramos en escritorio según lo guardado.
        window.addEventListener('resize', function () {
          if (esMovil()) app.classList.remove('colapsado');
          else if (localStorage.getItem('sidebar') === 'colapsado') app.classList.add('colapsado');
        });
        if (ba) ba.addEventListener('click', function () {
          // Si el sidebar esta colapsado, primero lo expandimos
          if (app.classList.contains('colapsado')) {
            app.classList.remove('colapsado');
            localStorage.setItem('sidebar', 'expandido');
            ga.classList.add('abierto');
          } else {
            ga.classList.toggle('abierto');
          }
        });
      })();

      // Reloj en vivo del header: fecha + hora, se actualiza cada segundo.
      (function () {
        var ef = document.getElementById('relojFecha');
        var eh = document.getElementById('relojHora');
        if (!eh) return;
        var dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        var meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun',
                     'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        function dos(n) { return n < 10 ? '0' + n : n; }
        function tick() {
          var d = new Date();
          if (ef) ef.textContent = dias[d.getDay()] + ' ' + d.getDate() + ' '
                                 + meses[d.getMonth()] + ' ' + d.getFullYear();
          eh.textContent = dos(d.getHours()) + ':' + dos(d.getMinutes())
                         + ':' + dos(d.getSeconds());
        }
        tick();
        setInterval(tick, 1000);
      })();
    </script>
</body>
</html>
    <?php
}

/** Formatea un número como pesos chilenos: 119990 -> $119.990 */
function clp($v): string
{
    if ($v === null || $v === '') return '';
    return '$' . number_format((float)$v, 0, ',', '.');
}

/**
 * Interpreta un monto escrito por el usuario en formato chileno y lo devuelve
 * como número. En CLP el "." separa miles y la "," los decimales:
 *   "5.000" -> 5000.0 ; "$1.234.567" -> 1234567.0 ; "1500,50" -> 1500.5
 * Devuelve null si no hay un número válido.
 */
function parsear_monto($v): ?float
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    $s = str_replace(['$', ' ', '.'], '', $s);   // quita símbolo, espacios y miles
    $s = str_replace(',', '.', $s);               // coma decimal -> punto
    return is_numeric($s) ? (float)$s : null;
}

/** ISO (2026-05-20) -> dd-mm-yyyy (20-05-2026) */
function fecha_dmy($iso): string
{
    if (!$iso) return '';
    $p = explode('-', $iso);
    return count($p) === 3 ? "$p[2]-$p[1]-$p[0]" : $iso;
}

/** Estado visual de una factura según su confianza y notas. */
function estado_factura(array $f): array
{
    $conf = $f['confianza'];
    $notas = strtolower($f['notas'] ?? '');
    $adv = preg_match('/revisar|sospechos|advertencia|ilegible/', $notas);
    if ($conf !== null && $conf < 0.4) return ['rojo', 'Revisar (baja lectura)'];
    if ($conf === null || $conf < 0.7 || $adv) return ['amarillo', 'Revisar'];
    return ['verde', 'Correcto'];
}
