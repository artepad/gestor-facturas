<?php
/**
 * Punto de Venta (POS) — pantalla de venta.
 *
 * ETAPA 1A (cimientos): por ahora es solo un marcador de posición que sirve
 * para verificar el permiso, el menú y el acceso por negocio. La pantalla de
 * venta real (escanear → carrito → cobrar) llega en el paso 1C.
 *
 * Diseño completo del módulo: web/POS_DISENO.md
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('ventas');
$negocios = negocios_visibles($usuario);
?>
<?php cabecera_dashboard($usuario, 'ventas', 'Punto de Venta'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('ventas') ?></span>
                <h2>Punto de Venta</h2>
            </div>
            <a class="btn gris" href="caja.php">Caja</a>
        </div>

        <div class="panel">
            <h2>En construcción</h2>
            <p>
                Aquí vivirá la pantalla de venta: escanear el código de barras,
                armar el carrito y cobrar. Se está construyendo por etapas para
                no afectar lo que ya funciona.
            </p>
            <p class="vacio">
                Paso actual: <strong>1A — Cimientos</strong> (base de datos, permisos y menú).
                Este módulo solo lo ve el administrador hasta que esté probado.
            </p>
        </div>

        <div class="panel">
            <h2>Negocios donde podrás vender</h2>
            <?php if (!$negocios): ?>
                <p class="vacio">No tienes negocios asignados.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($negocios as $n): ?>
                        <li><?= h($n['nombre']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php pie_dashboard(); ?>
