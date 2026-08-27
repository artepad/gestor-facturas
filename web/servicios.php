<?php
/**
 * Servicios / Recaudación: el saldo de cada convenio (Klap y otros), las
 * recargas, las liquidaciones y las comisiones ganadas.
 *
 * Solo admin: es información financiera del dueño, igual que Ingresos y
 * Gastos. Las vendedoras sí registran los pagos de cuentas desde el POS (F9),
 * pero no manejan el saldo ni ven las comisiones.
 *
 * Recordatorio contable (POS_DISENO.md 7.7):
 *   - la plata recaudada NO es venta del negocio
 *   - recargar saldo NO es un gasto
 *   - el único ingreso real es la comisión
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/pos.php';
require __DIR__ . '/lib/auditoria.php';

$usuario  = exigir_permiso('ingresos');    // 'ingresos' = solo administrador
$pdo      = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

$negocioId = isset($_GET['negocio_id']) ? (int)$_GET['negocio_id'] : ($idsVisibles[0] ?? 0);
if (!in_array($negocioId, $idsVisibles, true)) $negocioId = $idsVisibles[0] ?? 0;

$error = '';
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'crear_convenio') {
            $nombre = trim($_POST['nombre'] ?? '');
            $modal  = ($_POST['modalidad'] ?? 'prepago') === 'postpago' ? 'postpago' : 'prepago';
            $minimo = parsear_monto($_POST['saldo_minimo'] ?? '');
            $neg    = (int)($_POST['negocio_id'] ?? 0);
            if ($nombre === '')                      throw new RuntimeException('Ponle un nombre al convenio.');
            if (!in_array($neg, $idsVisibles, true)) throw new RuntimeException('Negocio inválido.');
            $st = $pdo->prepare(
                "INSERT INTO convenios (negocio_id, nombre, modalidad, saldo_minimo) VALUES (?,?,?,?)"
            );
            try { $st->execute([$neg, $nombre, $modal, $minimo]); }
            catch (PDOException $e) {
                if ($e->getCode() === '23000') throw new RuntimeException('Ya existe un convenio con ese nombre en este negocio.');
                throw $e;
            }
            registrar_auditoria($pdo, $usuario, 'ventas', 'crear_convenio', null,
                'Creó el convenio ' . $nombre . ' (' . $modal . ')', null,
                'convenio', (int)$pdo->lastInsertId());
            header('Location: servicios.php?negocio_id=' . $neg . '&msg=convenio');
            exit;
        }

        if ($accion === 'operacion') {
            $cid   = (int)($_POST['convenio_id'] ?? 0);
            $tipo  = $_POST['tipo'] ?? '';
            $monto = parsear_monto($_POST['monto'] ?? '');
            if ($monto === null) throw new RuntimeException('Escribe un monto válido.');
            // Si sale de la caja hace falta un turno abierto del propio usuario.
            $ses = ($_POST['origen_fondos'] ?? 'banco') === 'caja'
                 ? sesion_abierta_de($pdo, (int)$usuario['id']) : null;
            $r = registrar_operacion_convenio(
                $pdo, $usuario, $cid, $tipo, $monto,
                $_POST['origen_fondos'] ?? 'banco',
                trim($_POST['referencia'] ?? ''), trim($_POST['nota'] ?? ''), $ses
            );
            $etiquetas = ['recarga_saldo' => 'Recargó saldo de', 'liquidacion' => 'Liquidó a',
                          'comision' => 'Registró comisión de'];
            registrar_auditoria($pdo, $usuario, 'ventas', $tipo, null,
                ($etiquetas[$tipo] ?? $tipo) . ' ' . $r['convenio']['nombre'] . ' por ' . clp($monto),
                null, 'convenio', $cid);
            header('Location: servicios.php?negocio_id=' . $negocioId . '&msg=operacion');
            exit;
        }

        if ($accion === 'ajustar') {
            $cid = (int)($_POST['convenio_id'] ?? 0);
            $real = parsear_monto($_POST['saldo_real'] ?? '');
            if ($real === null) throw new RuntimeException('Escribe el saldo que muestra el portal.');
            $st = $pdo->prepare("SELECT * FROM convenios WHERE id = ? AND negocio_id = ?");
            $st->execute([$cid, $negocioId]);
            $c = $st->fetch();
            if (!$c) throw new RuntimeException('Convenio inválido.');
            // El ajuste es la diferencia entre lo que dice el portal y lo calculado.
            $calc = saldo_convenio($pdo, $c);
            $nuevoAjuste = round((float)$c['saldo_ajuste'] + ($real - $calc['saldo']), 2);
            $pdo->prepare("UPDATE convenios SET saldo_ajuste = ? WHERE id = ?")
                ->execute([$nuevoAjuste, $cid]);
            registrar_auditoria($pdo, $usuario, 'ventas', 'ajustar_convenio', null,
                'Cuadró ' . $c['nombre'] . ': de ' . clp($calc['saldo']) . ' a ' . clp($real),
                null, 'convenio', $cid);
            header('Location: servicios.php?negocio_id=' . $negocioId . '&msg=ajuste');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

switch ($_GET['msg'] ?? '') {
    case 'convenio':  $aviso = 'Convenio creado.'; break;
    case 'operacion': $aviso = 'Operación registrada. El saldo estimado ya está actualizado.'; break;
    case 'ajuste':    $aviso = 'Saldo cuadrado con el portal del proveedor.'; break;
}

$convenios = convenios_con_saldo($pdo, $negocioId, false);
$verId = isset($_GET['ver']) ? (int)$_GET['ver'] : (int)($convenios[0]['id'] ?? 0);
$verConvenio = null;
foreach ($convenios as $c) if ((int)$c['id'] === $verId) $verConvenio = $c;
$ops = $verConvenio ? operaciones_convenio($pdo, (int)$verConvenio['id']) : [];

const OP_ETIQUETAS = [
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
                            <?= h($n['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>

        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <?php if ($aviso): ?><div class="panel exito"><p><?= h($aviso) ?></p></div><?php endif; ?>

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
                    Un convenio es la empresa con la que recaudas (Klap, Sencillito, Servipag…).
                    Crea el tuyo para llevar el saldo y las comisiones.
                </p>
            </div>
        <?php else: ?>
            <!-- ================= Saldo de cada convenio ================= -->
            <div class="ingresos-stats stats-compactas">
                <?php foreach ($convenios as $c): $k = $c['calc']; ?>
                    <div class="stat-card <?= $k['bajo'] ? 't-rojo' : 't-verde' ?>">
                        <span class="stat-label">
                            <?= h($c['nombre']) ?>
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
                                        <?= h($c['nombre']) ?>
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
                    <?= h($verConvenio['nombre']) ?>
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
                    <input type="hidden" name="accion" value="operacion">
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
                // La comisión siempre llega del proveedor: no sale de la caja.
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
                    <input type="hidden" name="accion" value="ajustar">
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
                                $et = OP_ETIQUETAS[$o['tipo']] ?? [$o['tipo'], 'b-salida'];
                                // En prepago la recaudación baja el saldo; recarga y comisión lo suben.
                                $baja = $k['es_prepago'] ? ($o['tipo'] === 'recaudacion')
                                                         : ($o['tipo'] === 'liquidacion');
                                ?>
                                <tr>
                                    <td class="texto-tenue"><?= h(date('d-m-Y H:i', strtotime($o['fecha']))) ?></td>
                                    <td><span class="badge-mov <?= $et[1] ?>"><?= $et[0] ?></span></td>
                                    <td><?= h($o['servicio'] ?: '—') ?></td>
                                    <td class="texto-tenue"><?= h($o['usuario'] ?: '—') ?></td>
                                    <td class="texto-tenue"><?= h($o['referencia'] ?: '—') ?></td>
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
                <strong>Prepago</strong> (como Klap): cargas saldo por adelantado y cada cuenta
                que cobras lo va descontando. <strong>Postpago</strong>: cobras primero y le
                pagas al convenio después.
            </p>
            <form method="post" class="filtros">
                <input type="hidden" name="accion" value="crear_convenio">
                <div class="campo">
                    <label>Nombre</label>
                    <input type="text" name="nombre" required placeholder="Klap">
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
                                <?= h($n['nombre']) ?>
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
