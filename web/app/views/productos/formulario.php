<?php
/**
 * Vista de app/Controllers/ProductosController::formulario().
 * Variables disponibles: $usuario, $id, $prod, $deptos, $error, $autor, $titulo.
 *
 * El catálogo es COMPARTIDO por los dos negocios: lo que se cambie acá se
 * ve en ambos locales al instante.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'herramientas', $titulo); ?>

    <div class="contenido">
        <div class="form-pagina form-pagina-ancha">
            <div class="cab-modulo modulo-turquesa">
                <div class="cab-modulo-tit">
                    <span class="modulo-badge"><?= icono('codigo-barras') ?></span>
                    <h2><?= h($titulo) ?></h2>
                </div>
                <a class="btn gris" href="herramienta_productos.php">Volver</a>
            </div>

            <div class="panel aviso-compartido">
                <p>
                    <strong>Este catálogo es compartido.</strong> Lo que guardes acá se verá
                    en <strong>los dos negocios</strong> al instante. Cada cambio queda
                    registrado con tu nombre.
                </p>
            </div>

            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

            <div class="panel">
            <form method="post">
                <?= campo_csrf() ?>
                <div class="campo">
                    <label>Código de barras *</label>
                    <input type="text" name="codigo" required autocomplete="off"
                           value="<?= h($prod['codigo']) ?>" placeholder="7801234567890">
                    <small class="texto-tenue">Es lo que lee el escáner. No se puede repetir.</small>
                </div>
                <div class="campo">
                    <label>Nombre del producto *</label>
                    <input type="text" name="nombre" required autocomplete="off"
                           value="<?= h($prod['nombre']) ?>" placeholder="Coca Cola 1.5L">
                </div>
                <div class="campo">
                    <label>Precio de venta *</label>
                    <input type="text" name="precio_venta" required inputmode="numeric"
                           value="<?= h($prod['precio_venta']) ?>" placeholder="2.000">
                </div>
                <div class="campo">
                    <label>Precio de costo</label>
                    <input type="text" name="precio_costo" inputmode="numeric"
                           value="<?= h($prod['precio_costo']) ?>" placeholder="1.400">
                    <small class="texto-tenue">Opcional. Sirve para calcular tu ganancia real.</small>
                </div>
                <div class="campo">
                    <label>Precio mayoreo</label>
                    <input type="text" name="precio_mayoreo" inputmode="numeric"
                           value="<?= h($prod['precio_mayoreo']) ?>" placeholder="—">
                </div>
                <div class="campo">
                    <label>Departamento</label>
                    <input type="text" name="departamento" list="listaDeptos" autocomplete="off"
                           value="<?= h($prod['departamento']) ?>" placeholder="Bebidas">
                    <datalist id="listaDeptos">
                        <?php foreach ($deptos as $d): ?>
                            <option value="<?= h($d) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="campo">
                    <label>Tipo de venta</label>
                    <input type="text" name="tipo_venta" autocomplete="off"
                           value="<?= h($prod['tipo_venta']) ?>" placeholder="Unidad / Granel">
                </div>
                <div class="campo campo-check">
                    <label>
                        <input type="checkbox" name="activo" value="1"
                               <?= !empty($prod['activo']) ? 'checked' : '' ?>>
                        Disponible para la venta
                    </label>
                    <small class="texto-tenue">Desmárcalo para ocultarlo del POS sin borrarlo.</small>
                </div>
                <div class="campo campo-check">
                    <label>
                        <input type="checkbox" name="afecto_iva" value="1"
                               <?= !empty($prod['afecto_iva']) ? 'checked' : '' ?>>
                        Afecto a IVA
                    </label>
                </div>

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

                <div class="form-acciones">
                    <button class="btn" type="submit" name="accion" value="guardar">Guardar</button>
                    <a class="btn gris" href="herramienta_productos.php">Cancelar</a>
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
    <?php pie_dashboard(); ?>
