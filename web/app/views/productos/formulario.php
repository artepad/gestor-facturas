<?php
/**
 * Vista de app/Controllers/ProductosController::formulario().
 * Variables disponibles: $usuario, $id, $prod, $deptos, $error, $errores (array
 * campo => mensaje), $autor, $titulo, $maxCodigo, $maxNombre, $maxDepto, $maxMonto,
 * $proveedoresProducto.
 *
 * El catálogo es COMPARTIDO por los dos negocios: lo que se cambie acá se
 * ve en ambos locales al instante.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';

/** Mensaje de error de un campo puntual, si lo hay (ver ProductosController::formulario()). */
function error_campo(array $errores, string $campo): string
{
    return !empty($errores[$campo]) ? '<small class="campo-error">' . h($errores[$campo]) . '</small>' : '';
}
/** Clase para marcar en rojo el borde de un campo con error. */
function clase_campo(array $errores, string $campo): string
{
    return !empty($errores[$campo]) ? ' input-error' : '';
}
?>
<?php cabecera_dashboard($usuario, 'productos', $titulo); ?>

    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-modulo modulo-turquesa">
                <div class="cab-modulo-tit">
                    <span class="modulo-badge"><?= icono('codigo-barras') ?></span>
                    <h2><?= h($titulo) ?></h2>
                </div>
                <a class="btn gris" href="productos.php">Volver</a>
            </div>

            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

            <div class="panel">
            <form method="post">
                <?= campo_csrf() ?>
                <div class="campo">
                    <label>Código de barras *</label>
                    <input type="text" name="codigo" id="inputCodigo" required autocomplete="off"
                           inputmode="numeric" maxlength="<?= (int)$maxCodigo ?>"
                           class="<?= clase_campo($errores, 'codigo') ?>"
                           value="<?= h($prod['codigo']) ?>">
                    <small class="texto-tenue">Es lo que lee el escáner. No se puede repetir, y solo admite números.</small>
                    <?= error_campo($errores, 'codigo') ?>
                </div>
                <div class="campo">
                    <label>Nombre del producto *</label>
                    <input type="text" name="nombre" required autocomplete="off"
                           maxlength="<?= (int)$maxNombre ?>"
                           class="<?= clase_campo($errores, 'nombre') ?>"
                           value="<?= h($prod['nombre']) ?>">
                    <?= error_campo($errores, 'nombre') ?>
                </div>
                <div class="campo">
                    <label>Precio de venta *</label>
                    <input type="text" name="precio_venta" id="inputPrecioVenta" required inputmode="numeric"
                           class="campo-monto<?= clase_campo($errores, 'precio_venta') ?>"
                           value="<?= h($prod['precio_venta']) ?>">
                    <?= error_campo($errores, 'precio_venta') ?>
                </div>
                <div class="campo">
                    <label>Precio de costo</label>
                    <input type="text" name="precio_costo" id="inputPrecioCosto" inputmode="numeric"
                           class="campo-monto<?= clase_campo($errores, 'precio_costo') ?>"
                           value="<?= h($prod['precio_costo']) ?>">
                    <small class="texto-tenue">Opcional. Sirve para calcular tu ganancia real.</small>
                    <?= error_campo($errores, 'precio_costo') ?>
                </div>
                <div class="campo">
                    <label>Precio mayoreo</label>
                    <input type="text" name="precio_mayoreo" id="inputPrecioMayoreo" inputmode="numeric"
                           class="campo-monto<?= clase_campo($errores, 'precio_mayoreo') ?>"
                           value="<?= h($prod['precio_mayoreo']) ?>">
                    <?= error_campo($errores, 'precio_mayoreo') ?>
                </div>
                <div class="campo">
                    <label>Departamento</label>
                    <div class="combo-depto" id="comboDepto">
                        <input type="text" name="departamento" id="inputDepto" autocomplete="off"
                               maxlength="<?= (int)$maxDepto ?>"
                               class="<?= clase_campo($errores, 'departamento') ?>"
                               value="<?= h($prod['departamento']) ?>">
                        <button type="button" class="combo-depto-flecha" id="btnComboDepto"
                                aria-label="Mostrar departamentos">
                            <?= icono('chevron') ?>
                        </button>
                        <div class="combo-depto-lista" id="listaComboDepto" hidden>
                            <?php foreach ($deptos as $d): ?>
                                <div class="combo-depto-opcion" data-valor="<?= h($d) ?>"><?= h($d) ?></div>
                            <?php endforeach; ?>
                            <p class="combo-depto-vacio" hidden>Ningún departamento coincide. Puedes escribir uno nuevo.</p>
                        </div>
                    </div>
                    <?= error_campo($errores, 'departamento') ?>
                </div>
                <div class="campo">
                    <label>Opciones</label>
                    <div class="opciones-producto">
                        <label class="opcion-check">
                            <input type="checkbox" name="activo" value="1"
                                   <?= !empty($prod['activo']) ? 'checked' : '' ?>>
                            <span class="oc-texto">
                                <strong>Disponible para la venta</strong>
                                <small>Desmárcalo para ocultarlo del POS sin borrarlo.</small>
                            </span>
                        </label>
                    </div>
                </div>

                <?php if ($id): ?>
                <div class="campo">
                    <label>Proveedores</label>
                    <?php if ($proveedoresProducto): ?>
                        <div class="lista-chips">
                            <?php foreach ($proveedoresProducto as $pv): ?>
                                <span class="chip-proveedor"><?= h($pv['nombre']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="texto-tenue">Sin proveedores asignados.</p>
                    <?php endif; ?>
                    <small class="texto-tenue">Se gestiona desde Compras → Proveedores.</small>
                </div>
                <?php endif; ?>

                <?php if ($autor): ?>
                    <p class="texto-tenue firma-cambio">
                        <?php if ($autor['editor']): ?>
                            Última edición: <strong><?= h($autor['editor']) ?></strong>
                            <?= $autor['actualizado_en'] ? ' el ' . h(date('d-m-Y H:i', strtotime($autor['actualizado_en']))) : '' ?>.
                        <?php endif; ?>
                        <?php if ($autor['creador']): ?>
                            Creado por <strong><?= h($autor['creador']) ?></strong>.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <div class="form-acciones form-acciones-centro">
                    <button class="btn" type="submit" name="accion" value="guardar">Guardar</button>
                    <a class="btn gris" href="productos.php">Cancelar</a>
                    <?php if ($id): ?>
                        <button class="btn rojo" type="submit" name="accion" value="eliminar"
                                onclick="return confirm('¿Eliminar este producto del catálogo de los DOS negocios? Las ventas ya hechas no se alteran.')">
                            Eliminar
                        </button>
                    <?php endif; ?>
                </div>
            </form>
            </div>
        </div>
    </div>
    <script>
      // Combo de Departamento: propio (no el <input list> nativo, que en el
      // celular abre un desplegable mal ubicado) — lista anclada bajo el
      // campo, se filtra al escribir, y el usuario igual puede escribir un
      // departamento nuevo que no esté en la lista.
      (function () {
        var caja = document.getElementById('comboDepto');
        var input = document.getElementById('inputDepto');
        var btn = document.getElementById('btnComboDepto');
        var lista = document.getElementById('listaComboDepto');
        if (!caja || !input || !btn || !lista) return;
        var opciones = Array.prototype.slice.call(lista.querySelectorAll('.combo-depto-opcion'));
        var avisoVacio = lista.querySelector('.combo-depto-vacio');

        function filtrar() {
          var q = input.value.trim().toLowerCase();
          var visibles = 0;
          opciones.forEach(function (op) {
            var coincide = op.dataset.valor.toLowerCase().indexOf(q) !== -1;
            op.hidden = !coincide;
            if (coincide) visibles++;
          });
          avisoVacio.hidden = visibles > 0;
        }

        function abrir() {
          filtrar();
          lista.hidden = false;
          caja.classList.add('abierto');
        }
        function cerrar() {
          lista.hidden = true;
          caja.classList.remove('abierto');
        }

        input.addEventListener('focus', abrir);
        input.addEventListener('input', function () { abrir(); });
        btn.addEventListener('click', function () {
          if (lista.hidden) { input.focus(); abrir(); } else { cerrar(); }
        });
        opciones.forEach(function (op) {
          op.addEventListener('mousedown', function (e) {
            e.preventDefault(); // no perder el foco antes del click
            input.value = op.dataset.valor;
            cerrar();
          });
        });
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') cerrar();
        });
        document.addEventListener('click', function (e) {
          if (!caja.contains(e.target)) cerrar();
        });
      })();
    </script>
    <script>
      // Código de barras: solo números mientras se escribe — mismo límite que
      // valida el servidor (un producto viejo con letras no se toca si no se
      // edita este campo, ver ProductosController::formulario()).
      (function () {
        var input = document.getElementById('inputCodigo');
        if (!input) return;
        input.addEventListener('input', function () {
          this.value = this.value.replace(/\D/g, '');
        });
      })();

      // Precios: separador de miles en vivo mientras se escribe (mismo patrón
      // que "Agregar factura"/"Editar factura" — ver formatoMiles() en
      // app/views/facturas/panel.php y detalle.php).
      (function () {
        var MAX_DIGITOS = <?= (int)strlen((string)$maxMonto) ?>;
        function formatoMiles(v) {
          v = String(v || '').replace(/[^0-9]/g, '').slice(0, MAX_DIGITOS);
          return v === '' ? '' : v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        ['inputPrecioVenta', 'inputPrecioCosto', 'inputPrecioMayoreo'].forEach(function (idCampo) {
          var campo = document.getElementById(idCampo);
          if (!campo) return;
          campo.addEventListener('input', function () {
            var pos = campo.selectionStart, antes = campo.value.length;
            campo.value = formatoMiles(campo.value);
            campo.selectionStart = campo.selectionEnd = pos + (campo.value.length - antes);
          });
        });
      })();
    </script>
    <?php pie_dashboard(); ?>
