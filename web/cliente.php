<?php
/**
 * Ficha de un cliente de fiados: datos de contacto, saldo actual (cuenta
 * corriente), y el historial de fiados y abonos con sus formularios de registro.
 * Acceso admin+sucursal, validando el negocio del cliente.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/auditoria.php';

$usuario = exigir_permiso('fiados');
$pdo = obtener_pdo();

// Solo el administrador puede editar/eliminar movimientos o eliminar clientes.
// El vendedor únicamente registra; si se equivoca, pide al admin que corrija.
$esAdmin = ($usuario['rol'] ?? '') === 'admin';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdo->prepare(
    "SELECT c.*, n.nombre AS negocio_nombre
     FROM clientes c JOIN negocios n ON n.id = c.negocio_id
     WHERE c.id = ?"
);
$st->execute([$id]);
$cliente = $st->fetch();
if (!$cliente || !puede_ver_negocio($usuario, (int)$cliente['negocio_id'])) {
    header('Location: fiados.php');
    exit;
}

$error = '';
$accionError = '';   // qué formulario falló (para dejarlo abierto)

// --- Acciones POST ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $nombreCliente = trim($cliente['nombre'] . ' ' . $cliente['apellido']);

    // Editar/eliminar son solo para admin: un vendedor no puede llegar aquí por
    // la UI, pero igual se bloquea en el backend (defensa en profundidad).
    $accionesAdmin = ['editar_fiado', 'editar_abono', 'eliminar_fiado', 'eliminar_abono', 'eliminar_cliente'];
    if (in_array($accion, $accionesAdmin, true) && !$esAdmin) {
        header('Location: cliente.php?id=' . $id);
        exit;
    }

    if (in_array($accion, ['nuevo_fiado', 'nuevo_abono', 'editar_fiado', 'editar_abono'], true)) {
        $accionError = $accion;
        $fecha = trim($_POST['fecha'] ?? '');
        $monto = parsear_monto($_POST['monto'] ?? '');
        $texto = trim($_POST['texto'] ?? '');
        $texto = $texto !== '' ? $texto : null;
        $refId = (int)($_POST['ref_id'] ?? 0);
        if ($monto === null || $monto <= 0) {
            $error = 'Ingresa un monto válido mayor a 0.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $error = 'La fecha no es válida.';
        } else {
            switch ($accion) {
                case 'nuevo_fiado':
                    $pdo->prepare("INSERT INTO fiados (cliente_id, fecha, monto, descripcion) VALUES (?,?,?,?)")
                        ->execute([$id, $fecha, $monto, $texto]);
                    registrar_auditoria($pdo, $usuario, 'fiados', 'crear_fiado', $id,
                        "Registró fiado de " . clp($monto) . " a $nombreCliente" . ($texto ? " ($texto)" : ''));
                    break;
                case 'nuevo_abono':
                    $pdo->prepare("INSERT INTO abonos (cliente_id, fecha, monto, nota) VALUES (?,?,?,?)")
                        ->execute([$id, $fecha, $monto, $texto]);
                    registrar_auditoria($pdo, $usuario, 'fiados', 'crear_abono', $id,
                        "Registró abono de " . clp($monto) . " de $nombreCliente" . ($texto ? " ($texto)" : ''));
                    break;
                case 'editar_fiado':  // solo actualiza si el fiado es de este cliente
                    $ant = $pdo->prepare("SELECT fecha, monto, descripcion FROM fiados WHERE id=? AND cliente_id=?");
                    $ant->execute([$refId, $id]); $ant = $ant->fetch();
                    $pdo->prepare("UPDATE fiados SET fecha=?, monto=?, descripcion=? WHERE id=? AND cliente_id=?")
                        ->execute([$fecha, $monto, $texto, $refId, $id]);
                    if ($ant) registrar_auditoria($pdo, $usuario, 'fiados', 'editar_fiado', $id,
                        "Editó fiado de $nombreCliente (" . clp((float)$ant['monto']) . " → " . clp($monto) . ")",
                        json_encode(['antes' => $ant, 'despues' => ['fecha' => $fecha, 'monto' => $monto, 'descripcion' => $texto]], JSON_UNESCAPED_UNICODE));
                    break;
                case 'editar_abono':
                    $ant = $pdo->prepare("SELECT fecha, monto, nota FROM abonos WHERE id=? AND cliente_id=?");
                    $ant->execute([$refId, $id]); $ant = $ant->fetch();
                    $pdo->prepare("UPDATE abonos SET fecha=?, monto=?, nota=? WHERE id=? AND cliente_id=?")
                        ->execute([$fecha, $monto, $texto, $refId, $id]);
                    if ($ant) registrar_auditoria($pdo, $usuario, 'fiados', 'editar_abono', $id,
                        "Editó abono de $nombreCliente (" . clp((float)$ant['monto']) . " → " . clp($monto) . ")",
                        json_encode(['antes' => $ant, 'despues' => ['fecha' => $fecha, 'monto' => $monto, 'nota' => $texto]], JSON_UNESCAPED_UNICODE));
                    break;
            }
            header('Location: cliente.php?id=' . $id);
            exit;
        }
    } elseif ($accion === 'eliminar_fiado') {
        $refId = (int)($_POST['ref_id'] ?? 0);
        $reg = $pdo->prepare("SELECT fecha, monto, descripcion FROM fiados WHERE id=? AND cliente_id=?");
        $reg->execute([$refId, $id]); $reg = $reg->fetch();
        $pdo->prepare("DELETE FROM fiados WHERE id=? AND cliente_id=?")->execute([$refId, $id]);
        if ($reg) registrar_auditoria($pdo, $usuario, 'fiados', 'eliminar_fiado', $id,
            "Eliminó fiado de " . clp((float)$reg['monto']) . " de $nombreCliente (" . fecha_dmy($reg['fecha']) . ")",
            json_encode($reg, JSON_UNESCAPED_UNICODE));
        header('Location: cliente.php?id=' . $id);
        exit;
    } elseif ($accion === 'eliminar_abono') {
        $refId = (int)($_POST['ref_id'] ?? 0);
        $reg = $pdo->prepare("SELECT fecha, monto, nota FROM abonos WHERE id=? AND cliente_id=?");
        $reg->execute([$refId, $id]); $reg = $reg->fetch();
        $pdo->prepare("DELETE FROM abonos WHERE id=? AND cliente_id=?")->execute([$refId, $id]);
        if ($reg) registrar_auditoria($pdo, $usuario, 'fiados', 'eliminar_abono', $id,
            "Eliminó abono de " . clp((float)$reg['monto']) . " de $nombreCliente (" . fecha_dmy($reg['fecha']) . ")",
            json_encode($reg, JSON_UNESCAPED_UNICODE));
        header('Location: cliente.php?id=' . $id);
        exit;
    } elseif ($accion === 'eliminar_cliente') {
        registrar_auditoria($pdo, $usuario, 'fiados', 'eliminar_cliente', $id,
            "Eliminó al cliente $nombreCliente y todo su historial de fiados/abonos");
        $pdo->prepare("DELETE FROM clientes WHERE id=?")->execute([$id]);  // cascade borra historial
        header('Location: fiados.php');
        exit;
    }
}

// --- Datos para mostrar ---
$totalFiado = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM fiados WHERE cliente_id=" . (int)$id)->fetchColumn();
$totalAbono = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM abonos WHERE cliente_id=" . (int)$id)->fetchColumn();
$saldo = $totalFiado - $totalAbono;

$stf = $pdo->prepare("SELECT * FROM fiados WHERE cliente_id=? ORDER BY fecha DESC, id DESC");
$stf->execute([$id]);
$fiados = $stf->fetchAll();
$sta = $pdo->prepare("SELECT * FROM abonos WHERE cliente_id=? ORDER BY fecha DESC, id DESC");
$sta->execute([$id]);
$abonos = $sta->fetchAll();

// Fusionar fiados y abonos en una sola línea de tiempo de movimientos
$movs = [];
foreach ($fiados as $f) {
    $movs[] = ['tipo' => 'fiado', 'id' => (int)$f['id'], 'fecha' => $f['fecha'],
               'texto' => $f['descripcion'], 'monto' => (float)$f['monto'], 'creado' => $f['creado_en']];
}
foreach ($abonos as $a) {
    $movs[] = ['tipo' => 'abono', 'id' => (int)$a['id'], 'fecha' => $a['fecha'],
               'texto' => $a['nota'], 'monto' => (float)$a['monto'], 'creado' => $a['creado_en']];
}
usort($movs, fn($x, $y) => [$y['fecha'], $y['creado']] <=> [$x['fecha'], $x['creado']]);

$nombreCompleto = trim($cliente['nombre'] . ' ' . $cliente['apellido']);
$hoy = date('Y-m-d');
$claseSaldo = $saldo > 0 ? 'saldo-deuda' : 'saldo-ok';

// Rangos precalculados para el estado de cuenta (PDF)
$inicioSemana = date('Y-m-d', strtotime('-6 days'));  // últimos 7 días (hoy incluido)
$inicioMes    = date('Y-m-01');

// ¿Estamos editando un movimiento? (por enlace GET, o por un POST que falló)
$editFiado = null;
$editAbono = null;
$cargarEdit = function (string $tabla, int $mid, string $campoTexto) use ($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM $tabla WHERE id=? AND cliente_id=?");
    $st->execute([$mid, $id]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'fecha' => $r['fecha'],
                 'monto' => number_format((float)$r['monto'], 0, ',', '.'),
                 'texto' => $r[$campoTexto]] : null;
};
if ($accionError === 'editar_fiado' && $error !== '') {
    $editFiado = ['id' => (int)($_POST['ref_id'] ?? 0), 'fecha' => $_POST['fecha'] ?? $hoy,
                  'monto' => $_POST['monto'] ?? '', 'texto' => $_POST['texto'] ?? ''];
} elseif ($esAdmin && isset($_GET['editar_fiado'])) {
    $editFiado = $cargarEdit('fiados', (int)$_GET['editar_fiado'], 'descripcion');
}
if ($accionError === 'editar_abono' && $error !== '') {
    $editAbono = ['id' => (int)($_POST['ref_id'] ?? 0), 'fecha' => $_POST['fecha'] ?? $hoy,
                  'monto' => $_POST['monto'] ?? '', 'texto' => $_POST['texto'] ?? ''];
} elseif ($esAdmin && isset($_GET['editar_abono'])) {
    $editAbono = $cargarEdit('abonos', (int)$_GET['editar_abono'], 'nota');
}
$abrirFiado = $editFiado !== null || $accionError === 'nuevo_fiado';
$abrirAbono = $editAbono !== null || $accionError === 'nuevo_abono';
?>
<?php cabecera_dashboard($usuario, 'fiados', $nombreCompleto); ?>

    <div class="contenido">
        <div class="cab-modulo modulo-morado">
            <div class="cab-modulo-tit">
                <span class="modulo-badge"><?= icono('fiados') ?></span>
                <h2><?= h($nombreCompleto) ?></h2>
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

            <?php if ($error): ?><div class="error" style="margin-top:12px"><?= h($error) ?></div><?php endif; ?>

            <div class="acciones-cc" id="registrar">
                <details class="accion-cc" <?= $abrirFiado ? 'open' : '' ?>>
                    <summary class="btn"><?= $editFiado ? 'Editar fiado' : '+ Registrar fiado' ?></summary>
                    <form class="form-cc" method="post">
                        <input type="hidden" name="accion" value="<?= $editFiado ? 'editar_fiado' : 'nuevo_fiado' ?>">
                        <?php if ($editFiado): ?><input type="hidden" name="ref_id" value="<?= (int)$editFiado['id'] ?>"><?php endif; ?>
                        <div class="campo" style="margin-bottom:10px"><label>Fecha</label>
                            <input type="date" name="fecha" value="<?= h($editFiado['fecha'] ?? $hoy) ?>" required></div>
                        <div class="campo" style="margin-bottom:10px"><label>Monto *</label>
                            <input type="text" name="monto" inputmode="numeric" placeholder="Ej: 5.000"
                                   value="<?= h($editFiado['monto'] ?? '') ?>" required></div>
                        <div class="campo" style="margin-bottom:10px"><label>Descripción</label>
                            <input type="text" name="texto" placeholder="Qué se fió (opcional)"
                                   value="<?= h($editFiado['texto'] ?? '') ?>"></div>
                        <button class="btn" type="submit"><?= $editFiado ? 'Guardar cambios' : 'Agregar fiado' ?></button>
                        <?php if ($editFiado): ?><a class="btn gris" href="cliente.php?id=<?= (int)$id ?>">Cancelar</a><?php endif; ?>
                    </form>
                </details>
                <details class="accion-cc" <?= $abrirAbono ? 'open' : '' ?>>
                    <summary class="btn verde"><?= $editAbono ? 'Editar abono' : '+ Registrar abono' ?></summary>
                    <form class="form-cc" method="post">
                        <input type="hidden" name="accion" value="<?= $editAbono ? 'editar_abono' : 'nuevo_abono' ?>">
                        <?php if ($editAbono): ?><input type="hidden" name="ref_id" value="<?= (int)$editAbono['id'] ?>"><?php endif; ?>
                        <div class="campo" style="margin-bottom:10px"><label>Fecha</label>
                            <input type="date" name="fecha" value="<?= h($editAbono['fecha'] ?? $hoy) ?>" required></div>
                        <div class="campo" style="margin-bottom:10px"><label>Monto *</label>
                            <input type="text" name="monto" inputmode="numeric" placeholder="Ej: 2.000"
                                   value="<?= h($editAbono['monto'] ?? '') ?>" required></div>
                        <div class="campo" style="margin-bottom:10px"><label>Nota</label>
                            <input type="text" name="texto" placeholder="Observación (opcional)"
                                   value="<?= h($editAbono['texto'] ?? '') ?>"></div>
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
                    <div class="dg-item"><span class="dg-label">Negocio</span><span class="dg-valor"><?= h($cliente['negocio_nombre']) ?></span></div>
                    <div class="dg-item"><span class="dg-label">Teléfono</span><span class="dg-valor"><?= h($cliente['telefono']) ?: '—' ?></span></div>
                    <div class="dg-item"><span class="dg-label">Dirección</span><span class="dg-valor"><?= h($cliente['direccion']) ?: '—' ?></span></div>
                    <div class="dg-item"><span class="dg-label">Correo</span><span class="dg-valor"><?= h($cliente['correo']) ?: '—' ?></span></div>
                </div>
                <div class="acciones-rapidas centrado" style="margin-top:16px">
                    <a class="btn gris" href="cliente_form.php?id=<?= (int)$id ?>">Editar cliente</a>
                    <?php if ($esAdmin): ?>
                    <form method="post" class="inline-form"
                          onsubmit="return confirm('¿Eliminar este cliente y TODO su historial? Esta acción no se puede deshacer.')">
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
                            <td class="fecha-col"><span class="fc-dm"><?= h($diaMes) ?></span><span class="fc-anio"><?= h($anio) ?></span></td>
                            <td><span class="mov-badge <?= $esFiado ? 'mov-fiado' : 'mov-abono' ?>"><?= $esFiado ? 'Fiado' : 'Abono' ?></span></td>
                            <td class="col-detalle"><?= h($m['texto']) ?: '—' ?></td>
                            <td class="monto-col <?= $esFiado ? 'saldo-deuda' : 'saldo-ok' ?>"><?= ($esFiado ? '+' : '−') . clp($m['monto']) ?></td>
                            <?php if ($esAdmin): ?>
                            <td class="col-accion">
                                <span class="acciones-fila">
                                    <a class="btn-icono" title="Editar" aria-label="Editar"
                                       href="cliente.php?id=<?= (int)$id ?>&amp;<?= $esFiado ? 'editar_fiado' : 'editar_abono' ?>=<?= (int)$m['id'] ?>#registrar"><?= icono('lapiz') ?></a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('¿Eliminar este movimiento?')">
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
