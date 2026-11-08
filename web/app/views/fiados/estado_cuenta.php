<?php
/**
 * Vista de app/Controllers/FiadosController::estadoCuenta().
 *
 * Página "desnuda" (sin el layout del dashboard), pensada para imprimir o
 * "Guardar como PDF" desde el navegador. Mismo patrón que precios_fv/imprimir.
 *
 * Variables disponibles: $id, $cliente, $desde, $hasta, $esHistorial,
 * $etiquetaPeriodo, $movs, $totalCompras, $totalAbonos, $saldoAnterior,
 * $saldoFinal, $nombreCompleto, $hoy.
 */

require_once dirname(__DIR__, 3) . '/lib/ui.php'; // e()/h(), icono(), clp(), fecha_dmy()
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estado de cuenta · <?= e($nombreCompleto) ?></title>
    <style>
        @page { margin: 1.2cm; size: A4 portrait; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif; background: #eef1f4;
            color: #1f2733; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* Barra superior (solo en pantalla, no se imprime) */
        .barra { background: #2c3e50; color: #fff; display: flex; align-items: center;
            gap: 14px; padding: 12px 20px; position: sticky; top: 0; z-index: 5; }
        .barra h1 { font-size: 16px; font-weight: 600; margin-right: auto; }
        .barra button, .barra a { font: inherit; font-weight: 600; border: none; border-radius: 6px;
            padding: 9px 18px; cursor: pointer; text-decoration: none; }
        .barra .b-print { background: #6d28d9; color: #fff; }
        .barra .b-print:hover { background: #5b21b6; }
        .barra .b-volver { background: #6c757d; color: #fff; }

        .hoja { background: #fff; max-width: 21cm; margin: 16px auto; padding: 1.4cm 1.4cm 1.1cm;
            box-shadow: 0 2px 12px rgba(0,0,0,.12); }

        /* Encabezado: negocio + documento */
        .enc { display: flex; justify-content: space-between; align-items: flex-start;
            gap: 20px; border-bottom: 3px solid #6d28d9; padding-bottom: 16px; }
        .neg { display: flex; gap: 14px; align-items: center; }
        .neg-logo { width: 54px; height: 54px; border-radius: 12px; background: #6d28d9;
            color: #fff; display: flex; align-items: center; justify-content: center; flex: none; }
        .neg-logo svg { width: 30px; height: 30px; }
        .neg-nombre { font-size: 22px; font-weight: 800; color: #2c3e50; line-height: 1.1; }
        .neg-datos { font-size: 12px; color: #64748b; margin-top: 3px; line-height: 1.5; }
        .doc { text-align: right; }
        .doc-tit { font-size: 15px; font-weight: 800; color: #6d28d9; letter-spacing: 1px;
            text-transform: uppercase; }
        .doc-emision { font-size: 12px; color: #64748b; margin-top: 4px; }
        .doc-periodo { display: inline-block; margin-top: 8px; font-size: 12px; font-weight: 700;
            color: #5b21b6; background: #f3ecfd; border: 1px solid #e2d4fb;
            border-radius: 999px; padding: 4px 12px; }

        /* Datos del cliente */
        .cli { margin-top: 18px; background: #f8fafc; border: 1px solid #e6ebf1;
            border-radius: 10px; padding: 14px 18px; display: grid;
            grid-template-columns: repeat(2, 1fr); gap: 6px 24px; }
        .cli .campo { font-size: 12.5px; color: #475569; }
        .cli .campo b { color: #1f2733; font-weight: 700; }
        .cli .cli-nombre { grid-column: 1 / -1; font-size: 16px; font-weight: 800; color: #2c3e50; }

        /* Tablas de movimientos */
        h3.sec { margin: 22px 0 8px; font-size: 14px; color: #2c3e50; font-weight: 800;
            display: flex; align-items: center; gap: 8px; }
        h3.sec .pill { font-size: 11px; font-weight: 700; color: #6d28d9;
            background: #f3ecfd; border-radius: 999px; padding: 2px 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        thead th { text-align: left; background: #2c3e50; color: #fff; font-weight: 600;
            padding: 8px 12px; }
        thead th.num { text-align: right; }
        tbody td { padding: 8px 12px; border-bottom: 1px solid #eef1f4; }
        tbody td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tbody tr:nth-child(even) td { background: #fafbfc; }
        .vacio { color: #94a3b8; font-style: italic; text-align: center; padding: 14px; }
        tfoot td { padding: 9px 12px; font-weight: 800; border-top: 2px solid #2c3e50; }
        tfoot td.num { text-align: right; }
        /* Badges de tipo de movimiento */
        .badge { display: inline-block; font-size: 11px; font-weight: 700; border-radius: 999px;
            padding: 2px 10px; white-space: nowrap; }
        .b-fiado { background: #fde8e8; color: #b91c1c; }
        .b-abono { background: #e7f6ec; color: #15803d; }
        td.cargo { color: #b91c1c; } tfoot td.cargo { color: #b91c1c; }
        td.abono { color: #15803d; } tfoot td.abono { color: #15803d; }
        td.saldo-run { font-weight: 700; color: #2c3e50; }
        .fila-anterior td { color: #64748b; font-style: italic; background: #f8fafc !important; }

        /* Resumen final */
        .resumen { margin-top: 24px; display: flex; justify-content: flex-end; }
        .resumen-tabla { width: 320px; max-width: 100%; }
        .resumen-tabla .fila { display: flex; justify-content: space-between;
            padding: 7px 4px; font-size: 13px; border-bottom: 1px solid #eef1f4; }
        .resumen-tabla .fila span:last-child { font-variant-numeric: tabular-nums; }
        .resumen-tabla .compras span:last-child { color: #b91c1c; font-weight: 700; }
        .resumen-tabla .abonos span:last-child { color: #15803d; font-weight: 700; }
        .resumen-tabla .saldo { margin-top: 6px; background: #2c3e50; color: #fff;
            border-radius: 10px; padding: 14px 16px; display: flex; justify-content: space-between;
            align-items: center; border: none; }
        .resumen-tabla .saldo .lbl { font-size: 13px; font-weight: 700; }
        .resumen-tabla .saldo .val { font-size: 22px; font-weight: 800; }
        .saldo.a-favor { background: #15803d; }
        .saldo.al-dia { background: #475569; }

        .nota-saldo { text-align: right; font-size: 11px; color: #94a3b8; margin-top: 8px; }

        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { box-shadow: none; margin: 0; max-width: none; padding: 0; }
        }

        /* Móvil: la hoja se adapta al ancho y la tabla puede desplazarse.
           Igualmente en móvil se abre el diálogo de guardar/compartir al cargar. */
        @media (max-width: 640px) {
            body { background: #fff; }
            .hoja { margin: 0; max-width: 100%; padding: 0.7cm 0.6cm; box-shadow: none; }
            .enc { flex-direction: column; gap: 12px; }
            .doc { text-align: left; }
            table { font-size: 12px; }
            thead th, tbody td, tfoot td { padding: 7px 8px; }
        }
    </style>
</head>
<body>
    <div class="barra">
        <h1>Estado de cuenta · <?= e($nombreCompleto) ?></h1>
        <button class="b-print" onclick="window.print()">Imprimir / Guardar como PDF</button>
        <a class="b-volver" href="cliente.php?id=<?= (int)$id ?>">Volver</a>
    </div>

    <div class="hoja">
        <!-- Encabezado -->
        <div class="enc">
            <div class="neg">
                <span class="neg-logo"><?= icono('negocios') ?></span>
                <div>
                    <div class="neg-nombre"><?= e($cliente['negocio_nombre']) ?></div>
                    <div class="neg-datos">
                        <?php if ($cliente['negocio_rut']): ?>RUT: <?= e($cliente['negocio_rut']) ?><br><?php endif; ?>
                        <?php if ($cliente['negocio_direccion']): ?><?= e($cliente['negocio_direccion']) ?><br><?php endif; ?>
                        <?php if ($cliente['negocio_telefono']): ?>Tel: <?= e($cliente['negocio_telefono']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="doc">
                <div class="doc-tit">Estado de cuenta</div>
                <div class="doc-emision">Emitido: <?= e(fecha_dmy($hoy)) ?></div>
                <div class="doc-periodo"><?= e($etiquetaPeriodo) ?></div>
            </div>
        </div>

        <!-- Datos del cliente -->
        <div class="cli">
            <div class="campo cli-nombre"><?= e($nombreCompleto) ?></div>
            <?php if ($cliente['telefono']): ?><div class="campo">Teléfono: <b><?= e($cliente['telefono']) ?></b></div><?php endif; ?>
            <?php if ($cliente['correo']): ?><div class="campo">Correo: <b><?= e($cliente['correo']) ?></b></div><?php endif; ?>
        </div>

        <!-- Movimientos: fiados y abonos en una sola línea de tiempo con saldo -->
        <h3 class="sec">Movimientos de la cuenta <span class="pill"><?= count($movs) ?></span></h3>
        <table class="t-movs">
            <thead><tr>
                <th>Fecha</th><th>Movimiento</th><th>Detalle</th>
                <th class="num">Cargo</th><th class="num">Abono</th><th class="num">Saldo</th>
            </tr></thead>
            <tbody>
            <?php $saldoRun = $saldoAnterior; ?>
            <?php if ($desde): ?>
                <tr class="fila-anterior">
                    <td class="num" style="text-align:left"><?= e(fecha_dmy($desde)) ?></td>
                    <td colspan="4">Saldo anterior</td>
                    <td class="num"><?= clp($saldoAnterior) ?></td>
                </tr>
            <?php endif; ?>
            <?php if (!$movs && !$desde): ?>
                <tr><td class="vacio" colspan="6">Sin movimientos en este período.</td></tr>
            <?php else: foreach ($movs as $m): $esFiado = $m['tipo'] === 'fiado';
                    $saldoRun += $esFiado ? $m['monto'] : -$m['monto']; ?>
                <tr>
                    <td class="num" style="text-align:left"><?= e(fecha_dmy($m['fecha'])) ?></td>
                    <td><span class="badge <?= $esFiado ? 'b-fiado' : 'b-abono' ?>"><?= $esFiado ? 'Fiado' : 'Abono' ?></span></td>
                    <td><?= e($m['detalle']) ?: '—' ?></td>
                    <td class="num cargo"><?= $esFiado ? clp($m['monto']) : '' ?></td>
                    <td class="num abono"><?= $esFiado ? '' : clp($m['monto']) ?></td>
                    <td class="num saldo-run"><?= clp($saldoRun) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot><tr>
                <td colspan="3">Totales</td>
                <td class="num cargo"><?= clp($totalCompras) ?></td>
                <td class="num abono"><?= clp($totalAbonos) ?></td>
                <td class="num"><?= clp($saldoFinal) ?></td>
            </tr></tfoot>
        </table>

        <!-- Resumen final -->
        <?php
            $claseSaldo = $saldoFinal > 0 ? '' : ($saldoFinal < 0 ? 'a-favor' : 'al-dia');
            $lblSaldo   = $saldoFinal > 0 ? 'Saldo pendiente' : ($saldoFinal < 0 ? 'Saldo a favor' : 'Cuenta al día');
        ?>
        <div class="resumen">
            <div class="resumen-tabla">
                <div class="fila compras"><span>Total compras fiadas</span><span><?= clp($totalCompras) ?></span></div>
                <div class="fila abonos"><span>Total abonos</span><span><?= clp($totalAbonos) ?></span></div>
                <div class="fila saldo <?= $claseSaldo ?>">
                    <span class="lbl"><?= $lblSaldo ?></span>
                    <span class="val"><?= clp(abs($saldoFinal)) ?></span>
                </div>
            </div>
        </div>
        <?php if ($hasta && $hasta < $hoy): ?>
            <div class="nota-saldo">Saldo calculado al <?= e(fecha_dmy($hasta)) ?>. Puede haber movimientos posteriores.</div>
        <?php endif; ?>
    </div>

    <script>
        // En móvil, abrir directo el diálogo de Imprimir / Guardar como PDF
        // (el celular ofrece guardar el PDF o compartirlo por WhatsApp/correo).
        // En computador se deja el botón de la barra, sin abrir nada automático.
        if (window.matchMedia('(max-width: 640px)').matches) {
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 500);
            });
        }
    </script>
</body>
</html>
