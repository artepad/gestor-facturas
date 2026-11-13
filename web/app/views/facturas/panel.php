<?php
/**
 * Vista de app/Controllers/FacturasController::panel().
 * Variables disponibles: $usuario, $negocios, $fNegocio, $fProveedor, $fDesde,
 * $fHasta, $fTexto, $facturas, $proveedores, $viendo, $csrfToken.
 *
 * "Agregar factura" (botón de la cabecera) pasa todo dentro de esta misma
 * pantalla — sin navegar a otra página — con un panel que se despliega y un
 * pop-up de verificación encima. El trabajo real (llamar a la IA, guardar)
 * lo hace FacturaIaController por fetch() (ver factura_ia.php); esta vista
 * solo arma la interfaz. Ver web/CLAUDE.md, sección "Facturas: subida
 * directa con IA".
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'facturas', 'Facturas'); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-naranjo">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('facturas') ?></span>
                <h2>Facturas</h2>
            </div>
            <div class="cab-modulo-der">
                <span class="badge-negocio">
                    <?= icono('negocios') ?>
                    Viendo: <strong><?= e($viendo) ?></strong>
                </span>
                <button type="button" class="btn-agregar-factura" id="btnAbrirAgregar">
                    <?= icono('subir') ?> Agregar factura
                </button>
            </div>
        </div>

        <!-- ====== Agregar factura (desplegable, sin cambiar de pantalla) ====== -->
        <section class="panel panel-agregar-factura" id="panelAgregarFactura" hidden aria-label="Agregar factura con IA">
            <div class="panel-agregar-cab">
                <h2><?= icono('subir') ?> Agregar factura con IA</h2>
                <button type="button" class="btn-cerrar-panel" id="btnCerrarAgregar" aria-label="Cerrar">&times;</button>
            </div>
            <p class="campo-ayuda" style="margin-top:-4px">
                Sube el PDF de la factura: la IA lee los datos y los puedes corregir antes de guardar.
            </p>

            <?php if (count($negocios) > 1): ?>
                <div class="campo" style="max-width:280px;margin-bottom:14px">
                    <label>Negocio</label>
                    <select id="agregarNegocioId">
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"><?= e($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" id="agregarNegocioId" value="<?= (int)($negocios[0]['id'] ?? 0) ?>">
            <?php endif; ?>

            <label class="factura-dropzone" id="dropzone" for="inputPdf">
                <input type="file" id="inputPdf" accept="application/pdf,.pdf" hidden>
                <?= icono('subir') ?>
                <p><strong>Arrastra el PDF aquí</strong> o haz clic para elegir un archivo</p>
                <p class="campo-ayuda">Solo PDF, máximo 15 MB.</p>
            </label>

            <div class="factura-cargando" id="cargando" hidden>
                <span class="spinner"></span> Leyendo la factura con IA, espera un momento…
            </div>

            <p class="error" id="errorSubida" hidden></p>
        </section>

        <!-- ====== Pop-up de verificación (editable) ====== -->
        <div class="modal-fondo" id="verifFondo" hidden></div>
        <section class="modal-confirmar modal-confirmar-ancho" id="verifModal" hidden aria-label="Verificar datos de la factura">
            <div class="modal-confirmar-icono"><?= icono('facturas') ?></div>
            <h2>Revisa los datos antes de guardar</h2>
            <p class="modal-confirmar-ayuda">
                Esto es lo que la IA leyó de la factura. Corrige lo que haga falta: nada se
                guarda hasta que confirmes.
            </p>

            <p class="modal-confirmar-aviso" id="avisoNoFactura" hidden></p>

            <input type="hidden" id="verifToken">
            <input type="hidden" id="verifConfianza">
            <input type="hidden" id="verifConfirmarDuplicada" value="0">

            <div class="factura-campos">
                <div class="campo">
                    <label>Proveedor (marca)</label>
                    <input type="text" id="fProveedor">
                </div>
                <div class="campo">
                    <label>Razón social</label>
                    <input type="text" id="fRazonSocial">
                </div>
                <div class="campo">
                    <label>RUT emisor</label>
                    <input type="text" id="fRut" placeholder="XX.XXX.XXX-X">
                </div>
                <div class="campo">
                    <label>N° Factura</label>
                    <input type="text" id="fNumero">
                </div>
                <div class="campo">
                    <label>Fecha de emisión</label>
                    <input type="date" id="fFecha">
                </div>
                <div class="campo">
                    <label>Total</label>
                    <input type="text" inputmode="numeric" id="fTotal" placeholder="0">
                </div>
            </div>
            <div class="campo" style="margin-top:12px">
                <label>Notas</label>
                <textarea id="fNotas" rows="2" placeholder="Observaciones (opcional)"></textarea>
            </div>

            <p class="modal-confirmar-aviso" id="avisoDuplicada" hidden></p>

            <div class="modal-confirmar-btns">
                <button type="button" class="btn gris" id="btnCancelarVerif">Cancelar</button>
                <button type="button" class="btn verde" id="btnGuardarVerif">Guardar factura</button>
            </div>
        </section>

        <div class="panel">
            <h2>Filtros</h2>
            <?php $fechasActivas = ($fDesde !== '' || $fHasta !== ''); ?>
            <form class="filtros<?= $fechasActivas ? ' mostrar-fechas' : '' ?>" method="get">
                <div class="campo campo-busqueda">
                    <label>Búsqueda</label>
                    <input type="text" name="q" value="<?= e($fTexto) ?>"
                           placeholder="N° Factura, proveedor, razón social" autofocus>
                    <!-- Interruptor de fechas: queda justo debajo de la búsqueda. -->
                    <label class="toggle-fechas">
                        <input type="checkbox" id="toggleFechas" <?= $fechasActivas ? 'checked' : '' ?>>
                        Filtrar por fechas
                    </label>
                </div>
                <?php if (count($negocios) > 1): ?>
                <div class="campo campo-medio">
                    <label>Negocio</label>
                    <!-- Al elegir un negocio se busca solo, sin tocar "Buscar". -->
                    <select name="negocio" onchange="this.form.submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($negocios as $n): ?>
                            <option value="<?= (int)$n['id'] ?>"
                                <?= $fNegocio === (int)$n['id'] ? 'selected' : '' ?>>
                                <?= e($n['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="campo campo-medio">
                    <label>Proveedor</label>
                    <!-- Combo con los proveedores existentes; busca solo al elegir. -->
                    <select name="proveedor" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($proveedores as $prov): ?>
                            <option value="<?= e($prov) ?>"
                                <?= $fProveedor === $prov ? 'selected' : '' ?>>
                                <?= e($prov) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Desde/Hasta: ocultos por defecto; aparecen al marcar el interruptor.
                     En móvil quedan ocultos (junto con el interruptor) por CSS. -->
                <div class="campo campo-fecha">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= e($fDesde) ?>">
                </div>
                <div class="campo campo-fecha">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= e($fHasta) ?>">
                </div>
                <div class="filtros-botones">
                    <button class="btn" type="submit">Buscar</button>
                    <a class="btn gris" href="panel_facturas.php">Limpiar</a>
                </div>
            </form>
        </div>

        <div class="resumen"><?= count($facturas) ?> factura(s)</div>

        <div class="tabla-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="nowrap">Fecha</th>
                        <th>Proveedor</th>
                        <th>N° Factura</th>
                        <th class="total">Total</th>
                        <th class="col-ocultar-movil">Razón Social</th>
                        <?php if (count($negocios) > 1): ?><th class="col-ocultar-movil">Negocio</th><?php endif; ?>
                        <th class="celda-estado">Estado</th>
                        <th class="col-ocultar-movil">PDF</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$facturas): ?>
                    <tr><td class="vacio" colspan="8">No hay facturas con esos filtros.</td></tr>
                <?php else: foreach ($facturas as $f):
                    [$color, $txtEstado] = estado_factura($f); ?>
                    <tr class="fila-click" onclick="location.href='factura.php?id=<?= (int)$f['id'] ?>'">
                        <td class="nowrap"><?= e(fecha_dmy($f['fecha'])) ?></td>
                        <td><?= e($f['proveedor']) ?></td>
                        <td><?= e($f['numero_factura'] ?? '') ?></td>
                        <td class="total"><?= clp($f['total']) ?></td>
                        <td class="col-ocultar-movil"><?= e($f['razon_social'] ?? '') ?></td>
                        <?php if (count($negocios) > 1): ?>
                            <td class="col-ocultar-movil"><?= e($f['negocio_nombre']) ?></td>
                        <?php endif; ?>
                        <td class="celda-estado"><span class="estado"><span class="punto <?= $color ?>"></span><span class="estado-txt"><?= $txtEstado ?></span></span></td>
                        <td class="col-ocultar-movil">
                            <?php if ($f['ruta_pdf']): ?>
                                <a class="btn-icono" href="ver_pdf.php?id=<?= (int)$f['id'] ?>" target="_blank"
                                   title="Ver PDF" aria-label="Ver PDF" onclick="event.stopPropagation()"><?= icono('documento') ?></a>
                            <?php else: ?>
                                <span style="color:#aaa">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
      // El interruptor "Filtrar por fechas" muestra/oculta los campos Desde/Hasta.
      (function () {
        var chk = document.getElementById('toggleFechas');
        if (!chk) return;
        var form = chk.closest('form');
        chk.addEventListener('change', function () {
          form.classList.toggle('mostrar-fechas', chk.checked);
          if (!chk.checked) {  // al desactivar, limpiar las fechas elegidas
            form.querySelector('input[name="desde"]').value = '';
            form.querySelector('input[name="hasta"]').value = '';
          }
        });
      })();

      // "Agregar factura": sube un PDF, la IA lee la cabecera, la vendedora
      // revisa/corrige en el pop-up y confirma — todo sin salir de este panel.
      // Ver web/app/Controllers/FacturaIaController.php (factura_ia.php).
      (function () {
        var token = <?= json_encode($csrfToken) ?>;
        var btnAbrir = document.getElementById('btnAbrirAgregar'),
            btnCerrar = document.getElementById('btnCerrarAgregar'),
            panelAgregar = document.getElementById('panelAgregarFactura'),
            dropzone = document.getElementById('dropzone'),
            inputPdf = document.getElementById('inputPdf'),
            negocioIdEl = document.getElementById('agregarNegocioId'),
            cargando = document.getElementById('cargando'),
            errorSubida = document.getElementById('errorSubida'),
            fondo = document.getElementById('verifFondo'),
            modal = document.getElementById('verifModal'),
            avisoNoFactura = document.getElementById('avisoNoFactura'),
            avisoDuplicada = document.getElementById('avisoDuplicada'),
            verifToken = document.getElementById('verifToken'),
            verifConfianza = document.getElementById('verifConfianza'),
            verifConfirmarDuplicada = document.getElementById('verifConfirmarDuplicada'),
            fProveedor = document.getElementById('fProveedor'),
            fRazonSocial = document.getElementById('fRazonSocial'),
            fRut = document.getElementById('fRut'),
            fNumero = document.getElementById('fNumero'),
            fFecha = document.getElementById('fFecha'),
            fTotal = document.getElementById('fTotal'),
            fNotas = document.getElementById('fNotas'),
            btnCancelarVerif = document.getElementById('btnCancelarVerif'),
            btnGuardarVerif = document.getElementById('btnGuardarVerif');
        if (!btnAbrir || !panelAgregar) return;

        btnAbrir.addEventListener('click', function () {
          panelAgregar.hidden = !panelAgregar.hidden;
          if (!panelAgregar.hidden) panelAgregar.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        btnCerrar.addEventListener('click', function () { panelAgregar.hidden = true; });

        function negocioId() {
          return parseInt(negocioIdEl.value, 10) || 0;
        }
        function mostrarError(msg) {
          errorSubida.textContent = msg;
          errorSubida.hidden = false;
        }
        function limpiarError() {
          errorSubida.hidden = true;
        }
        function formatoMiles(v) {
          v = String(v || '').replace(/[^0-9]/g, '');
          return v === '' ? '' : v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        // --- Elegir / arrastrar archivo ---
        dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('sobre'); });
        dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('sobre'); });
        dropzone.addEventListener('drop', function (e) {
          e.preventDefault();
          dropzone.classList.remove('sobre');
          if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            subirArchivo(e.dataTransfer.files[0]);
          }
        });
        inputPdf.addEventListener('change', function () {
          if (inputPdf.files && inputPdf.files[0]) subirArchivo(inputPdf.files[0]);
        });

        function subirArchivo(archivo) {
          limpiarError();
          if (!negocioId()) { mostrarError('Elige un negocio.'); return; }
          if (archivo.type && archivo.type !== 'application/pdf' && !archivo.name.toLowerCase().endsWith('.pdf')) {
            mostrarError('Solo se aceptan archivos PDF.');
            return;
          }

          var datos = new FormData();
          datos.append('_token', token);
          datos.append('accion', 'analizar');
          datos.append('negocio_id', negocioId());
          datos.append('pdf', archivo);

          cargando.hidden = false;
          dropzone.classList.add('deshabilitado');
          fetch('factura_ia.php', { method: 'POST', body: datos })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              cargando.hidden = true;
              dropzone.classList.remove('deshabilitado');
              if (!res.ok) { mostrarError(res.error || 'No se pudo leer la factura.'); return; }
              mostrarVerificacion(res);
            })
            .catch(function () {
              cargando.hidden = true;
              dropzone.classList.remove('deshabilitado');
              mostrarError('No se pudo conectar con el servidor. Intenta de nuevo.');
            });
        }

        // --- Pop-up de verificación ---
        function mostrarVerificacion(res) {
          var d = res.datos;
          verifToken.value = res.token;
          verifConfianza.value = d.confianza || '';
          verifConfirmarDuplicada.value = '0';

          avisoNoFactura.hidden = d.es_factura !== false;
          if (!avisoNoFactura.hidden) {
            avisoNoFactura.textContent = 'La IA no reconoció esto como una factura (parece "'
              + (d.tipo_documento || 'otro documento') + '"). Revisa los datos igual, o cancela si subiste el PDF equivocado.';
          }

          fProveedor.value = d.proveedor || '';
          fRazonSocial.value = d.razon_social || '';
          fRut.value = d.rut_emisor || '';
          fNumero.value = d.numero_factura || '';
          fFecha.value = d.fecha || '';
          fTotal.value = d.total ? formatoMiles(d.total) : '';
          fNotas.value = d.notas || '';

          mostrarAvisoDuplicada(res.duplicada);

          fondo.hidden = false;
          modal.hidden = false;
        }
        function mostrarAvisoDuplicada(dup) {
          if (!dup) { avisoDuplicada.hidden = true; return; }
          avisoDuplicada.hidden = false;
          avisoDuplicada.textContent = 'Ya existe una factura con este folio de este proveedor '
            + '(guardada el ' + (dup.fecha || '?') + ', por $' + formatoMiles(dup.total) + '). '
            + 'Si guardas, se registrará como una factura aparte.';
        }
        function cerrarVerificacion() {
          fondo.hidden = true;
          modal.hidden = true;
        }
        btnCancelarVerif.addEventListener('click', cerrarVerificacion);
        fTotal.addEventListener('input', function () {
          var pos = fTotal.selectionStart, antes = fTotal.value.length;
          fTotal.value = formatoMiles(fTotal.value);
          fTotal.selectionStart = fTotal.selectionEnd = pos + (fTotal.value.length - antes);
        });

        btnGuardarVerif.addEventListener('click', function () {
          limpiarError();
          if (!fProveedor.value.trim()) { mostrarError('Escribe el proveedor.'); return; }
          if (!fFecha.value) { mostrarError('Elige la fecha de emisión.'); return; }
          if (!fTotal.value.replace(/[^0-9]/g, '')) { mostrarError('Escribe el total.'); return; }

          var datos = new FormData();
          datos.append('_token', token);
          datos.append('accion', 'guardar');
          datos.append('negocio_id', negocioId());
          datos.append('token', verifToken.value);
          datos.append('confianza', verifConfianza.value);
          datos.append('confirmar_duplicada', verifConfirmarDuplicada.value);
          datos.append('proveedor', fProveedor.value.trim());
          datos.append('razon_social', fRazonSocial.value.trim());
          datos.append('rut_emisor', fRut.value.trim());
          datos.append('numero_factura', fNumero.value.trim());
          datos.append('fecha', fFecha.value);
          datos.append('total', fTotal.value.replace(/[^0-9]/g, ''));
          datos.append('notas', fNotas.value.trim());

          btnGuardarVerif.disabled = true;
          fetch('factura_ia.php', { method: 'POST', body: datos })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              btnGuardarVerif.disabled = false;
              if (res.duplicada) {
                // El servidor vuelve a confirmar el duplicado (nunca confía en lo que ya se mostró):
                // se pide una segunda confirmación explícita antes de reintentar.
                mostrarAvisoDuplicada(res.duplicada);
                verifConfirmarDuplicada.value = '1';
                mostrarError('Esa factura ya existe. Vuelve a apretar "Guardar factura" para guardarla igual.');
                return;
              }
              if (!res.ok) { mostrarError(res.error || 'No se pudo guardar la factura.'); return; }
              // Se queda en esta misma pantalla: recarga para ver la factura
              // recién guardada arriba del listado, conservando los filtros activos.
              window.location.reload();
            })
            .catch(function () {
              btnGuardarVerif.disabled = false;
              mostrarError('No se pudo conectar con el servidor. Intenta de nuevo.');
            });
        });
      })();
    </script>
    <?php pie_dashboard(); ?>
