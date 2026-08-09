<?php
/**
 * Caja: apertura y cierre de turno, arqueo y diferencias.
 *
 * ETAPA 1A (cimientos): marcador de posición. La apertura/cierre real con
 * arqueo llega en el paso 1B.
 *
 * Diseño completo del módulo: web/POS_DISENO.md
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';

$usuario = exigir_permiso('ventas');
$pdo = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

// Turnos abiertos de los negocios que el usuario puede ver (comprobación de
// que las tablas nuevas existen y responden).
$abiertas = [];
if ($idsVisibles) {
    $ph = implode(',', array_fill(0, count($idsVisibles), '?'));
    $st = $pdo->prepare(
        "SELECT s.*, n.nombre AS negocio
           FROM caja_sesiones s
           JOIN negocios n ON n.id = s.negocio_id
          WHERE s.estado = 'abierta' AND s.negocio_id IN ($ph)
          ORDER BY s.abierto_en DESC"
    );
    $st->execute($idsVisibles);
    $abiertas = $st->fetchAll();
}
?>
<?php cabecera_dashboard($usuario, 'ventas', 'Caja'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('caja') ?></span>
                <h2>Caja</h2>
            </div>
            <a class="btn gris" href="pos.php">Volver a Ventas</a>
        </div>

        <div class="panel">
            <h2>En construcción</h2>
            <p>
                Aquí se abrirá y cerrará el turno de caja, con el conteo del
                efectivo y la diferencia (faltante o sobrante).
            </p>
            <p class="vacio">Paso actual: <strong>1A — Cimientos</strong>.</p>
        </div>

        <div class="panel">
            <h2>Turnos abiertos</h2>
            <?php if (!$abiertas): ?>
                <p class="vacio">No hay ninguna caja abierta.</p>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>Negocio</th><th>Caja</th><th>Abierta desde</th><th>Fondo</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($abiertas as $s): ?>
                            <tr>
                                <td><?= h($s['negocio']) ?></td>
                                <td><?= h($s['caja'] ?: '—') ?></td>
                                <td><?= h($s['abierto_en']) ?></td>
                                <td><?= clp($s['fondo_inicial']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php pie_dashboard(); ?>
