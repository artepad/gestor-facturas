<?php
/**
 * Hoja imprimible con la lista completa de Frutas y Verduras — para pegar en
 * el local o el mesón, no etiquetas individuales (esas ya existen en
 * Herramientas → Etiquetas). Página "desnuda", mismo patrón que
 * etiquetas_imprimir.php / ofertas_imprimir.php.
 *
 * Variable disponible: $productos (de PrecioFV::activos()).
 */
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fmt = fn($v) => number_format((float)$v, 0, ',', '.');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Precios · Frutas y verduras</title>
<style>
    @page { margin: 1cm; size: A4 portrait; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif; background: #eef1f4;
        color: #222; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    .barra { background: #1b5e20; color: #fff; display: flex; align-items: center;
        gap: 14px; padding: 12px 20px; position: sticky; top: 0; }
    .barra h1 { font-size: 16px; font-weight: 600; margin-right: auto; }
    .barra button, .barra a { font: inherit; font-weight: 600; border: none; border-radius: 6px;
        padding: 9px 18px; cursor: pointer; text-decoration: none; }
    .barra .b-print { background: #2e7d32; color: #fff; }
    .barra .b-print:hover { background: #1b5e20; }
    .barra .b-volver { background: #6c757d; color: #fff; }

    .hoja { background: #fff; max-width: 21cm; margin: 16px auto; padding: 1cm;
        box-shadow: 0 2px 12px rgba(0,0,0,.12); }
    .page-title { text-align: center; font-size: 24px; font-weight: bold; color: #1b5e20;
        margin-bottom: 0.2cm; }
    .page-fecha { text-align: center; font-size: 12px; color: #666; margin-bottom: 0.6cm; }

    .lista { display: grid; grid-template-columns: 1fr 1fr; gap: 0.3cm 0.8cm; }
    .fila { display: flex; justify-content: space-between; align-items: baseline;
        border-bottom: 1px dashed #ccc; padding: 0.2cm 0; page-break-inside: avoid; }
    .nombre { font-weight: 600; }
    .obs { font-size: 11px; color: #777; font-weight: 400; }
    .precio { font-weight: 800; color: #1b5e20; white-space: nowrap; margin-left: 0.4cm; }
    .unidad { font-size: 11px; color: #666; font-weight: 400; }

    @media print {
        body { background: #fff; }
        .barra { display: none; }
        .hoja { box-shadow: none; margin: 0; max-width: none; padding: 0; }
    }
</style>
</head>
<body>
    <div class="barra">
        <h1>Precios · Frutas y verduras</h1>
        <?php if ($productos): ?>
            <button class="b-print" onclick="window.print()">Imprimir / Guardar como PDF</button>
        <?php endif; ?>
        <a class="b-volver" href="pos.php">Volver a Venta</a>
    </div>

    <?php if (!$productos): ?>
        <div class="hoja"><p>No hay productos cargados todavía.</p></div>
    <?php else: ?>
    <div class="hoja">
        <div class="page-title">Frutas y verduras</div>
        <div class="page-fecha">Impreso el <?= h(date('d-m-Y H:i')) ?></div>
        <div class="lista">
            <?php foreach ($productos as $p): ?>
                <div class="fila">
                    <span class="nombre">
                        <?= h($p['nombre']) ?>
                        <?php if (!empty($p['observacion'])): ?><br><span class="obs"><?= h($p['observacion']) ?></span><?php endif; ?>
                    </span>
                    <span class="precio">$<?= $fmt($p['precio']) ?> <span class="unidad">/ <?= h($p['unidad']) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
