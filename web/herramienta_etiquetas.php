<?php
/**
 * Herramienta "Gestor de Etiquetas": crea etiquetas de precio para los
 * productos. El usuario llena nombre + precio de cada producto y al generar se
 * abre una hoja lista para imprimir o guardar como PDF (ver etiquetas_imprimir.php).
 *
 * El botón "Buscar" (autocompletar por código de barras desde la base de datos
 * de productos) se agregará cuando exista la herramienta de base de datos.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('herramientas');

$FILAS = 14;   // una hoja A4 = 14 etiquetas (2 columnas x 7 filas)
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Gestor de Etiquetas'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Gestor de Etiquetas</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
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
                        <tr>
                            <td class="et-num"><?= sprintf('%02d', $i) ?></td>
                            <td><input type="text" name="nombre[]" class="et-nombre" maxlength="120"
                                       placeholder="Nombre del producto" autocomplete="off"></td>
                            <td class="col-num">
                                <span class="et-peso">$</span><input type="text" name="precio[]"
                                    class="et-precio" inputmode="numeric" placeholder="0" autocomplete="off">
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

        <p class="texto-ayuda" style="margin-top:14px;">
            <strong>Próximamente:</strong> un botón <em>Buscar</em> para autocompletar el nombre y el precio
            escaneando el código de barras, cuando configuremos la base de datos de productos de cada negocio.
        </p>
    </div>
    <?php pie_dashboard(); ?>
