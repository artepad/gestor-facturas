<?php
/**
 * Vista de app/Controllers/PortalProveedorController::index().
 * Variables disponibles: $usuario, $proveedor, $negocios, $productos.
 *
 * Layout PROPIO (no cabecera_dashboard/pie_dashboard — ver el controlador):
 * esta es la única pantalla que ve una cuenta externa (rol='representante'),
 * así que no comparte el menú ni el resto del dashboard de la casa. Reusa
 * .franja/.topbar/.main/.main-scroll de assets/estilo.css (genéricas, no
 * dependen de .app/.sidebar) para que se vea igual de prolijo.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catálogo de <?= e($proveedor['nombre']) ?> · Minimark</title>
    <?php $cssV = @filemtime(__DIR__ . '/../../../assets/estilo.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/estilo.css?v=<?= $cssV ?>">
</head>
<body>
    <div class="franja"></div>
    <header class="topbar">
        <h1><?= e($proveedor['nombre']) ?><span class="topbar-sub">Portal de proveedor · Minimark</span></h1>
        <a class="btn gris topbar-salir" href="logout.php">Cerrar sesión</a>
    </header>
    <div class="main">
      <div class="main-scroll">
        <div class="contenido">
            <div class="panel">
                <h2 class="panel-titulo">Tu catálogo</h2>
                <p class="texto-tenue">
                    Hola <?= e($usuario['nombre'] ?: $proveedor['nombre']) ?>. Acá ves los productos
                    de <?= e($proveedor['nombre']) ?> que Minimark tiene registrados
                    <?php if ($negocios): ?>
                        en <?= e(implode(' y ', array_column($negocios, 'nombre'))) ?>.
                    <?php else: ?>
                        .
                    <?php endif; ?>
                </p>

                <?php if ($productos): ?>
                <div class="campo campo-busqueda">
                    <input type="text" id="buscarProducto" autocomplete="off"
                           placeholder="Buscar por nombre o código...">
                </div>
                <div class="tabla-wrap">
                    <table id="tablaProductosProveedor">
                        <thead><tr><th>Código</th><th>Producto</th><th>Tu código</th></tr></thead>
                        <tbody>
                        <?php foreach ($productos as $p): ?>
                            <tr>
                                <td class="texto-tenue"><?= e($p['codigo']) ?></td>
                                <td><?= e($p['nombre']) ?></td>
                                <td class="texto-tenue"><?= e($p['codigo_proveedor'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <p class="vacio">Todavía no tienes productos asignados. Habla con Minimark para que te los asignen.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="footer-texto">Minimark · Plataforma de gestión</div>
      </div>
    </div>
    <div class="franja-azul"></div>
    <?php if ($productos): ?>
    <script>
      // Buscador simple en el cliente: la lista de un proveedor es acotada,
      // no hace falta ir al servidor (a diferencia del buscador de Compras,
      // que filtra todo el catálogo).
      (function () {
        var input = document.getElementById('buscarProducto');
        var filas = document.querySelectorAll('#tablaProductosProveedor tbody tr');
        input.addEventListener('input', function () {
          var q = input.value.trim().toLowerCase();
          filas.forEach(function (fila) {
            fila.hidden = q !== '' && fila.textContent.toLowerCase().indexOf(q) === -1;
          });
        });
      })();
    </script>
    <?php endif; ?>
</body>
</html>
