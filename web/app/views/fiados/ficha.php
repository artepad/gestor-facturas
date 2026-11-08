<?php
/**
 * Vista de app/Controllers/FiadosController::ficha().
 * Variables disponibles: $usuario, $esAdmin, $id, $cliente, $error, $totalFiado,
 * $totalAbono, $saldo, $movs, $nombreCompleto, $hoy, $claseSaldo, $inicioSemana,
 * $inicioMes, $editFiado, $editAbono, $abrirFiado, $abrirAbono.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php';
?>
<?php cabecera_dashboard($usuario, 'fiados', $nombreCompleto); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-morado">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('fiados') ?></span>
                <h2><?= e($nombreCompleto) ?></h2>
            </div>
            <a class="btn gris" href="fiados.php">Volver</a>
        </div>

        <!-- Resumen: lo esencial a primera vista (saldo + acciones) -->
        <div class="panel">
            <div class="saldo-card">
                <span class="saldo-label">Saldo pendiente</span>
                <span class="saldo-monto <?= $claseSaldo ?>"><?= clp($saldo) ?></span>
                <span class="dg-label">Fiado: <?= clp($totalFiado) ?> · Abonado: <?= clp($totalAbono) ?></span>
            </div>

            <?php if ($error): ?><div class="error" style="margin-top:12px"><?= e($error) ?></div><?php endif; ?>

            <div class="acciones-cc" id="registrar">
                <details class="accion-cc" <?= $abrirFiado ? 'open' : '' ?>>
                    <summary class="btn"><?= $editFiado ? 'Editar fiado' : '+ Registrar fiado' ?></summary>
                    <form class="form-cc" method="post">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="<?= $editFiado ? 'editar_fiado' : 'nuevo_fiado' ?>">
                        <?php if ($editFiado): ?><input type="hidden" name="ref_id" value="<?= (int)$editFiado['id'] ?>"><?php endif; ?>
                        <div class="campo" style="margin-bottom:10px"><label>Fecha</label>
                            <input type="date" name="fecha" value="<?= e($editFiado['fecha'] ?? $hoy) ?>" required></div>
                        <div class="campo" style="margin-bottom:10px"><label>Monto *</label>
                            <input type="text" name="monto" inputmode="numeric" placeholder="Ej: 5.000"
                                   value="<?= e($editFiado['monto'] ?? '') ?>" required></div>
                        <div class="campo" style="margin-bottom:10px"><label>Descripción</label>
                            <input type="text" name="texto" placeholder="Qué se fió (opcional)"
                                   value="<?= e($editFiado['texto'] ?? '') ?>"></div>
                        <button class="btn" type="submit"><?= $editFiado ? 'Guardar cambios' : 'Agregar fiado' ?></button>
                        <?php if ($editFiado): ?><a class="btn gris" href="cliente.php?id=<?= (int)$id ?>">Cancelar</a><?php endif; ?>
                    </form>
                </details>
                <details class="accion-cc" <?= $abrirAbono ? 'open' : '' ?>>
                    <summary class="btn verde"><?= $editAbono ? 'Editar abono' : '+ Registrar abono' ?></summary>
                    <form class="form-cc" method="post">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="<?= $editAbono ? 'editar_abono' : 'nuevo_abono' ?>">
                        <?php if ($editAbono): ?><input type="hidden" name="ref_id" value="<?= (int)$editAbono['id'] ?>"><?php endif; ?>
                        <div class="campo" style="margin-bottom:10px"><label>Fecha</label>
                            <input type="date" name="fecha" value="<?= e($editAbono['fecha'] ?? $hoy) ?>" required></div>
                        <div class="campo" style="margin-bottom:10px"><label>Monto *</label>
                            <input type="text" name="monto" inputmode="numeric" placeholder="Ej: 2.000"
                                   value="<?= e($editAbono['monto'] ?? '') ?>" required></div>
                        <div class="campo" style="margin-bottom:10px"><label>Nota</label>
                            <input type="text" name="texto" placeholder="Observación (opcional)"
                                   value="<?= e($editAbono['texto'] ?? '') ?>"></div>
                        <button class="btn verde" type="submit"><?= $editAbono ? 'Guardar cambios' : 'Agregar abono' ?></button>
                        <?php if ($editAbono): ?><a class="btn gris" href="cliente.php?id=<?= (int)$id ?>">Cancelar</a><?php endif; ?>
                    </form>
                </details>
            </div>
        </div>

        <!-- Estado de cuenta (PDF): distintos períodos, se abre en pestaña aparte -->
        <details class="bloque">
            <summary>Estado de cuenta (PDF)</summary>
            <div class="bloque-cuerpo">
                <p class="ec-intro">Documento imprimible para entregar o enviar por WhatsApp o correo.</p>
                <div class="ec-opciones">
                    <a class="ec-opcion" target="_blank"
                       href="estado_cuenta_imprimir.php?id=<?= (int)$id ?>&amp;desde=<?= $inicioSemana ?>&amp;hasta=<?= $hoy ?>">
                        <span class="ec-tit">Semana</span><span class="ec-sub">Últimos 7 días</span>
                    </a>
                    <a class="ec-opcion" target="_blank"
                       href="estado_cuenta_imprimir.php?id=<?= (int)$id ?>&amp;desde=<?= $inicioMes ?>&amp;hasta=<?= $hoy ?>">
                        <span class="ec-tit">Mes actual</span><span class="ec-sub">Desde el día 1</span>
                    </a>
                    <a class="ec-opcion ec-destacada" target="_blank"
                       href="estado_cuenta_imprimir.php?id=<?= (int)$id ?>">
                        <span class="ec-tit">Historial completo</span><span class="ec-sub">Todos los movimientos</span>
                    </a>
                </div>
                <details class="ec-rango">
                    <summary>Otro rango de fechas</summary>
                    <form method="get" action="estado_cuenta_imprimir.php" target="_blank" class="form-rango">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <div class="campo"><label>Desde</label>
                            <input type="date" name="desde" value="<?= $inicioMes ?>" required></div>
                        <div class="campo"><label>Hasta</label>
                            <input type="date" name="hasta" value="<?= $hoy ?>" required></div>
                        <button class="btn gris" type="submit">Generar</button>
                    </form>
                </details>
            </div>
        </details>

        <!-- Datos de contacto (desplegable) -->
        <details class="bloque">
            <summary>Datos de contacto</summary>
            <div class="bloque-cuerpo">
                <div class="contacto-grid">
                    <div class="dg-item"><span class="dg-label">Negocio</span><span class="dg-valor"><?= e($cliente['negocio_nombre']) ?></span></div>
                    <div class="dg-item"><span class="dg-label">Teléfono</span><span class="dg-valor"><?= e($cliente['telefono']) ?: '—' ?></span></div>
                    <div class="dg-item"><span class="dg-label">Dirección</span><span class="dg-valor"><?= e($cliente['direccion']) ?: '—' ?></span></div>
                    <div class="dg-item"><span class="dg-label">Correo</span><span class="dg-valor"><?= e($cliente['correo']) ?: '—' ?></span></div>
                </div>
                <div class="acciones-rapidas centrado" style="margin-top:16px">
                    <a class="btn gris" href="cliente_form.php?id=<?= (int)$id ?>">Editar cliente</a>
                    <?php if ($esAdmin): ?>
                    <form method="post" class="inline-form"
                          onsubmit="return confirm('¿Eliminar este cliente y TODO su historial? Esta acción no se puede deshacer.')">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="eliminar_cliente">
                        <button class="btn rojo" type="submit">Eliminar cliente</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <!-- Movimientos: fiados y abonos en una sola línea de tiempo (solo la tabla) -->
        <div class="tabla-wrap">
                <table class="tabla-mov">
                    <thead><tr>
                        <th>Fecha</th><th>Tipo</th><th class="col-detalle">Detalle</th>
                        <th class="monto-col">Monto</th>
                        <?php if ($esAdmin): ?><th class="col-accion">Acción</th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php if (!$movs): ?>
                        <tr><td class="vacio" colspan="<?= $esAdmin ? 5 : 4 ?>">Sin movimientos. Registra un fiado o un abono arriba.</td></tr>
                    <?php else: foreach ($movs as $m): $esFiado = $m['tipo'] === 'fiado'; ?>
                        <?php $anio = substr($m['fecha'], 0, 4); $diaMes = substr(fecha_dmy($m['fecha']), 0, 5); ?>
                        <tr>
                            <td class="fecha-col"><span class="fc-dm"><?= e($diaMes) ?></span><span class="fc-anio"><?= e($anio) ?></span></td>
                            <td><span class="mov-badge <?= $esFiado ? 'mov-fiado' : 'mov-abono' ?>"><?= $esFiado ? 'Fiado' : 'Abono' ?></span></td>
                            <td class="col-detalle"><?= e($m['texto']) ?: '—' ?></td>
                            <td class="monto-col <?= $esFiado ? 'saldo-deuda' : 'saldo-ok' ?>"><?= ($esFiado ? '+' : '−') . clp($m['monto']) ?></td>
                            <?php if ($esAdmin): ?>
                            <td class="col-accion">
                                <span class="acciones-fila">
                                    <a class="btn-icono" title="Editar" aria-label="Editar"
                                       href="cliente.php?id=<?= (int)$id ?>&amp;<?= $esFiado ? 'editar_fiado' : 'editar_abono' ?>=<?= (int)$m['id'] ?>#registrar"><?= icono('lapiz') ?></a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar este movimiento?')">
                                        <?= campo_csrf() ?>
                                        <input type="hidden" name="accion" value="<?= $esFiado ? 'eliminar_fiado' : 'eliminar_abono' ?>">
                                        <input type="hidden" name="ref_id" value="<?= (int)$m['id'] ?>">
                                        <button class="btn-icono peligro" type="submit" title="Eliminar" aria-label="Eliminar"><?= icono('basurero') ?></button>
                                    </form>
                                </span>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
        </div>
    </div>
    <?php pie_dashboard(); ?>
