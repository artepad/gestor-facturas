<?php
/**
 * Caja: apertura y cierre de turno, movimientos de efectivo y arqueo.
 *
 * ETAPA 1B. El cierre calcula el efectivo esperado y la diferencia. La
 * generación del `corte` al cerrar (que alimenta el módulo Ingresos) llega en
 * el paso 1E.
 *
 * Diseño completo del módulo: web/POS_DISENO.md
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/pos.php';
require __DIR__ . '/lib/auditoria.php';

$usuario = exigir_permiso('ventas');
$pdo = obtener_pdo();
$negocios = negocios_visibles($usuario);
$idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
$esAdmin = ($usuario['rol'] ?? '') === 'admin';

$error = '';
$aviso = '';
$sesion = sesion_abierta_de($pdo, (int)$usuario['id']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'abrir') {
            if ($sesion) {
                throw new RuntimeException('Ya tienes una caja abierta.');
            }
            $negocioId = (int)($_POST['negocio_id'] ?? 0);
            if (!in_array($negocioId, $idsVisibles, true)) {
                throw new RuntimeException('Selecciona un negocio válido.');
            }
            // parsear_monto devuelve null si el texto no es un número válido.
            $fondo = parsear_monto($_POST['fondo_inicial'] ?? '') ?? 0.0;
            if ($fondo < 0) {
                throw new RuntimeException('El fondo inicial no puede ser negativo.');
            }
            $caja = trim($_POST['caja'] ?? '');
            $id = abrir_caja($pdo, $usuario, $negocioId, $caja, $fondo);
            registrar_auditoria($pdo, $usuario, 'ventas', 'abrir_caja', null,
                'Abrió la caja ' . ($caja !== '' ? $caja : '(sin nombre)') .
                ' con fondo ' . clp($fondo));
            header('Location: caja.php');
            exit;
        }

        if ($accion === 'movimiento') {
            if (!$sesion) throw new RuntimeException('No tienes una caja abierta.');
            $tipo = ($_POST['tipo'] ?? '') === 'entrada' ? 'entrada' : 'salida';
            $monto = parsear_monto($_POST['monto'] ?? '');
            if ($monto === null || $monto <= 0) {
                throw new RuntimeException('Indica un monto válido mayor que cero.');
            }
            $motivo = trim($_POST['motivo'] ?? '');
            // El motivo es obligatorio al SACAR plata: una salida sin explicación
            // no le sirve a nadie al revisar el turno. En las entradas es opcional.
            if ($tipo === 'salida' && $motivo === '') {
                throw new RuntimeException('Escribe el motivo de la salida (para qué se sacó la plata).');
            }
            registrar_movimiento_caja($pdo, $usuario, (int)$sesion['id'], $tipo, $monto, $motivo);
            registrar_auditoria($pdo, $usuario, 'ventas', 'movimiento_caja', null,
                ucfirst($tipo) . ' de efectivo por ' . clp($monto) .
                ($motivo !== '' ? " ($motivo)" : ''));
            header('Location: caja.php');
            exit;
        }

        if ($accion === 'cerrar') {
            if (!$sesion) throw new RuntimeException('No tienes una caja abierta.');
            $contado = parsear_monto($_POST['efectivo_contado'] ?? '');
            if ($contado === null) {
                throw new RuntimeException('Escribe cuánto efectivo contaste (aunque sea 0).');
            }
            if ($contado < 0) throw new RuntimeException('El efectivo contado no puede ser negativo.');
            $nota = trim($_POST['nota'] ?? '');
            $r = cerrar_caja($pdo, $usuario, (int)$sesion['id'], $contado, $nota);
            registrar_auditoria($pdo, $usuario, 'ventas', 'cerrar_caja', null,
                'Cerró la caja. Esperado ' . clp($r['efectivo_esperado']) .
                ', contado ' . clp($contado) . ', diferencia ' . clp($r['diferencia']));
            header('Location: caja.php?cerrada=1');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['cerrada'])) {
    $aviso = 'Caja cerrada. Puedes ver el detalle en el historial de turnos.';
}

$resumen     = $sesion ? resumen_caja($pdo, (int)$sesion['id']) : null;
$movimientos = $sesion ? movimientos_caja($pdo, (int)$sesion['id']) : [];
$recaudado   = $sesion ? recaudaciones_del_turno($pdo, (int)$sesion['id']) : [];
$enEspera    = $sesion ? tickets_en_espera($pdo, (int)$sesion['id']) : 0;
$otrasAbiertas = $esAdmin ? sesiones_abiertas($pdo, $idsVisibles) : [];
$historial   = sesiones_cerradas($pdo, $idsVisibles, 15);
?>
<?php cabecera_dashboard($usuario, 'ventas', 'Caja'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-turquesa">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('caja') ?></span>
                <h2>Caja</h2>
            </div>
            <a class="btn gris" href="pos.php">Ir a vender</a>
        </div>

        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <?php if ($aviso): ?><div class="panel exito"><p><?= h($aviso) ?></p></div><?php endif; ?>

        <?php if (!$sesion): ?>
            <!-- ================= Abrir caja ================= -->
            <div class="panel">
                <h2>Abrir caja</h2>
                <p class="ec-intro">
                    Declara con cuánto efectivo parte la caja (el sencillo para dar vuelto).
                    Al cerrar el turno se comparará con lo que cuentes.
                </p>
                <?php if (!$negocios): ?>
                    <p class="vacio">No tienes negocios asignados.</p>
                <?php else: ?>
                <form method="post" class="filtros">
                    <input type="hidden" name="accion" value="abrir">
                    <?php if (count($negocios) > 1): ?>
                    <div class="campo">
                        <label>Negocio</label>
                        <select name="negocio_id">
                            <?php foreach ($negocios as $n): ?>
                                <option value="<?= (int)$n['id'] ?>"><?= h($n['nombre']) ?></option>
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
                        <input type="text" name="fondo_inicial" placeholder="20.000" inputmode="numeric">
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <button class="btn verde" type="submit">Abrir caja</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- ================= Turno abierto ================= -->
            <div class="panel">
                <h2>
                    Turno abierto ·
                    <?= h($sesion['caja'] ?: 'Caja') ?> ·
                    <?= h($sesion['negocio_nombre']) ?>
                </h2>
                <p class="ec-intro">Abierta desde <?= h($sesion['abierto_en']) ?>.</p>

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

            <!-- ================= Entradas / salidas ================= -->
            <div class="panel">
                <h2>Entradas y salidas de efectivo</h2>
                <p class="ec-intro">
                    Una <strong>salida</strong> es plata que sale del cajón (pagar el pan al
                    proveedor, un retiro). Una <strong>entrada</strong> es plata que se agrega
                    (por ejemplo, el abono de un fiado que se pagó en efectivo).
                </p>
                <form method="post" class="filtros">
                    <input type="hidden" name="accion" value="movimiento">
                    <div class="campo">
                        <label>Tipo</label>
                        <select name="tipo" id="movTipo">
                            <option value="salida">Salida (sale del cajón)</option>
                            <option value="entrada">Entrada (entra al cajón)</option>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Monto</label>
                        <input type="text" name="monto" placeholder="5.000" inputmode="numeric" required>
                    </div>
                    <div class="campo" style="flex:1 1 220px">
                        <label id="movMotivoLbl">Motivo *</label>
                        <input type="text" name="motivo" id="movMotivo" required
                               placeholder="Pago pan proveedor" style="width:100%">
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <button class="btn" type="submit">Registrar</button>
                    </div>
                </form>
                <script>
                // El motivo es obligatorio solo al sacar plata (el backend lo
                // vuelve a validar; esto es para avisar antes de enviar).
                (function () {
                    var tipo = document.getElementById('movTipo'),
                        campo = document.getElementById('movMotivo'),
                        lbl = document.getElementById('movMotivoLbl');
                    if (!tipo || !campo || !lbl) return;
                    tipo.addEventListener('change', function () {
                        var salida = tipo.value === 'salida';
                        campo.required = salida;
                        lbl.textContent = salida ? 'Motivo *' : 'Motivo (opcional)';
                        campo.placeholder = salida ? 'Pago pan proveedor' : 'Abono de fiado en efectivo';
                    });
                })();
                </script>

                <?php if ($movimientos): ?>
                    <div class="tabla-wrap">
                        <table>
                            <thead><tr>
                                <th>Hora</th><th>Tipo</th><th>Motivo</th><th>Quién</th><th class="monto-col">Monto</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($movimientos as $m): ?>
                                <tr>
                                    <td><?= h(substr((string)$m['creado_en'], 11, 5)) ?></td>
                                    <td>
                                        <span class="badge-mov <?= $m['tipo'] === 'entrada' ? 'b-entrada' : 'b-salida' ?>">
                                            <?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Salida' ?>
                                        </span>
                                    </td>
                                    <td><?= h($m['motivo'] ?: '—') ?></td>
                                    <td><?= h($m['usuario'] ?: '—') ?></td>
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

            <!-- ================= Pagos de cuentas del turno ================= -->
            <?php if ($recaudado): ?>
            <div class="panel">
                <h2>Pagos de cuentas (Klap y otros convenios)</h2>
                <p class="ec-intro">
                    Estos <strong>no son ventas tuyas</strong>: el dinero es de la empresa de
                    servicios. Se registran acá porque el efectivo sí entra al cajón y el
                    arqueo tiene que contarlo. Se registran desde la pantalla de venta con <b>F9</b>.
                </p>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>Hora</th><th>Servicio</th><th>Convenio</th><th>Pagó con</th>
                            <th>Comprobante</th><th class="monto-col">Monto</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($recaudado as $o): ?>
                            <tr>
                                <td><?= h(substr((string)$o['fecha'], 11, 5)) ?></td>
                                <td><?= h($o['servicio']) ?></td>
                                <td><?= h($o['convenio']) ?></td>
                                <td>
                                    <span class="badge-mov <?= $o['medio_pago'] === 'efectivo' ? 'b-entrada' : 'b-salida' ?>">
                                        <?= h(ucfirst((string)$o['medio_pago'])) ?>
                                    </span>
                                </td>
                                <td><?= h($o['referencia'] ?: '—') ?></td>
                                <td class="monto-col"><?= clp($o['monto']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ================= Cerrar caja (arqueo) ================= -->
            <div class="panel">
                <h2>Cerrar caja (arqueo)</h2>
                <?php if ($enEspera > 0): ?>
                    <div class="error">
                        Tienes <?= (int)$enEspera ?> venta<?= $enEspera > 1 ? 's' : '' ?>
                        en espera sin terminar. Cóbralas o descártalas antes de cerrar la caja.
                    </div>
                <?php else: ?>
                    <p class="ec-intro">
                        Cuenta el efectivo que hay en el cajón y escríbelo. El sistema lo
                        comparará con los <strong><?= clp($resumen['efectivo_esperado']) ?></strong>
                        que espera y te mostrará si falta o sobra.
                    </p>
                    <form method="post" class="filtros"
                          onsubmit="return confirm('¿Cerrar la caja? No podrás seguir vendiendo en este turno.')">
                        <input type="hidden" name="accion" value="cerrar">
                        <div class="campo">
                            <label>Efectivo contado</label>
                            <input type="text" name="efectivo_contado" placeholder="0"
                                   inputmode="numeric" required autofocus>
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
            <!-- ================= Otras cajas abiertas (admin) ================= -->
            <div class="panel">
                <h2>Otras cajas abiertas</h2>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>Negocio</th><th>Caja</th><th>Cajero</th><th>Abierta desde</th><th class="monto-col">Fondo</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($otrasAbiertas as $s): ?>
                            <?php if ($sesion && (int)$s['id'] === (int)$sesion['id']) continue; ?>
                            <tr>
                                <td><?= h($s['negocio_nombre']) ?></td>
                                <td><?= h($s['caja'] ?: '—') ?></td>
                                <td><?= h($s['cajero'] ?: '—') ?></td>
                                <td><?= h($s['abierto_en']) ?></td>
                                <td class="monto-col"><?= clp($s['fondo_inicial']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= Historial de turnos ================= -->
        <div class="panel">
            <h2>Últimos turnos cerrados</h2>
            <?php if (!$historial): ?>
                <p class="vacio">Aún no hay turnos cerrados.</p>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>Cerrado</th><th>Negocio</th><th>Caja</th><th>Cajero</th>
                            <th class="monto-col">Esperado</th><th class="monto-col">Contado</th><th class="monto-col">Diferencia</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($historial as $s): ?>
                            <?php $d = (float)$s['diferencia']; ?>
                            <tr>
                                <td><?= h($s['cerrado_en']) ?></td>
                                <td><?= h($s['negocio_nombre']) ?></td>
                                <td><?= h($s['caja'] ?: '—') ?></td>
                                <td><?= h($s['cajero'] ?: '—') ?></td>
                                <td class="monto-col"><?= clp($s['efectivo_esperado']) ?></td>
                                <td class="monto-col"><?= clp($s['efectivo_contado']) ?></td>
                                <td class="monto-col">
                                    <span class="dif <?= $d < 0 ? 'dif-falta' : ($d > 0 ? 'dif-sobra' : 'dif-ok') ?>">
                                        <?= $d == 0 ? 'Cuadró' : (($d > 0 ? 'Sobra ' : 'Falta ') . clp(abs($d))) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php pie_dashboard(); ?>
