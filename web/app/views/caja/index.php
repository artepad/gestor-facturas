<?php
/**
 * Vista de app/Controllers/CajaController::index().
 *
 * Fase 9 (despliegue): esta pantalla ya es la real (web/caja.php la puentea
 * directo a este Controlador), así que usa el layout completo del panel
 * (cabecera_dashboard/pie_dashboard), igual que el resto del sitio. Único
 * agregado respecto al archivo original: el campo CSRF en los 3 formularios
 * (hallazgo H3).
 *
 * Variables disponibles (ver CajaController): $usuario, $negocios, $esAdmin,
 * $sesion, $error, $aviso, $corteNuevo, $resumen, $movimientos, $recaudado,
 * $enEspera, $otrasAbiertas, $historial, $avisoLarga, $sesionesAbiertas.
 */
require_once dirname(__DIR__, 3) . '/lib/ui.php';
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
        <?php if ($aviso): ?>
            <div class="panel exito">
                <p>
                    <?= h($aviso) ?>
                    <?php if ($corteNuevo): ?>
                        <a href="corte.php?id=<?= $corteNuevo ?>">Ver el corte</a>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

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
                <form method="post" class="filtros" id="formAbrirCaja">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="abrir">
                    <?php if (count($negocios) > 1): ?>
                    <div class="campo">
                        <label>Negocio</label>
                        <select name="negocio_id" id="abrirNegocioId">
                            <?php foreach ($negocios as $n): ?>
                                <option value="<?= (int)$n['id'] ?>"><?= h($n['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="negocio_id" value="<?= (int)$negocios[0]['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="tipo" id="abrirCajaTipo" value="fisica">
                    <div class="campo">
                        <label>Nombre de la caja</label>
                        <select name="caja" id="abrirCajaNombre" style="min-width:180px">
                            <option value="Principal" data-tipo="fisica">Principal</option>
                            <option value="Secundaria" data-tipo="fisica">Secundaria</option>
                            <option value="Express" data-tipo="express">Express (celular, sin efectivo)</option>
                        </select>
                    </div>
                    <div class="campo" id="campoFondoInicial">
                        <label>Fondo inicial</label>
                        <input type="text" name="fondo_inicial" id="abrirCajaFondo" placeholder="20.000" inputmode="numeric">
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <button class="btn verde" type="submit" id="btnAbrirCaja">Abrir caja</button>
                    </div>
                </form>
                <p class="aviso-caja-larga" id="avisoCajaDuplicada" hidden></p>

                <!-- ====== Confirmación antes de abrir (pop-up propio) ====== -->
                <div class="modal-fondo" id="confirmarAbrirFondo" hidden></div>
                <section class="modal-confirmar" id="confirmarAbrirCaja" hidden aria-label="Confirmar apertura de caja">
                    <div class="modal-confirmar-icono"><?= icono('caja') ?></div>
                    <h2>¿Estás segura de iniciar esta caja?</h2>
                    <p class="modal-confirmar-ayuda">Revisa los datos: una vez abierta, todas las ventas de este turno quedarán registradas aquí.</p>
                    <dl class="modal-confirmar-datos">
                        <div><dt>Negocio</dt><dd id="confNegocio">—</dd></div>
                        <div><dt>Nombre de la caja</dt><dd id="confCaja">—</dd></div>
                        <div><dt>Monto inicial</dt><dd id="confFondo">—</dd></div>
                    </dl>
                    <p class="modal-confirmar-aviso" id="confAviso" hidden></p>
                    <div class="modal-confirmar-btns">
                        <button type="button" class="btn gris" id="btnCancelarAbrir">Cancelar</button>
                        <button type="button" class="btn verde" id="btnConfirmarAbrir">Iniciar caja</button>
                    </div>
                </section>

                <script>
                // Sesiones hoy abiertas en los negocios visibles (ver CajaController):
                // sirve solo para avisar si el nombre elegido ya está en uso en ESE
                // negocio — no bloquea nada, la vendedora decide igual (ver el
                // análisis sobre "combo con aviso, no ocultar").
                var SESIONES_ABIERTAS = <?= json_encode(array_map(fn($s) => [
                    'negocio_id' => (int)$s['negocio_id'],
                    'caja'       => (string)($s['caja'] ?? ''),
                    'cajero'     => (string)($s['cajero'] ?? ''),
                    'abierto_en' => (string)($s['abierto_en'] ?? ''),
                ], $sesionesAbiertas), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

                // Pop-up propio (no confirm() nativo) para revisar negocio/caja/monto
                // antes de abrir: evita que una vendedora deje el turno entero
                // (ventas, stock, corte) pegado al negocio equivocado por apretar
                // mal el selector o no fijarse en el monto. También avisa (sin
                // bloquear) si ese nombre de caja ya está abierto en ese negocio.
                (function () {
                    var form   = document.getElementById('formAbrirCaja'),
                        selNeg = document.getElementById('abrirNegocioId'),
                        selCaja = document.getElementById('abrirCajaNombre'),
                        txtFondo = document.getElementById('abrirCajaFondo'),
                        campoFondo = document.getElementById('campoFondoInicial'),
                        inputTipo = document.getElementById('abrirCajaTipo'),
                        avisoInline = document.getElementById('avisoCajaDuplicada'),
                        fondoModal = document.getElementById('confirmarAbrirFondo'),
                        modal  = document.getElementById('confirmarAbrirCaja'),
                        confNegocio = document.getElementById('confNegocio'),
                        confCaja = document.getElementById('confCaja'),
                        confFondo = document.getElementById('confFondo'),
                        confAviso = document.getElementById('confAviso'),
                        btnOk  = document.getElementById('btnConfirmarAbrir'),
                        btnCancelar = document.getElementById('btnCancelarAbrir');
                    if (!form || !modal || !fondoModal) return;

                    function negocioIdElegido() {
                        if (selNeg) return parseInt(selNeg.value, 10);
                        var hidden = form.querySelector('input[name=negocio_id]');
                        return hidden ? parseInt(hidden.value, 10) : 0;
                    }
                    function nombreNegocio() {
                        if (selNeg) return selNeg.options[selNeg.selectedIndex].text;
                        return '<?= count($negocios) === 1 ? h($negocios[0]['nombre']) : '' ?>';
                    }
                    function formatoFondo() {
                        var v = (txtFondo.value || '').replace(/[^0-9]/g, '');
                        if (v === '') return '$0';
                        return '$' + v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    }
                    // Caja Express no maneja efectivo: no tiene sentido pedir un
                    // fondo inicial. Se oculta el campo y se guarda el tipo
                    // elegido en un input aparte (no se adivina del nombre).
                    function actualizarTipo() {
                        var tipo = selCaja.options[selCaja.selectedIndex].dataset.tipo || 'fisica';
                        inputTipo.value = tipo;
                        campoFondo.hidden = (tipo === 'express');
                        if (tipo === 'express') txtFondo.value = '';
                    }
                    // Busca si YA hay una caja abierta con ese mismo nombre en ese
                    // mismo negocio (cada negocio se evalúa por separado).
                    function sesionDuplicada() {
                        var negId = negocioIdElegido(), caja = selCaja.value;
                        for (var i = 0; i < SESIONES_ABIERTAS.length; i++) {
                            var s = SESIONES_ABIERTAS[i];
                            if (s.negocio_id === negId && s.caja === caja) return s;
                        }
                        return null;
                    }
                    function textoDuplicada(s) {
                        var hora = (s.abierto_en || '').substr(11, 5);
                        return 'Ya hay una caja "' + s.caja + '" abierta en este negocio' +
                               (s.cajero ? ', abierta por ' + s.cajero : '') +
                               (hora ? ' desde las ' + hora : '') +
                               '. Puedes continuar igual o elegir otro nombre.';
                    }
                    function revisarDuplicada() {
                        var s = sesionDuplicada();
                        if (s) {
                            avisoInline.textContent = textoDuplicada(s);
                            avisoInline.hidden = false;
                        } else {
                            avisoInline.hidden = true;
                        }
                        return s;
                    }
                    function abrirModal() {
                        confNegocio.textContent = nombreNegocio();
                        confCaja.textContent = selCaja.options[selCaja.selectedIndex].text;
                        confFondo.textContent = inputTipo.value === 'express'
                            ? 'No aplica (sin efectivo)' : formatoFondo();
                        var s = revisarDuplicada();
                        if (s) {
                            confAviso.textContent = textoDuplicada(s);
                            confAviso.hidden = false;
                        } else {
                            confAviso.hidden = true;
                        }
                        fondoModal.hidden = false;
                        modal.hidden = false;
                        btnOk.focus();
                    }
                    function cerrarModal() {
                        fondoModal.hidden = true;
                        modal.hidden = true;
                    }

                    if (selNeg) selNeg.addEventListener('change', revisarDuplicada);
                    selCaja.addEventListener('change', function () { actualizarTipo(); revisarDuplicada(); });
                    actualizarTipo();
                    revisarDuplicada();

                    form.addEventListener('submit', function (ev) {
                        if (modal.dataset.confirmado === '1') return;
                        ev.preventDefault();
                        abrirModal();
                    });
                    btnCancelar.addEventListener('click', cerrarModal);
                    fondoModal.addEventListener('click', cerrarModal);
                    document.addEventListener('keydown', function (ev) {
                        if (ev.key === 'Escape' && !modal.hidden) cerrarModal();
                    });
                    btnOk.addEventListener('click', function () {
                        modal.dataset.confirmado = '1';
                        form.requestSubmit ? form.requestSubmit() : form.submit();
                    });
                })();
                </script>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- ================= Turno abierto ================= -->
            <div class="panel panel-turno">
                <div class="turno-cab">
                    <div class="turno-cab-ic"><?= icono('caja') ?></div>
                    <div class="turno-cab-tit">
                        <h2>
                            <?= h($sesion['caja'] ?: 'Caja') ?> · <?= h($sesion['negocio_nombre']) ?>
                            <?php if ($esExpress): ?><span class="badge-express">Sin efectivo</span><?php endif; ?>
                        </h2>
                        <p class="ec-intro">Turno abierto desde <?= h($sesion['abierto_en']) ?>.</p>
                    </div>
                </div>
                <?php if ($avisoLarga): ?>
                    <p class="aviso-caja-larga"><?= icono('reloj') ?> <?= h($avisoLarga) ?></p>
                <?php endif; ?>

                <div class="stat-destacado-solo t-verde">
                    <span class="stat-label">Efectivo esperado en el cajón</span>
                    <span class="stat-valor"><?= clp($resumen['efectivo_esperado']) ?: '$0' ?></span>
                </div>

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
                </div>
            </div>

            <!-- ================= Entradas / salidas ================= -->
            <?php if (!$esExpress): ?>
            <div class="panel">
                <h2>Entradas y salidas de efectivo</h2>
                <p class="ec-intro">
                    Una <strong>salida</strong> es plata que sale del cajón (pagar el pan al
                    proveedor, un retiro). Una <strong>entrada</strong> es plata que se agrega
                    (por ejemplo, el abono de un fiado que se pagó en efectivo).
                </p>

                <button type="button" class="btn" id="btnMostrarMov">Registrar movimiento</button>

                <form method="post" class="filtros mov-form" id="movForm" hidden>
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="movimiento">
                    <div class="campo">
                        <label>Tipo</label>
                        <select name="tipo" id="movTipo">
                            <option value="salida">Salida</option>
                            <option value="entrada">Entrada</option>
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
                        <input type="text" name="motivo" id="movMotivo" required
                               style="width:100%">
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <button class="btn" type="submit">Registrar</button>
                        <button class="btn gris" type="button" id="btnCancelarMov">Cancelar</button>
                    </div>
                </form>
                <script>
                (function () {
                    var btnMostrar = document.getElementById('btnMostrarMov'),
                        form = document.getElementById('movForm'),
                        btnCancelar = document.getElementById('btnCancelarMov'),
                        tipo = document.getElementById('movTipo'),
                        campo = document.getElementById('movMotivo'),
                        lbl = document.getElementById('movMotivoLbl');
                    if (!tipo || !campo || !lbl) return;

                    // El motivo es obligatorio solo al sacar plata (el backend lo
                    // vuelve a validar; esto es para avisar antes de enviar).
                    tipo.addEventListener('change', function () {
                        var salida = tipo.value === 'salida';
                        campo.required = salida;
                        lbl.textContent = salida ? 'Motivo *' : 'Motivo (opcional)';
                    });

                    // El formulario parte oculto: solo el botón. Se despliega al
                    // pedirlo, para que la pantalla muestre primero lo importante
                    // (el historial de movimientos), no un formulario vacío.
                    if (btnMostrar && form) {
                        btnMostrar.addEventListener('click', function () {
                            form.hidden = false;
                            btnMostrar.hidden = true;
                            tipo.focus();
                        });
                    }
                    if (btnCancelar) {
                        btnCancelar.addEventListener('click', function () {
                            form.hidden = true;
                            form.reset();
                            lbl.textContent = 'Motivo *';
                            campo.required = true;
                            btnMostrar.hidden = false;
                        });
                    }
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
                                <?php $esSalida = $m['tipo'] === 'salida'; ?>
                                <tr>
                                    <td><?= h(substr((string)$m['creado_en'], 11, 5)) ?></td>
                                    <td>
                                        <span class="badge-mov <?= $esSalida ? 'b-salida' : 'b-entrada' ?>">
                                            <?= $esSalida ? 'Salida' : 'Entrada' ?>
                                        </span>
                                    </td>
                                    <td><?= h($m['motivo'] ?: '—') ?></td>
                                    <td><?= h($m['usuario'] ?: '—') ?></td>
                                    <td class="monto-col <?= $esSalida ? 'valor-rojo' : 'valor-verde' ?>">
                                        <?= ($esSalida ? '− ' : '+ ') . clp($m['monto']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="vacio">Sin movimientos de efectivo en este turno.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ================= Pagos de cuentas del turno ================= -->
            <?php if ($recaudado): ?>
            <div class="panel">
                <h2>Pagos de cuentas (Mercado Pago y otros convenios)</h2>
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

            <!-- ================= Cerrar caja (arqueo) — la acción más importante
                 de esta pantalla: panel propio, más grande y con su propio
                 color, para que se note antes que cualquier otra cosa. ===== -->
            <div class="panel panel-cerrar">
                <div class="cerrar-cab">
                    <span class="cerrar-cab-ic"><?= icono('caja') ?></span>
                    <h2>Cerrar caja</h2>
                </div>
                <?php if ($enEspera > 0): ?>
                    <div class="error">
                        Tienes <?= (int)$enEspera ?> venta<?= $enEspera > 1 ? 's' : '' ?>
                        en espera sin terminar. Cóbralas o descártalas antes de cerrar la caja.
                    </div>
                <?php elseif ($esExpress): ?>
                    <p class="ec-intro">
                        Esta caja no maneja efectivo, así que no hay nada que contar — se cierra directo.
                    </p>
                    <form method="post" class="filtros"
                          onsubmit="return confirm('¿Cerrar la caja? No podrás seguir vendiendo en este turno.')">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="cerrar">
                        <input type="hidden" name="efectivo_contado" value="0">
                        <div class="campo" style="flex:1 1 220px">
                            <label>Nota (opcional)</label>
                            <input type="text" name="nota" placeholder="Observaciones del turno" style="width:100%">
                        </div>
                        <div class="campo">
                            <label>&nbsp;</label>
                            <button class="btn rojo btn-grande" type="submit">Cerrar caja</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="ec-intro">
                        Cuenta el efectivo que hay en el cajón y escríbelo. El sistema lo
                        comparará con los <strong><?= clp($resumen['efectivo_esperado']) ?></strong>
                        que espera y te mostrará si falta o sobra.
                    </p>

                    <details class="bloque contador-en-cierre" open>
                        <summary><?= icono('calculadora') ?> Contador de billetes y monedas</summary>
                        <div class="bloque-cuerpo">
                            <?php $contadorParaCierre = true; require __DIR__ . '/../partials/contador_caja.php'; ?>
                        </div>
                    </details>

                    <form method="post" class="filtros cerrar-form"
                          onsubmit="return confirm('¿Cerrar la caja? No podrás seguir vendiendo en este turno.')">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="cerrar">
                        <div class="campo">
                            <label>Efectivo contado</label>
                            <input type="text" name="efectivo_contado" id="efectivoContado" placeholder="0"
                                   inputmode="numeric" required autofocus>
                        </div>
                        <div class="campo" style="flex:1 1 220px">
                            <label>Nota (opcional)</label>
                            <input type="text" name="nota" placeholder="Observaciones del turno" style="width:100%">
                        </div>
                        <div class="campo">
                            <label>&nbsp;</label>
                            <button class="btn rojo btn-grande" type="submit">Cerrar caja</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($esAdmin && count($otrasAbiertas) > ($sesion ? 1 : 0)): ?>
            <!-- ================= Otras cajas abiertas (admin) ================= -->
            <div class="panel">
                <h2>Otras cajas abiertas</h2>
                <p class="ec-intro">
                    Si una caja quedó abierta por error, sin internet o la persona ya no está
                    disponible, puedes cerrarla tú — pídele por teléfono cuánto efectivo hay
                    realmente en el cajón antes de hacerlo.
                </p>
                <div class="tabla-wrap">
                    <table>
                        <thead><tr>
                            <th>Negocio</th><th>Caja</th><th>Cajero</th><th>Abierta desde</th>
                            <th class="monto-col">Fondo</th><th></th>
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
                                <td>
                                    <button type="button" class="btn gris btn-cerrar-ajena"
                                            data-sesion-id="<?= (int)$s['id'] ?>"
                                            data-negocio="<?= h($s['negocio_nombre']) ?>"
                                            data-caja="<?= h($s['caja'] ?: 'Caja') ?>"
                                            data-cajero="<?= h($s['cajero'] ?: 'esa persona') ?>"
                                            data-esperado="<?= (float)$s['esperado'] ?>">
                                        Cerrar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ====== Cerrar caja ajena (pop-up propio, admin) ====== -->
            <div class="modal-fondo" id="cerrarAjenaFondo" hidden></div>
            <section class="modal-confirmar" id="modalCerrarAjena" hidden aria-label="Cerrar la caja de otra persona">
                <div class="modal-confirmar-icono"><?= icono('caja') ?></div>
                <h2>Cerrar la caja de <span id="cajAjenaCajero">—</span></h2>
                <p class="modal-confirmar-ayuda">
                    Esto cierra el turno igual que si lo cerrara ella misma. Necesitas saber
                    cuánto efectivo hay <strong>de verdad</strong> en el cajón — pregúntale por
                    teléfono o ve a mirar; el sistema no inventa el cuadre.
                </p>
                <dl class="modal-confirmar-datos">
                    <div><dt>Negocio</dt><dd id="cajAjenaNegocio">—</dd></div>
                    <div><dt>Caja</dt><dd id="cajAjenaCaja">—</dd></div>
                    <div><dt>Efectivo esperado</dt><dd id="cajAjenaEsperado">—</dd></div>
                </dl>
                <form method="post" id="formCerrarAjena" class="form-angosto">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="cerrar_ajena">
                    <input type="hidden" name="sesion_id" id="cajAjenaSesionId" value="">
                    <div class="campo">
                        <label>Efectivo contado</label>
                        <input type="text" name="efectivo_contado" id="cajAjenaContado"
                               inputmode="numeric" placeholder="0" required>
                    </div>
                    <div class="campo">
                        <label>Nota (opcional)</label>
                        <input type="text" name="nota" placeholder="Por qué se cerró a distancia">
                    </div>
                    <div class="modal-confirmar-btns">
                        <button type="button" class="btn gris" id="btnCancelarCerrarAjena">Cancelar</button>
                        <button type="submit" class="btn rojo">Cerrar esta caja</button>
                    </div>
                </form>
            </section>
            <script>
            (function () {
                var fondoModal = document.getElementById('cerrarAjenaFondo'),
                    modal = document.getElementById('modalCerrarAjena'),
                    form = document.getElementById('formCerrarAjena'),
                    btnCancelar = document.getElementById('btnCancelarCerrarAjena'),
                    campoContado = document.getElementById('cajAjenaContado');
                if (!modal || !fondoModal || !form) return;

                function abrir(btn) {
                    document.getElementById('cajAjenaCajero').textContent = btn.dataset.cajero;
                    document.getElementById('cajAjenaNegocio').textContent = btn.dataset.negocio;
                    document.getElementById('cajAjenaCaja').textContent = btn.dataset.caja;
                    document.getElementById('cajAjenaEsperado').textContent =
                        '$' + Math.round(parseFloat(btn.dataset.esperado) || 0).toString()
                            .replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    document.getElementById('cajAjenaSesionId').value = btn.dataset.sesionId;
                    campoContado.value = '';
                    fondoModal.hidden = false;
                    modal.hidden = false;
                    campoContado.focus();
                }
                function cerrar() {
                    fondoModal.hidden = true;
                    modal.hidden = true;
                }

                document.querySelectorAll('.btn-cerrar-ajena').forEach(function (btn) {
                    btn.addEventListener('click', function () { abrir(btn); });
                });
                btnCancelar.addEventListener('click', cerrar);
                fondoModal.addEventListener('click', cerrar);
                document.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Escape' && !modal.hidden) cerrar();
                });
                form.addEventListener('submit', function (ev) {
                    if (!confirm('¿Seguro que quieres cerrar esta caja? No podrás deshacerlo.')) {
                        ev.preventDefault();
                    }
                });
            })();
            </script>
        <?php endif; ?>

        <!-- ================= Historial de turnos ================= -->
        <!-- Contraído por defecto: no es información prioritaria para el día
             a día (ver análisis con el dueño). -->
        <details class="bloque">
            <summary>
                <?= $esAdmin ? 'Últimos turnos cerrados' : 'Tus últimos turnos cerrados' ?>
                <?php if ($historial): ?><span class="chip-cuenta"><?= count($historial) ?></span><?php endif; ?>
            </summary>
            <div class="bloque-cuerpo">
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
                                    <td>
                                        <?php if ($s['corte_id']): ?>
                                            <a class="btn-ver-corte" href="corte.php?id=<?= (int)$s['corte_id'] ?>">
                                                <?= icono('documento') ?> Ver corte
                                            </a>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>
    </div>
    <script>
    // Campos de dinero: solo números (dígitos y el punto de los miles). Va al
    // final y fuera de todo if, para que alcance tanto al fondo de apertura
    // como al monto contado del cierre. El backend igual rechaza montos <= 0.
    Array.prototype.forEach.call(
        document.querySelectorAll('input[inputmode=numeric]'),
        function (el) {
            el.addEventListener('input', function () {
                var v = el.value.replace(/[^0-9.]/g, '');
                if (v !== el.value) el.value = v;
            });
        }
    );
    </script>
    <?php pie_dashboard(); ?>
