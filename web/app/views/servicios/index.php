<?php
/**
 * Vista de app/Controllers/ServiciosController::index().
 *
 * Fase 9 (despliegue): esta pantalla ya es la real (web/servicios.php la
 * puentea directo a este Controlador), así que usa el layout completo del
 * panel (cabecera_dashboard/pie_dashboard), igual que el resto del sitio.
 *
 * Variables disponibles: $usuario, $negocios, $negocioId, $error, $aviso,
 * $convenios, $verId, $verConvenio, $ops.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

const OP_ETIQUETAS_VISTA = [
    'recaudacion'   => ['Pago de cuenta', 'b-salida'],
    'recarga_saldo' => ['Recarga',        'b-entrada'],
    'comision'      => ['Comisión',       'b-entrada'],
    'liquidacion'   => ['Liquidación',    'b-salida'],
];
?>
<?php cabecera_dashboard($usuario, 'ingresos', 'Servicios'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('telefono') ?></span>
                <h2>Servicios (pago de cuentas)</h2>
            </div>
            <?php if (count($negocios) > 1): ?>
            <form method="get" class="filtros">
                <select name="negocio_id" onchange="this.form.submit()">
                    <?php foreach ($negocios as $n): ?>
                        <option value="<?= (int)$n['id'] ?>" <?= $negocioId === (int)$n['id'] ? 'selected' : '' ?>>
                            <?= e($n['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>

        <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($aviso): ?><div class="panel exito"><p><?= e($aviso) ?></p></div><?php endif; ?>

        <div class="panel aviso-compartido">
            <p>
                <strong>Recuerda:</strong> la plata que pagan los clientes por sus cuentas
                <strong>no es venta tuya</strong> (es de la empresa de servicios) y recargar
                saldo <strong>no es un gasto</strong> (es tu propia plata cambiando de bolsillo).
                Tu ingreso real es la <strong>comisión</strong>.
            </p>
        </div>

        <?php if (!$convenios): ?>
            <!-- ================= Sin convenios ================= -->
            <div class="panel">
                <h2 class="panel-titulo">Todavía no hay convenios</h2>
                <p class="texto-ayuda">
                    Un convenio es la empresa con la que recaudas (Mercado Pago, Sencillito, Servipag…).
                    Crea el tuyo para llevar el saldo y las comisiones.
                </p>
            </div>
        <?php else: ?>
            <!-- ================= Saldo de cada convenio ================= -->
            <div class="ingresos-stats stats-compactas">
                <?php foreach ($convenios as $c): $k = $c['calc']; ?>
                    <div class="stat-card <?= $k['bajo'] ? 't-rojo' : 't-verde' ?>">
                        <span class="stat-label">
                            <?= e($c['nombre']) ?>
                            <?php if (!$c['activo']): ?><span class="badge-inactivo">Inactivo</span><?php endif; ?>
                        </span>
                        <span class="stat-valor <?= $k['bajo'] ? 'valor-rojo' : '' ?>">
                            <?= clp($k['saldo']) ?: '$0' ?>
                        </span>
                        <span class="stat-comp texto-tenue">
                            <?= $k['es_prepago'] ? 'saldo estimado disponible' : 'deuda estimada con el convenio' ?>
                        </span>
                        <?php if ($k['bajo']): ?>
                            <span class="stat-comp valor-rojo">
                                ⚠ Bajo el mínimo de <?= clp($k['minimo']) ?> · recarga pronto
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($verConvenio): $k = $verConvenio['calc']; ?>
            <?php if (count($convenios) > 1): ?>
                <div class="panel">
                    <form method="get" class="filtros">
                        <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                        <div class="campo">
                            <label>Ver convenio</label>
                            <select name="ver" onchange="this.form.submit()">
                                <?php foreach ($convenios as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= $verId === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= e($c['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- ================= Resumen del convenio ================= -->
            <div class="panel">
                <h2 class="panel-titulo">
                    <?= e($verConvenio['nombre']) ?>
                    <span class="texto-tenue">· <?= $k['es_prepago'] ? 'prepago' : 'postpago' ?></span>
                </h2>
                <table class="tabla-corte">
                    <tr><td>Recaudado de clientes <small class="texto-tenue">(no es venta tuya)</small></td>
                        <td><?= clp($k['totales']['recaudacion']) ?: '$0' ?>
                            <span class="texto-tenue">· <?= (int)$k['conteos']['recaudacion'] ?> cuenta(s)</span></td></tr>
                    <?php if ($k['es_prepago']): ?>
                        <tr><td>Recargas de saldo <small class="texto-tenue">(no es gasto)</small></td>
                            <td>+ <?= clp($k['totales']['recarga_saldo']) ?: '$0' ?></td></tr>
                    <?php else: ?>
                        <tr><td>Liquidado al convenio</td>
                            <td>− <?= clp($k['totales']['liquidacion']) ?: '$0' ?></td></tr>
                    <?php endif; ?>
                    <tr><td><strong>Comisiones ganadas</strong> <small class="texto-tenue">(tu ingreso real)</small></td>
                        <td><strong class="valor-verde"><?= clp($k['totales']['comision']) ?: '$0' ?></strong></td></tr>
                    <?php if (abs($k['ajuste']) > 0.001): ?>
                        <tr><td>Ajuste manual</td><td><?= clp($k['ajuste']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="fila-total">
                        <td><?= $k['es_prepago'] ? 'Saldo estimado' : 'Deuda estimada' ?></td>
                        <td><?= clp($k['saldo']) ?: '$0' ?></td>
                    </tr>
                </table>
                <p class="texto-ayuda">
                    Es un <strong>cálculo nuestro</strong>, no un dato en línea del proveedor.
                    Si no calza con el portal, usa "Cuadrar con el portal" más abajo.
                </p>
            </div>

            <!-- ================= Registrar operación ================= -->
            <div class="panel">
                <h2 class="panel-titulo">Registrar operación</h2>
                <form method="post" class="filtros">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="operacion">
                    <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                    <input type="hidden" name="convenio_id" value="<?= (int)$verConvenio['id'] ?>">
                    <div class="campo">
                        <label>Qué pasó</label>
                        <select name="tipo" id="opTipo">
                            <?php if ($k['es_prepago']): ?>
                                <option value="recarga_saldo">Cargué saldo</option>
                            <?php else: ?>
                                <option value="liquidacion">Le pagué al convenio</option>
                            <?php endif; ?>
                            <option value="comision">Me pagaron la comisión</option>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Monto</label>
                        <input type="text" name="monto" inputmode="numeric" required placeholder="100.000">
                    </div>
                    <div class="campo" id="campoOrigen">
                        <label>De dónde salió</label>
                        <select name="origen_fondos">
                            <option value="banco">Del banco (transferencia)</option>
                            <option value="caja">De la caja (sale efectivo del cajón)</option>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Referencia</label>
                        <input type="text" name="referencia" placeholder="N° de operación">
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <button class="btn verde" type="submit">Registrar</button>
                    </div>
                </form>
                <script>
                (function () {
                    var t = document.getElementById('opTipo'), o = document.getElementById('campoOrigen');
                    function p() { o.style.display = (t.value === 'comision') ? 'none' : ''; }
                    t.addEventListener('change', p); p();
                })();
                </script>
            </div>

            <!-- ================= Cuadrar con el portal ================= -->
            <div class="panel">
                <h2 class="panel-titulo">Cuadrar con el portal</h2>
                <p class="texto-ayuda">
                    Si el portal del proveedor muestra un saldo distinto al estimado
                    (<?= clp($k['saldo']) ?: '$0' ?>), escribe el real y se ajusta la diferencia.
                </p>
                <form method="post" class="filtros">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="ajustar">
                    <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                    <input type="hidden" name="convenio_id" value="<?= (int)$verConvenio['id'] ?>">
                    <div class="campo">
                        <label>Saldo que muestra el portal</label>
                        <input type="text" name="saldo_real" inputmode="numeric" required placeholder="85.000">
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <button class="btn" type="submit">Cuadrar</button>
                    </div>
                </form>
            </div>

            <!-- ================= Historial ================= -->
            <div class="panel">
                <h2 class="panel-titulo">Últimas operaciones</h2>
                <?php if (!$ops): ?>
                    <p class="vacio">Todavía no hay movimientos en este convenio.</p>
                <?php else: ?>
                    <div class="tabla-wrap">
                        <table>
                            <thead><tr>
                                <th>Cuándo</th><th>Qué</th><th>Servicio</th><th>Quién</th>
                                <th>Referencia</th><th class="monto-col">Monto</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($ops as $o): ?>
                                <?php
                                $et = OP_ETIQUETAS_VISTA[$o['tipo']] ?? [$o['tipo'], 'b-salida'];
                                $baja = $k['es_prepago'] ? ($o['tipo'] === 'recaudacion')
                                                         : ($o['tipo'] === 'liquidacion');
                                ?>
                                <tr>
                                    <td class="texto-tenue"><?= e(date('d-m-Y H:i', strtotime($o['fecha']))) ?></td>
                                    <td><span class="badge-mov <?= $et[1] ?>"><?= $et[0] ?></span></td>
                                    <td><?= e($o['servicio'] ?: '—') ?></td>
                                    <td class="texto-tenue"><?= e($o['usuario'] ?: '—') ?></td>
                                    <td class="texto-tenue"><?= e($o['referencia'] ?: '—') ?></td>
                                    <td class="monto-col"><?= ($baja ? '− ' : '+ ') . clp($o['monto']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ================= Nuevo convenio ================= -->
        <details class="bloque panel"<?= $convenios ? '' : ' open' ?>>
            <summary><h2 class="panel-titulo">Agregar un convenio</h2></summary>
            <p class="texto-ayuda">
                <strong>Prepago</strong> (como Mercado Pago): cargas saldo por adelantado y cada cuenta
                que cobras lo va descontando. <strong>Postpago</strong>: cobras primero y le
                pagas al convenio después.
            </p>
            <form method="post" class="filtros">
                <?= campo_csrf() ?>
                <input type="hidden" name="accion" value="crear_convenio">
                <div class="campo">
                    <label>Nombre</label>
                    <input type="text" name="nombre" required placeholder="Mercado Pago">
                </div>
                <div class="campo">
                    <label>Modalidad</label>
                    <select name="modalidad">
                        <option value="prepago">Prepago (cargo saldo antes)</option>
                        <option value="postpago">Postpago (pago después)</option>
                    </select>
                </div>
                <div class="campo">
                    <label>Avisarme bajo</label>
                    <input type="text" name="saldo_minimo" inputmode="numeric" placeholder="20.000">
                </div>
                <?php if (count($negocios) > 1): ?>
                <div class="campo">
                    <label>Negocio</label>
                    <select name="negocio_id">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>" <?= $negocioId === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="negocio_id" value="<?= $negocioId ?>">
                <?php endif; ?>
                <div class="campo">
                    <label>&nbsp;</label>
                    <button class="btn verde" type="submit">Crear convenio</button>
                </div>
            </form>
        </details>
    </div>
    <?php pie_dashboard(); ?>
