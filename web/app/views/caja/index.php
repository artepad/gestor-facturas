<?php
/**
 * Vista de app/Controllers/CajaController::index().
 *
 * Variables disponibles (ver CajaController): $usuario, $negocios, $esAdmin,
 * $sesion, $error, $aviso, $corteNuevo, $resumen, $movimientos, $recaudado,
 * $enEspera, $otrasAbiertas, $historial, $avisoLarga, $baseAntiguo.
 *
 * Reusa clp()/icono() de lib/ui.php (formato y SVG, no HTML completo) para
 * que se vea identico al original; NO reusa cabecera_dashboard()/
 * pie_dashboard() porque esas arman el sidebar con links relativos al sitio
 * viejo ("pos.php", "home.php"...) que romperian desde esta ruta nueva
 * (/caja/). Mientras conviven las dos estructuras, esta pantalla lleva un
 * encabezado propio, simple, con un link de vuelta. El sidebar completo
 * vuelve cuando el corte este terminado y el dominio apunte a public/
 * (Fase 9) — no antes.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
$baseNuevo = \App\Core\Request::base();   // donde vive esta pantalla nueva ("/minimark/public" en local)
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Caja (vista previa) · Minimark</title>
<link rel="stylesheet" href="<?= e($baseAntiguo) ?>/assets/estilo.css">
</head>
<body>
<div style="background:#22313f;color:#fff;padding:10px 20px;font-size:14px;display:flex;justify-content:space-between;align-items:center">
    <span>🧪 Vista previa de la migración (Fase 6, corte 1) — misma lógica que <code>caja.php</code>, interfaz todavía sin el menú lateral.</span>
    <a href="<?= e($baseAntiguo) ?>/caja.php" style="color:#9fd3ff">Volver a la pantalla actual</a>
</div>
<div class="contenido" style="max-width:1100px;margin:0 auto;padding:20px">
    <div class="cab-modulo modulo-turquesa">
        <div class="cab-modulo-tit">
            <span class="modulo-badge"><?= icono('caja') ?></span>
            <h2>Caja</h2>
        </div>
    </div>

    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($aviso): ?>
        <div class="panel exito">
            <p>
                <?= e($aviso) ?>
                <?php if ($corteNuevo): ?>
                    <a href="<?= e($baseAntiguo) ?>/corte.php?id=<?= $corteNuevo ?>">Ver el corte</a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (!$sesion): ?>
        <div class="panel">
            <h2>Abrir caja</h2>
            <p class="ec-intro">
                Declara con cuánto efectivo parte la caja (el sencillo para dar vuelto).
                Al cerrar el turno se comparará con lo que cuentes.
            </p>
            <?php if (!$negocios): ?>
                <p class="vacio">No tienes negocios asignados.</p>
            <?php else: ?>
            <form method="post" action="<?= e($baseNuevo) ?>/caja/abrir" class="filtros">
                <?= campo_csrf() ?>
                <?php if (count($negocios) > 1): ?>
                <div class="campo">
                    <label>Negocio</label>
                    <select name="negocio_id">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"><?= e($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="negocio_id" value="<?= (int)$negocios[0]['id'] ?>">
                <?php endif; ?>
                <div class="campo">
                    <label>Nombre de la caja</label>
                    <input type="text" name="caja" placeholder="Caja Principal" style="min-width:180px">
                </div>
                <div class="campo">
                    <label>Fondo inicial</label>
                    <div class="campo-peso">
                        <span class="campo-peso-signo">$</span>
                        <input type="text" name="fondo_inicial" inputmode="numeric">
                    </div>
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn verde" type="submit">Abrir caja</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="panel">
            <h2>Turno abierto · <?= e($sesion['caja'] ?: 'Caja') ?> · <?= e($sesion['negocio_nombre']) ?></h2>
            <p class="ec-intro">Abierta desde <?= e($sesion['abierto_en']) ?>.</p>
            <?php if ($avisoLarga): ?>
                <p class="aviso-caja-larga"><?= icono('reloj') ?> <?= e($avisoLarga) ?></p>
            <?php endif; ?>

            <div class="ingresos-stats stats-compactas">
                <div class="stat-card">
                    <span class="stat-label">Fondo inicial</span>
                    <span class="stat-valor"><?= clp($resumen['fondo_inicial']) ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Ventas del turno</span>
                    <span class="stat-valor"><?= clp($resumen['ventas_total']) ?: '$0' ?></span>
                    <span class="stat-comp texto-tenue"><?= (int)$resumen['numero_ventas'] ?> venta(s)</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Ventas en efectivo</span>
                    <span class="stat-valor valor-verde"><?= clp($resumen['ventas_efectivo']) ?: '$0' ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Pagos de cuentas</span>
                    <span class="stat-valor"><?= clp($resumen['recaudado_efectivo']) ?: '$0' ?></span>
                    <span class="stat-comp texto-tenue">en efectivo, no es venta</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Entradas de efectivo</span>
                    <span class="stat-valor"><?= clp($resumen['entradas']) ?: '$0' ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Salidas de efectivo</span>
                    <span class="stat-valor valor-rojo"><?= $resumen['salidas'] > 0 ? '− ' . clp($resumen['salidas']) : '$0' ?></span>
                </div>
                <div class="stat-card stat-destacado t-verde">
                    <span class="stat-label">Efectivo esperado en el cajón</span>
                    <span class="stat-valor"><?= clp($resumen['efectivo_esperado']) ?: '$0' ?></span>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>Entradas y salidas de efectivo</h2>
            <p class="ec-intro">
                Una <strong>salida</strong> es plata que sale del cajón. Una <strong>entrada</strong>
                es plata que se agrega (ej. el abono de un fiado pagado en efectivo).
            </p>
            <form method="post" action="<?= e($baseNuevo) ?>/caja/movimiento" class="filtros">
                <?= campo_csrf() ?>
                <div class="campo">
                    <label>Tipo</label>
                    <select name="tipo" id="movTipo">
                        <option value="salida">Salida (sale del cajón)</option>
                        <option value="entrada">Entrada (entra al cajón)</option>
                    </select>
                </div>
                <div class="campo">
                    <label>Monto</label>
                    <div class="campo-peso">
                        <span class="campo-peso-signo">$</span>
                        <input type="text" name="monto" inputmode="numeric" required>
                    </div>
                </div>
                <div class="campo" style="flex:1 1 220px">
                    <label id="movMotivoLbl">Motivo *</label>
                    <input type="text" name="motivo" id="movMotivo" required style="width:100%">
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn" type="submit">Registrar</button>
                </div>
            </form>
            <script>
            (function () {
                var tipo = document.getElementById('movTipo'),
                    campo = document.getElementById('movMotivo'),
                    lbl = document.getElementById('movMotivoLbl');
                if (!tipo || !campo || !lbl) return;
                tipo.addEventListener('change', function () {
                    var salida = tipo.value === 'salida';
                    campo.required = salida;
                    lbl.textContent = salida ? 'Motivo *' : 'Motivo (opcional)';
                });
            })();
            </script>

            <?php if ($movimientos): ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr><th>Hora</th><th>Tipo</th><th>Motivo</th><th>Quién</th><th class="monto-col">Monto</th></tr></thead>
                        <tbody>
                        <?php foreach ($movimientos as $m): ?>
                            <tr>
                                <td><?= e(substr((string)$m['creado_en'], 11, 5)) ?></td>
                                <td><span class="badge-mov <?= $m['tipo'] === 'entrada' ? 'b-entrada' : 'b-salida' ?>"><?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Salida' ?></span></td>
                                <td><?= e($m['motivo'] ?: '—') ?></td>
                                <td><?= e($m['usuario'] ?: '—') ?></td>
                                <td class="monto-col"><?= ($m['tipo'] === 'salida' ? '− ' : '+ ') . clp($m['monto']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="vacio">Sin movimientos de efectivo en este turno.</p>
            <?php endif; ?>
        </div>

        <?php if ($recaudado): ?>
        <div class="panel">
            <h2>Pagos de cuentas (Klap y otros convenios)</h2>
            <p class="ec-intro">Estos <strong>no son ventas tuyas</strong>; se registran acá porque el efectivo entra al cajón.</p>
            <div class="tabla-wrap">
                <table>
                    <thead><tr><th>Hora</th><th>Servicio</th><th>Convenio</th><th>Pagó con</th><th>Comprobante</th><th class="monto-col">Monto</th></tr></thead>
                    <tbody>
                    <?php foreach ($recaudado as $o): ?>
                        <tr>
                            <td><?= e(substr((string)$o['fecha'], 11, 5)) ?></td>
                            <td><?= e($o['servicio']) ?></td>
                            <td><?= e($o['convenio']) ?></td>
                            <td><span class="badge-mov <?= $o['medio_pago'] === 'efectivo' ? 'b-entrada' : 'b-salida' ?>"><?= e(ucfirst((string)$o['medio_pago'])) ?></span></td>
                            <td><?= e($o['referencia'] ?: '—') ?></td>
                            <td class="monto-col"><?= clp($o['monto']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel">
            <h2>Cerrar caja (arqueo)</h2>
            <?php if ($enEspera > 0): ?>
                <div class="error">
                    Tienes <?= (int)$enEspera ?> venta<?= $enEspera > 1 ? 's' : '' ?> en espera sin terminar.
                    Cóbralas o descártalas antes de cerrar la caja.
                </div>
            <?php else: ?>
                <p class="ec-intro">
                    Cuenta el efectivo del cajón y escríbelo. Se comparará con los
                    <strong><?= clp($resumen['efectivo_esperado']) ?></strong> que espera.
                </p>
                <form method="post" action="<?= e($baseNuevo) ?>/caja/cerrar" class="filtros"
                      onsubmit="return confirm('¿Cerrar la caja? No podrás seguir vendiendo en este turno.')">
                    <?= campo_csrf() ?>
                    <div class="campo">
                        <label>Efectivo contado</label>
                        <input type="text" name="efectivo_contado" inputmode="numeric" required autofocus>
                    </div>
                    <div class="campo" style="flex:1 1 220px">
                        <label>Nota (opcional)</label>
                        <input type="text" name="nota" placeholder="Observaciones del turno" style="width:100%">
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <button class="btn rojo" type="submit">Cerrar caja</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($esAdmin && count($otrasAbiertas) > ($sesion ? 1 : 0)): ?>
        <div class="panel">
            <h2>Otras cajas abiertas</h2>
            <div class="tabla-wrap">
                <table>
                    <thead><tr><th>Negocio</th><th>Caja</th><th>Cajero</th><th>Abierta desde</th><th class="monto-col">Fondo</th></tr></thead>
                    <tbody>
                    <?php foreach ($otrasAbiertas as $s): ?>
                        <?php if ($sesion && (int)$s['id'] === (int)$sesion['id']) continue; ?>
                        <tr>
                            <td><?= e($s['negocio_nombre']) ?></td>
                            <td><?= e($s['caja'] ?: '—') ?></td>
                            <td><?= e($s['cajero'] ?: '—') ?></td>
                            <td><?= e($s['abierto_en']) ?></td>
                            <td class="monto-col"><?= clp($s['fondo_inicial']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Últimos turnos cerrados</h2>
        <?php if (!$historial): ?>
            <p class="vacio">Aún no hay turnos cerrados.</p>
        <?php else: ?>
            <div class="tabla-wrap">
                <table>
                    <thead><tr>
                        <th>Cerrado</th><th>Negocio</th><th>Caja</th><th>Cajero</th>
                        <th class="monto-col">Esperado</th><th class="monto-col">Contado</th>
                        <th class="monto-col">Diferencia</th><th>Corte</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($historial as $s): ?>
                        <?php $d = (float)$s['diferencia']; ?>
                        <tr>
                            <td><?= e($s['cerrado_en']) ?></td>
                            <td><?= e($s['negocio_nombre']) ?></td>
                            <td><?= e($s['caja'] ?: '—') ?></td>
                            <td><?= e($s['cajero'] ?: '—') ?></td>
                            <td class="monto-col"><?= clp($s['efectivo_esperado']) ?></td>
                            <td class="monto-col"><?= clp($s['efectivo_contado']) ?></td>
                            <td class="monto-col">
                                <span class="dif <?= $d < 0 ? 'dif-falta' : ($d > 0 ? 'dif-sobra' : 'dif-ok') ?>">
                                    <?= $d == 0 ? 'Cuadró' : (($d > 0 ? 'Sobra ' : 'Falta ') . clp(abs($d))) ?>
                                </span>
                            </td>
                            <td><?php if ($s['corte_id']): ?><a href="<?= e($baseAntiguo) ?>/corte.php?id=<?= (int)$s['corte_id'] ?>">Ver corte</a><?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
Array.prototype.forEach.call(document.querySelectorAll('input[inputmode=numeric]'), function (el) {
    el.addEventListener('input', function () {
        var v = el.value.replace(/[^0-9.]/g, '');
        if (v !== el.value) el.value = v;
    });
});
</script>
</body>
</html>
