<?php
/**
 * Módulo Herramientas: tablero de utilidades operativas. Cada herramienta es
 * una tarjeta que lleva a su propia página. Para agregar una nueva, basta con
 * añadir una tarjeta aquí y crear su archivo.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('herramientas');
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Herramientas'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Herramientas</h2>
        </div>
        <p class="saludo-sub">Utilidades para el día a día del negocio.</p>

        <div class="herramientas-grid">
            <a class="herramienta-card" href="herramienta_catalogo.php">
                <span class="h-ic"><?= icono('codigo-barras') ?></span>
                <h3>Consultar Catálogo</h3>
                <p>Busca productos por nombre o código y ve su precio al instante.
                   Escanea el código de barras con la cámara del celular.</p>
            </a>

            <a class="herramienta-card" href="herramienta_caja.php">
                <span class="h-ic"><?= icono('calculadora') ?></span>
                <h3>Contador de Caja</h3>
                <p>Cuenta billetes y monedas para cuadrar la caja en minutos.
                   Las monedas se cuentan por peso.</p>
            </a>

            <a class="herramienta-card" href="herramienta_etiquetas.php">
                <span class="h-ic"><?= icono('etiqueta') ?></span>
                <h3>Gestor de Etiquetas</h3>
                <p>Crea etiquetas de precio para los productos, listas para imprimir o guardar en PDF.</p>
            </a>

            <a class="herramienta-card" href="herramienta_ofertas.php">
                <span class="h-ic"><?= icono('oferta') ?></span>
                <h3>Etiquetas de Ofertas</h3>
                <p>Crea etiquetas de oferta llamativas: descuentos, %, 3x y producto del día.</p>
            </a>

            <a class="herramienta-card" href="herramienta_productos.php">
                <span class="h-ic"><?= icono('inventario') ?></span>
                <h3>Base de Datos de Productos</h3>
                <p>Carga el catálogo de Eleventa (Excel) para que el Gestor de Etiquetas
                   busque por código de barras.</p>
            </a>
        </div>
    </div>
    <?php pie_dashboard(); ?>
