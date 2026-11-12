<?php
/**
 * Vista de app/Controllers/HerramientasController::caja().
 * Variables disponibles: $usuario.
 *
 * El contador en sí (billetes y monedas) vive en
 * app/views/partials/contador_caja.php — lo comparte con "Cerrar caja"
 * (caja/index.php), para no duplicar la lógica de conteo en dos pantallas.
 */
require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'herramientas', 'Contador de Caja'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Contador de Caja</h2>
            <a class="btn gris" href="herramientas.php">Volver</a>
        </div>

        <?php require __DIR__ . '/../partials/contador_caja.php'; ?>
    </div>
    <?php pie_dashboard(); ?>
