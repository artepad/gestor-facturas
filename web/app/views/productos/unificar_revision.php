<?php
/**
 * Vista de app/Controllers/ProductosController::unificar() — paso 2/3 (?id=N).
 * Variables disponibles: $usuario, $unif, $items (grupo => filas), $pendientes, $error.
 *
 * $unif['estado'] puede ser 'revisando' (hay que decidir cada diferencia),
 * 'aplicada' (ya se escribió en el catálogo real) o 'descartada'.
 *
 * Los conflictos de PRECIO y de NOMBRE van en tablas separadas (el precio es
 * el dato más sensible, se resuelve primero) y cada decisión se muestra como
 * dos tarjetas de color fijo por negocio (azul = negocio A, morado = negocio
 * B, los mismos colores que ya usan las tarjetas resumen) para que nunca haga
 * falta leer el texto del botón para saber a quién pertenece cada opción.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

$nomA = $unif['negocio_a_nombre'];
$nomB = $unif['negocio_b_nombre'];
$revisando = $unif['estado'] === 'revisando';

/** Cómo quedó resuelto un ítem, en texto, para cuando ya no hay nada que decidir. */
$textoDecision = function (array $it) use ($nomA, $nomB): string {
    return match ($it['decision']) {
        'a'             => "Se usó el dato de $nomA",
        'b'             => "Se usó el dato de $nomB",
        'personalizado' => 'Se guardó un dato personalizado',
        'distinto'      => 'Son productos distintos: se agregaron los dos',
        default         => '—',
    };
};

/** Clase + texto del "chip" de confirmación de una fila ya resuelta. */
$chipResuelto = function (array $it) use ($textoDecision): array {
    $clase = match ($it['decision']) {
        'a' => 'chip-resuelto-a', 'b' => 'chip-resuelto-b', default => 'chip-resuelto-neutro',
    };
    return [$clase, $textoDecision($it)];
};

/**
 * Tarjetas "Usar A" / "Usar B" + Personalizar para un conflicto de precio o
 * de nombre. $enfasis decide qué dato se muestra grande (el que está en
 * conflicto en ese grupo) y cuál se muestra como referencia, más chico.
 */
$tarjetasDecision = function (array $it, string $enfasis) use ($nomA, $nomB): void {
    $precioA = $it['precio_venta_a'] !== null ? (float)$it['precio_venta_a'] : null;
    $precioB = $it['precio_venta_b'] !== null ? (float)$it['precio_venta_b'] : null;
    ?>
    <form method="post">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="resolver">
        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
        <div class="decision-tarjetas">
            <div class="decision-tarjeta decision-a">
                <div class="decision-tarjeta-neg"><?= h($nomA) ?></div>
                <?php if ($enfasis === 'precio'): ?>
                    <div class="decision-tarjeta-precio"><?= clp($precioA) ?></div>
                    <div class="decision-tarjeta-sub"><?= h($it['nombre_a']) ?></div>
                <?php else: ?>
                    <div class="decision-tarjeta-nombre-grande"><?= h($it['nombre_a']) ?></div>
                    <div class="decision-tarjeta-sub"><?= clp($precioA) ?></div>
                <?php endif; ?>
                <button class="btn-decision btn-decision-a" type="submit" name="decision" value="a">Usar este</button>
            </div>
            <div class="decision-tarjeta decision-b">
                <div class="decision-tarjeta-neg"><?= h($nomB) ?></div>
                <?php if ($enfasis === 'precio'): ?>
                    <div class="decision-tarjeta-precio"><?= clp($precioB) ?></div>
                    <div class="decision-tarjeta-sub"><?= h($it['nombre_b']) ?></div>
                <?php else: ?>
                    <div class="decision-tarjeta-nombre-grande"><?= h($it['nombre_b']) ?></div>
                    <div class="decision-tarjeta-sub"><?= clp($precioB) ?></div>
                <?php endif; ?>
                <button class="btn-decision btn-decision-b" type="submit" name="decision" value="b">Usar este</button>
            </div>
        </div>

        <?php if ($enfasis === 'precio' && $precioA !== null && $precioB !== null): ?>
            <?php
                $diff = abs($precioA - $precioB);
                $menor = min($precioA, $precioB);
                $masCaro = $precioA > $precioB ? $nomA : $nomB;
                $pct = $menor > 0 ? round($diff / $menor * 100) : null;
                $esGrande = $pct === null || $pct >= 25;
            ?>
            <div class="decision-diferencia<?= $esGrande ? ' alerta' : '' ?>">
                <?= $esGrande ? icono('oferta', 'ic-chico') : '' ?>
                Diferencia: <strong><?= clp($diff) ?></strong>
                <?= $pct !== null ? "({$pct}% más caro en " . h($masCaro) . ")" : '(no se puede calcular el % — un precio viene en $0)' ?>
                <?= $esGrande ? '· revisa con cuidado' : '' ?>
            </div>
        <?php endif; ?>

        <button class="btn-personalizar-toggle" type="button"
                onclick="this.closest('form').querySelector('.resolver-personalizado').style.display='flex'; this.style.display='none'">
            <?= icono('lapiz', 'ic-chico') ?> Personalizar
        </button>
        <div class="resolver-personalizado" style="display:none">
            <input type="text" name="nombre_personalizado" placeholder="Nombre"
                   value="<?= h($it['nombre_sugerido'] ?: $it['nombre_a']) ?>">
            <?php if ($enfasis === 'precio'): ?>
                <input type="text" name="precio_personalizado" placeholder="Precio de venta"
                       value="<?= $precioA !== null ? number_format($precioA, 0, ',', '.') : '' ?>">
            <?php endif; ?>
            <button class="btn sm" type="submit" name="decision" value="personalizado">Guardar</button>
        </div>
    </form>
    <?php
};

/**
 * Lista de conflictos de un grupo, con límite de tarjetas por pantalla: con
 * catálogos reales puede haber miles de conflictos, y mostrarlos todos de
 * una haría la página enorme y lenta — justo lo contrario de lo que se busca
 * al separar las tablas. Se muestran como máximo $CAP tarjetas pendientes a
 * la vez (se resuelven, se recarga y aparecen las siguientes); lo ya
 * resuelto se resume como un conteo cuando son muchos, en vez de listar cada
 * chip uno por uno.
 */
$CAP_CONFLICTOS = 60;
$listaConflictos = function (array $items, string $enfasis) use ($chipResuelto, $tarjetasDecision, $revisando, $CAP_CONFLICTOS): void {
    $pendientes = array_values(array_filter($items, fn($it) => !$it['resuelto']));
    $resueltos  = array_values(array_filter($items, fn($it) => (bool)$it['resuelto']));
    ?>
    <div class="conflicto-lista">
        <?php if ($revisando): foreach (array_slice($pendientes, 0, $CAP_CONFLICTOS) as $it): ?>
            <div class="conflicto-fila">
                <div class="conflicto-codigo">Código <strong><?= h($it['codigo_a']) ?></strong></div>
                <?php $tarjetasDecision($it, $enfasis); ?>
            </div>
        <?php endforeach; endif; ?>
        <?php if ($revisando && count($pendientes) > $CAP_CONFLICTOS): ?>
            <p class="texto-ayuda">
                Mostrando <?= $CAP_CONFLICTOS ?> de <?= count($pendientes) ?> pendientes. Resuelve estos y
                recarga la página para que aparezcan los siguientes — así la pantalla no se vuelve eterna.
            </p>
        <?php endif; ?>

        <?php if ($resueltos): ?>
            <?php if (count($resueltos) <= 30): ?>
                <?php foreach ($resueltos as $it): [$clase, $texto] = $chipResuelto($it); ?>
                    <div class="conflicto-fila conflicto-fila-resuelta">
                        <div class="conflicto-codigo">Código <?= h($it['codigo_a']) ?></div>
                        <div class="chip-resuelto <?= $clase ?>">✓ <?= h($texto) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="texto-tenue"><?= count($resueltos) ?> ya resueltos (no se listan uno por uno para no alargar la página).</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
};
?>
<?php cabecera_dashboard($usuario, 'productos', 'Unificar catálogos'); ?>
    <div class="contenido">
        <div class="cab-acciones">
            <h2>Unificar: <?= h($nomA) ?> + <?= h($nomB) ?></h2>
            <a class="btn gris" href="herramienta_unificar_productos.php">Volver</a>
        </div>

        <?php if ($error): ?>
            <div class="aviso-flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($unif['estado'] === 'aplicada'): ?>
            <div class="aviso-flash flash-ok">
                <strong>Unificación aplicada</strong> el <?= h(date('d-m-Y H:i', strtotime($unif['aplicado_en']))) ?>
                <?php if ($unif['aplicado_por_nombre']): ?>por <?= h($unif['aplicado_por_nombre']) ?><?php endif; ?>:
                <?= (int)$unif['total_nuevos'] ?> producto(s) nuevo(s), <?= (int)$unif['total_actualizados'] ?> actualizado(s)
                en el catálogo compartido.
                <a href="producto_exportar.php" style="white-space:nowrap">Descargar catálogo completo (Excel)</a>
            </div>
            <p class="texto-ayuda">
                ¿Estás probando esto en el ambiente local y después tienes que llevarlo a producción?
                Descarga el catálogo completo de arriba y súbelo tal cual en
                <a href="herramienta_productos.php">Base de Datos de Productos → Cargar / actualizar</a>
                del otro ambiente — no hace falta repetir ninguna decisión.
            </p>
        <?php elseif ($unif['estado'] === 'descartada'): ?>
            <div class="aviso-flash flash-aviso">Esta unificación fue descartada: no se aplicó ningún cambio al catálogo.</div>
        <?php else: ?>
            <p class="texto-ayuda">
                Comparando el Excel de <strong><?= h($nomA) ?></strong> (<?= h($unif['archivo_a_nombre']) ?>)
                con el de <strong><?= h($nomB) ?></strong> (<?= h($unif['archivo_b_nombre']) ?>).
                Resuelve cada diferencia de abajo; el catálogo real no cambia hasta que aprietes "Aplicar unificación".
            </p>
        <?php endif; ?>

        <div class="ingresos-stats">
            <a href="#g-igual" class="stat-card stat-link t-verde">
                <span class="stat-label">Coinciden sin problema</span>
                <span class="stat-valor"><?= (int)$unif['total_igual'] ?></span>
            </a>
            <a href="#g-conflicto-precio" class="stat-card stat-link t-naranjo">
                <span class="stat-label">Conflicto de precio</span>
                <span class="stat-valor"><?= (int)$unif['total_conflicto_precio'] ?></span>
            </a>
            <a href="#g-conflicto-nombre" class="stat-card stat-link t-amarillo">
                <span class="stat-label">Conflicto de nombre</span>
                <span class="stat-valor"><?= (int)$unif['total_conflicto_nombre'] ?></span>
            </a>
            <a href="#g-solo-a" class="stat-card stat-link t-azul">
                <span class="stat-label">Solo en <?= h($nomA) ?></span>
                <span class="stat-valor"><?= (int)$unif['total_solo_a'] ?></span>
            </a>
            <a href="#g-solo-b" class="stat-card stat-link t-morado">
                <span class="stat-label">Solo en <?= h($nomB) ?></span>
                <span class="stat-valor"><?= (int)$unif['total_solo_b'] ?></span>
            </a>
            <a href="#g-revisar" class="stat-card stat-link t-rojo">
                <span class="stat-label">Para revisar</span>
                <span class="stat-valor"><?= (int)$unif['total_revisar'] ?></span>
            </a>
        </div>

        <?php if ($items['conflicto_precio']): ?>
        <details class="panel acordeon acordeon-conflicto" id="g-conflicto-precio" open>
            <summary class="acordeon-titulo">
                <span>Conflicto de precio (<?= count($items['conflicto_precio']) ?>)</span>
                <span class="acordeon-chevron"><?= icono('chevron') ?></span>
            </summary>
            <p class="texto-ayuda">El mismo código de barras tiene un precio de venta distinto en cada Excel
                (aunque el nombre coincida o no). Elige con calma cuál precio dejar — es el dato más sensible.</p>
            <?php $listaConflictos($items['conflicto_precio'], 'precio'); ?>
        </details>
        <?php endif; ?>

        <?php if ($items['conflicto_nombre']): ?>
        <details class="panel acordeon acordeon-conflicto" id="g-conflicto-nombre" open>
            <summary class="acordeon-titulo">
                <span>Conflicto de nombre (<?= count($items['conflicto_nombre']) ?>)</span>
                <span class="acordeon-chevron"><?= icono('chevron') ?></span>
            </summary>
            <p class="texto-ayuda">El precio ya coincide en los dos negocios; solo cambia cómo está escrito el
                nombre. Menor riesgo que un conflicto de precio.</p>
            <?php $listaConflictos($items['conflicto_nombre'], 'nombre'); ?>
        </details>
        <?php endif; ?>

        <?php if ($items['revisar']): ?>
        <div class="panel" id="g-revisar">
            <h2 class="panel-titulo">Para revisar (mismo nombre, códigos distintos)</h2>
            <p class="texto-ayuda">Parecen el mismo producto pero con un código de barras distinto en cada Excel.
                Confirma si son el mismo producto (y cuál dato dejar) o si en realidad son dos productos distintos.</p>
            <div class="tabla-wrap">
                <table>
                    <thead><tr>
                        <th>Código <?= h($nomA) ?></th><th><?= h($nomA) ?></th>
                        <th>Código <?= h($nomB) ?></th><th><?= h($nomB) ?></th><th>Decisión</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($items['revisar'] as $it): ?>
                        <tr>
                            <td><?= h($it['codigo_a']) ?></td>
                            <td><?= h($it['nombre_a']) ?><br><span class="texto-tenue"><?= clp($it['precio_venta_a']) ?></span></td>
                            <td><?= h($it['codigo_b']) ?></td>
                            <td><?= h($it['nombre_b']) ?><br><span class="texto-tenue"><?= clp($it['precio_venta_b']) ?></span></td>
                            <td>
                                <?php if ($it['resuelto']): ?>
                                    <span class="texto-tenue"><?= h($textoDecision($it)) ?></span>
                                <?php elseif ($revisando): ?>
                                    <form method="post" class="resolver-botones">
                                        <?= campo_csrf() ?>
                                        <input type="hidden" name="accion" value="resolver">
                                        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                        <button class="btn sm" type="submit" name="decision" value="a">Mismo, usar <?= h($nomA) ?></button>
                                        <button class="btn sm" type="submit" name="decision" value="b">Mismo, usar <?= h($nomB) ?></button>
                                        <button class="btn sm gris" type="submit" name="decision" value="distinto">Son distintos</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Las que no necesitan decisión se muestran plegadas, solo para revisar
        // que todo esté bien — no hay nada que hacer en estas tres secciones.
        $seccionesSinDecision = [
            'g-igual'  => ['Coinciden sin problema', $items['igual']],
            'g-solo-a' => ["Solo en $nomA", $items['solo_a']],
            'g-solo-b' => ["Solo en $nomB", $items['solo_b']],
        ];
        foreach ($seccionesSinDecision as $anchor => [$titulo, $filas]):
            if (!$filas) continue;
        ?>
        <details class="panel acordeon" id="<?= $anchor ?>">
            <summary class="acordeon-titulo">
                <span><?= h($titulo) ?> (<?= count($filas) ?>)</span>
                <span class="acordeon-chevron"><?= icono('chevron') ?></span>
            </summary>
            <div class="tabla-wrap">
                <table>
                    <thead><tr><th>Código</th><th>Producto</th><th class="col-num">Precio de venta</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($filas, 0, 150) as $it): ?>
                        <tr>
                            <td><?= h($it['codigo_final']) ?></td>
                            <td><?= h($it['nombre_final']) ?></td>
                            <td class="col-num"><?= clp($it['precio_venta_final']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($filas) > 150): ?>
                <p class="texto-tenue" style="margin-top:10px">Mostrando los primeros 150 de <?= count($filas) ?>.</p>
            <?php endif; ?>
        </details>
        <?php endforeach; ?>

        <?php if ($revisando): ?>
        <div class="panel <?= $pendientes > 0 ? '' : 'zona-peligro' ?>" style="margin-top:20px">
            <h2 class="panel-titulo">Aplicar la unificación</h2>
            <?php if ($pendientes > 0): ?>
                <p class="texto-ayuda">Todavía hay <strong><?= $pendientes ?></strong> producto(s) sin resolver
                    (en "Conflicto de precio", "Conflicto de nombre" o "Para revisar" arriba). Decide cada uno antes de aplicar.</p>
            <?php else: ?>
                <p class="texto-ayuda">Ya resolviste todo. Al aplicar, estos cambios se escriben en el catálogo
                    real (compartido por los dos negocios) de una sola vez. No borra productos que no estén en
                    ninguno de los dos Excel.</p>
            <?php endif; ?>
            <div class="resolver-botones">
                <form method="post" onsubmit="return confirm('¿Aplicar esta unificación al catálogo real? No se puede deshacer con un clic.')">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="aplicar">
                    <button class="btn" type="submit" <?= $pendientes > 0 ? 'disabled' : '' ?>>Aplicar unificación</button>
                </form>
                <form method="post" onsubmit="return confirm('¿Descartar esta unificación? No se aplicará ningún cambio.')">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="descartar">
                    <button class="btn peligro" type="submit">Descartar</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php pie_dashboard(); ?>
