<?php
/**
 * Vista de app/Controllers/PromocionesController::index().
 *
 * Variables disponibles: $usuario, $negocios, $id, $nueva, $error, $promo,
 * $lista, $deptos, $msg, $hoy, $editando, $baseAntiguo, $baseNuevo, $csrfToken.
 *
 * Mismo criterio que app/views/caja/index.php (ver ahí el porqué del
 * encabezado propio en vez de cabecera_dashboard()/pie_dashboard()).
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
require_once dirname(__DIR__, 3) . '/lib/pos.php';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Promociones (vista previa) · Minimark</title>
<link rel="stylesheet" href="<?= e($baseAntiguo) ?>/assets/estilo.css">
</head>
<body>
<div style="background:#22313f;color:#fff;padding:10px 20px;font-size:14px;display:flex;justify-content:space-between;align-items:center">
    <span>🧪 Vista previa de la migración (Fase 6) — misma lógica que <code>promociones.php</code>, interfaz todavía sin el menú lateral.</span>
    <a href="<?= e($baseAntiguo) ?>/promociones.php" style="color:#9fd3ff">Volver a la pantalla actual</a>
</div>
<div class="contenido" style="max-width:1100px;margin:0 auto;padding:20px">
    <div class="cab-modulo modulo-turquesa">
        <div class="cab-modulo-tit">
            <span class="modulo-badge"><?= icono('oferta') ?></span>
            <h2>Promociones</h2>
        </div>
        <div class="cab-botones">
            <a class="btn gris" href="<?= e($baseAntiguo) ?>/herramienta_productos.php">Catálogo</a>
            <?php if (!$editando): ?>
                <a class="btn verde" href="<?= e($baseNuevo) ?>/promociones/?nueva=1">+ Nueva promoción</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg === 'guardada'): ?>
        <div class="panel exito"><p>Promoción guardada. El POS la aplicará sola en la próxima venta.</p></div>
    <?php elseif ($msg === 'eliminada'): ?>
        <div class="panel exito"><p>Promoción eliminada.</p></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($editando): ?>
    <!-- ================= Crear / editar ================= -->
    <div class="panel">
        <h2 class="panel-titulo"><?= $id ? 'Editar promoción' : 'Nueva promoción' ?></h2>
        <p class="texto-ayuda">
            El POS aplica esto <strong>solo</strong> al vender. Si pones "4 por $1.000" y el
            cliente lleva 4, cobra $1.000; si lleva 5, cobra $1.300 (el pack más uno suelto).
        </p>
        <form method="post" action="<?= e($baseNuevo) ?>/promociones/<?= $id ? '?id=' . $id : '' ?>">
            <?= campo_csrf() ?>
            <div class="campo">
                <label>Nombre de la promoción *</label>
                <input type="text" name="nombre" required value="<?= e($promo['nombre']) ?>"
                       placeholder="4 yogures por $1.000">
            </div>
            <div class="campo">
                <label>Tipo *</label>
                <select name="tipo" id="tipoPromo">
                    <?php foreach (\App\Models\Promocion::TIPOS as $k => $t): ?>
                        <option value="<?= $k ?>" <?= $promo['tipo'] === $k ? 'selected' : '' ?>>
                            <?= e($t[0]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="texto-tenue" id="ayudaTipo"></small>
            </div>
            <div class="campo">
                <label>Código del producto</label>
                <input type="text" name="codigo" value="<?= e($promo['codigo'] ?? '') ?>"
                       autocomplete="off" placeholder="7801234567890">
                <small class="texto-tenue">
                    <?php if (!empty($promo['producto_nombre'])): ?>
                        Actualmente: <strong><?= e($promo['producto_nombre']) ?></strong>.
                    <?php endif; ?>
                    Déjalo vacío si la promo es de un departamento completo.
                </small>
            </div>
            <div class="campo">
                <label>…o departamento completo</label>
                <input type="text" name="departamento" list="listaDeptos" autocomplete="off"
                       value="<?= e($promo['departamento'] ?? '') ?>" placeholder="Lácteos">
                <datalist id="listaDeptos">
                    <?php foreach ($deptos as $d): ?><option value="<?= e($d) ?>"></option><?php endforeach; ?>
                </datalist>
            </div>
            <div class="campo" id="campoCantidad">
                <label>Cantidad (la N)</label>
                <input type="text" name="cantidad" inputmode="numeric"
                       value="<?= e($promo['cantidad'] ?? '') ?>" placeholder="4">
            </div>
            <div class="campo">
                <label id="lblValor">Valor *</label>
                <input type="text" name="valor" required inputmode="numeric"
                       value="<?= e($promo['valor'] ?? '') ?>" placeholder="1.000">
            </div>
            <?php if (count($negocios) > 1): ?>
            <div class="campo">
                <label>¿En qué negocio?</label>
                <select name="negocio_id">
                    <option value="">En los dos negocios</option>
                    <?php foreach ($negocios as $n): ?>
                        <option value="<?= (int)$n['id'] ?>"
                            <?= (string)($promo['negocio_id'] ?? '') === (string)$n['id'] ? 'selected' : '' ?>>
                            Solo en <?= e($n['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="campo">
                <label>Desde (opcional)</label>
                <input type="date" name="desde" value="<?= e($promo['desde'] ?? '') ?>">
            </div>
            <div class="campo">
                <label>Hasta (opcional)</label>
                <input type="date" name="hasta" value="<?= e($promo['hasta'] ?? '') ?>">
                <small class="texto-tenue">Sin fechas, la promoción vale hasta que la desactives.</small>
            </div>
            <div class="campo campo-check">
                <label>
                    <input type="checkbox" name="activo" value="1" <?= !empty($promo['activo']) ? 'checked' : '' ?>>
                    Activa
                </label>
            </div>
            <div class="form-acciones">
                <button class="btn" type="submit" name="accion" value="guardar">Guardar</button>
                <a class="btn gris" href="<?= e($baseNuevo) ?>/promociones/">Cancelar</a>
                <?php if ($id): ?>
                    <button class="btn rojo" type="submit" name="accion" value="eliminar"
                            onclick="return confirm('¿Eliminar esta promoción?')">Eliminar</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <script>
    // Ayuda contextual y campos que solo aplican a ciertos tipos.
    (function () {
        var AYUDA = <?= json_encode(array_map(fn($t) => $t[1], \App\Models\Promocion::TIPOS), JSON_UNESCAPED_UNICODE) ?>;
        var LBL = { pack: 'Precio del pack *', nxm: 'Cuántas paga (la M) *',
                    precio: 'Precio especial *', porcentaje: 'Porcentaje de descuento *' };
        var sel = document.getElementById('tipoPromo');
        function pintar() {
            document.getElementById('ayudaTipo').textContent = AYUDA[sel.value] || '';
            document.getElementById('lblValor').textContent = LBL[sel.value] || 'Valor *';
            document.getElementById('campoCantidad').style.display =
                (sel.value === 'pack' || sel.value === 'nxm') ? '' : 'none';
        }
        sel.addEventListener('change', pintar);
        pintar();
    })();
    </script>
    <?php endif; ?>

    <!-- ================= Listado ================= -->
    <div class="panel">
        <h2 class="panel-titulo">Promociones (<?= count($lista) ?>)</h2>
        <?php if (!$lista): ?>
            <p class="vacio">
                Todavía no hay promociones. Crea una y el POS la aplicará sola al vender.
            </p>
        <?php else: ?>
            <div class="tabla-wrap">
                <table>
                    <thead><tr>
                        <th>Promoción</th><th>Oferta</th><th>Aplica a</th>
                        <th>Vigencia</th><th>Dónde</th><th>Estado</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($lista as $p): ?>
                        <?php
                        $vencida = $p['hasta'] && $p['hasta'] < $hoy;
                        $futura  = $p['desde'] && $p['desde'] > $hoy;
                        $vigente = $p['activo'] && !$vencida && !$futura;
                        ?>
                        <tr<?= $vigente ? '' : ' class="fila-inactiva"' ?>>
                            <td><?= e($p['nombre']) ?></td>
                            <td><strong><?= e(\texto_promocion($p)) ?></strong></td>
                            <td>
                                <?php if ($p['producto_nombre']): ?>
                                    <?= e($p['producto_nombre']) ?>
                                    <span class="texto-tenue"><?= e($p['codigo']) ?></span>
                                <?php else: ?>
                                    Depto. <?= e($p['departamento']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="texto-tenue">
                                <?php if (!$p['desde'] && !$p['hasta']): ?>Sin límite
                                <?php else: ?>
                                    <?= $p['desde'] ? e(fecha_dmy($p['desde'])) : '…' ?>
                                    a <?= $p['hasta'] ? e(fecha_dmy($p['hasta'])) : '…' ?>
                                <?php endif; ?>
                            </td>
                            <td class="texto-tenue"><?= e($p['negocio_nombre'] ?: 'Los dos') ?></td>
                            <td>
                                <?php if ($vigente): ?>
                                    <span class="badge-carga b-activa">Vigente</span>
                                <?php elseif (!$p['activo']): ?>
                                    <span class="badge-carga b-reemplazada">Desactivada</span>
                                <?php elseif ($vencida): ?>
                                    <span class="badge-carga b-eliminada">Vencida</span>
                                <?php else: ?>
                                    <span class="badge-carga b-reemplazada">Aún no empieza</span>
                                <?php endif; ?>
                            </td>
                            <td><a class="btn sm" href="<?= e($baseNuevo) ?>/promociones/?id=<?= (int)$p['id'] ?>">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
