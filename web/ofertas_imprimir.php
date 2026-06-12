<?php
/**
 * Hoja imprimible de etiquetas de OFERTA. Recibe por POST la cola de ofertas en
 * JSON (desde herramienta_ofertas.php) y arma una página A4 (4 etiquetas grandes)
 * lista para imprimir o guardar como PDF. Página "desnuda" para impresión limpia.
 * El diseño replica el de la app de escritorio (4 tipos: normal, %, cantidad, día).
 */

require __DIR__ . '/lib/auth.php';

$usuario = exigir_permiso('herramientas');

$cola = json_decode($_POST['ofertas'] ?? '[]', true);
if (!is_array($cola)) $cola = [];

// Normaliza, valida y recorta a 4
$ofertas = [];
foreach ($cola as $o) {
    $tipo = $o['tipo'] ?? '';
    $prod = trim((string)($o['producto'] ?? ''));
    if ($prod === '' || !in_array($tipo, ['normal', 'percentage', 'quantity', 'daily'], true)) continue;
    $ofertas[] = $o;
    if (count($ofertas) >= 4) break;
}
// Rellena hasta 4 con espacios vacíos (igual que la app)
while (count($ofertas) < 4) $ofertas[] = ['tipo' => 'empty'];

function fmt_precio($p) { return number_format(round((float)$p), 0, ',', '.'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$promos = ['OFERTA', 'IMPERDIBLE', 'APROVECHA', 'NO TE LO PIERDAS'];
$titulos = ['normal' => 'Oferta Especial', 'percentage' => 'Descuento',
            'quantity' => 'Oferta por Cantidad', 'daily' => 'Destacado del Día'];

$hayOfertas = count(array_filter($ofertas, fn($o) => ($o['tipo'] ?? '') !== 'empty')) > 0;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiquetas de ofertas</title>
    <style>
        @page { margin: 0.5cm; size: A4 portrait; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif; background: #eef1f4;
            color: #1a1a2e; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .barra { background: #2c3e50; color: #fff; display: flex; align-items: center;
            gap: 14px; padding: 12px 20px; position: sticky; top: 0; }
        .barra h1 { font-size: 16px; font-weight: 600; margin-right: auto; }
        .barra button, .barra a { font: inherit; font-weight: 600; border: none; border-radius: 6px;
            padding: 9px 18px; cursor: pointer; text-decoration: none; }
        .barra .b-print { background: #1565c0; color: #fff; }
        .barra .b-print:hover { background: #0d47a1; }
        .barra .b-volver { background: #6c757d; color: #fff; }
        .barra .aviso { color: #ffd9d9; font-size: 14px; }

        .hoja { background: #fff; max-width: 21cm; margin: 16px auto; padding: 0.6cm 0;
            box-shadow: 0 2px 12px rgba(0,0,0,.12); }
        .page-title { text-align: center; font-size: 22px; font-weight: 800; margin: 0.3cm 0 0.6cm;
            color: #1a1a2e; letter-spacing: 3px; text-transform: uppercase; }

        .offer-grid { display: grid; grid-template-columns: 7cm 7cm; grid-template-rows: repeat(2, 9.5cm);
            gap: 0.4cm; width: 14.8cm; margin: 0 auto; justify-content: center; }
        .offer-label { display: flex; flex-direction: column; background: #fff; height: 9.5cm;
            width: 100%; overflow: hidden; position: relative; page-break-inside: avoid; }
        .offer-header { padding: 0.3cm 0.5cm; flex-shrink: 0; display: flex; align-items: center;
            justify-content: space-between; }
        .offer-header-label { font-size: 0.32cm; font-weight: 800; letter-spacing: 2px;
            text-transform: uppercase; color: #fff; }
        .offer-header-badge { font-size: 0.28cm; font-weight: 700; color: #fff; opacity: .85; letter-spacing: 1px; }
        .offer-content { flex: 1; display: flex; flex-direction: column; align-items: center;
            justify-content: center; padding: 0.3cm 0.5cm; text-align: center; }
        .offer-footer { padding: 0.35cm 0.5cm; flex-shrink: 0; text-align: center; font-size: 0.50cm;
            font-weight: 900; letter-spacing: 2px; color: #fff; }

        .type-normal .offer-header, .type-normal .offer-footer { background: #c0392b; }
        .type-normal { border-left: 5px solid #c0392b; border-right: 5px solid #c0392b; }
        .type-percentage .offer-header, .type-percentage .offer-footer { background: #e67e22; }
        .type-percentage { border-left: 5px solid #e67e22; border-right: 5px solid #e67e22; }
        .type-quantity .offer-header, .type-quantity .offer-footer { background: #8e44ad; }
        .type-quantity { border-left: 5px solid #8e44ad; border-right: 5px solid #8e44ad; }
        .type-daily .offer-header, .type-daily .offer-footer { background: #2980b9; }
        .type-daily { border-left: 5px solid #2980b9; border-right: 5px solid #2980b9; }

        .product-name-offer { font-weight: 700; color: #2c3e50; font-size: 0.50cm; line-height: 1.35;
            max-height: 1.8cm; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; margin-bottom: 0.25cm; letter-spacing: .3px; }
        .price-before { font-size: 0.42cm; color: #95a5a6; text-decoration: line-through;
            font-weight: 600; margin-bottom: 0.15cm; }
        .price-now { font-size: 1.5cm; color: #1a1a2e; font-weight: 900;
            font-family: 'Arial Black', 'Impact', sans-serif; line-height: 1; }
        .price-symbol { font-size: 0.8cm; vertical-align: super; font-weight: 800; }

        .pct-badge { font-size: 1.8cm; color: #e67e22; font-weight: 900;
            font-family: 'Arial Black', 'Impact', sans-serif; line-height: 1; margin-bottom: 0.1cm; }
        .pct-label { font-size: 0.32cm; color: #e67e22; font-weight: 700; text-transform: uppercase;
            letter-spacing: 2px; margin-bottom: 0.2cm; }
        .pct-prices { display: flex; align-items: baseline; gap: 0.4cm; margin-top: 0.15cm; }
        .pct-price-before { font-size: 0.42cm; color: #95a5a6; text-decoration: line-through; font-weight: 600; }
        .pct-price-now { font-size: 1.1cm; color: #1a1a2e; font-weight: 900;
            font-family: 'Arial Black', 'Impact', sans-serif; }

        .qty-display { display: flex; align-items: baseline; gap: 0.2cm; margin: 0.2cm 0; }
        .qty-number { font-size: 1.8cm; color: #8e44ad; font-weight: 900;
            font-family: 'Arial Black', 'Impact', sans-serif; line-height: 1; }
        .qty-x { font-size: 0.8cm; color: #8e44ad; font-weight: 800; }
        .qty-price { font-size: 1.3cm; color: #1a1a2e; font-weight: 900;
            font-family: 'Arial Black', 'Impact', sans-serif; line-height: 1; }

        .daily-badge { font-size: 0.38cm; color: #2980b9; font-weight: 800; text-transform: uppercase;
            letter-spacing: 3px; border: 2px solid #2980b9; padding: 0.12cm 0.4cm; margin-bottom: 0.35cm; }
        .daily-price { font-size: 1.5cm; color: #1a1a2e; font-weight: 900;
            font-family: 'Arial Black', 'Impact', sans-serif; line-height: 1; margin-top: 0.2cm; }

        .empty-offer { border: 2px dashed #dee2e6; background: #f8f9fa; color: #adb5bd;
            font-style: italic; align-items: center; justify-content: center; font-size: 0.40cm; }

        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { box-shadow: none; margin: 0; max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="barra">
        <h1>Etiquetas de ofertas</h1>
        <?php if ($hayOfertas): ?>
            <button class="b-print" onclick="window.print()">Imprimir / Guardar como PDF</button>
        <?php else: ?>
            <span class="aviso">No agregaste ofertas válidas.</span>
        <?php endif; ?>
        <a class="b-volver" href="herramienta_ofertas.php">Volver</a>
    </div>

    <?php if ($hayOfertas): ?>
    <div class="hoja">
        <div class="page-title">Etiquetas de Ofertas</div>
        <div class="offer-grid">
            <?php foreach ($ofertas as $o):
                $tipo = $o['tipo'];
                if ($tipo === 'empty') {
                    echo '<div class="offer-label empty-offer"><div>Espacio<br>disponible</div></div>';
                    continue;
                }
                $promo = $promos[array_rand($promos)];
                $prod = $h($o['producto'] ?? '');
            ?>
            <div class="offer-label type-<?= $h($tipo) ?>">
                <div class="offer-header">
                    <span class="offer-header-label"><?= $h($titulos[$tipo]) ?></span>
                    <?php if ($tipo === 'percentage'): ?>
                        <span class="offer-header-badge"><?= $h($o['porcentaje'] ?? '') ?> OFF</span>
                    <?php endif; ?>
                </div>
                <div class="offer-content">
                    <?php if ($tipo === 'normal'): ?>
                        <div class="product-name-offer"><?= $prod ?></div>
                        <div class="price-before">Antes: $<?= fmt_precio($o['precio_antes'] ?? 0) ?></div>
                        <div class="price-now"><span class="price-symbol">$</span><?= fmt_precio($o['precio_ahora'] ?? 0) ?></div>
                    <?php elseif ($tipo === 'percentage'): ?>
                        <div class="product-name-offer"><?= $prod ?></div>
                        <div class="pct-badge"><?= $h($o['porcentaje'] ?? '') ?></div>
                        <div class="pct-label">de descuento</div>
                        <div class="pct-prices">
                            <span class="pct-price-before">$<?= fmt_precio($o['precio_antes'] ?? 0) ?></span>
                            <span class="pct-price-now"><span class="price-symbol">$</span><?= fmt_precio($o['precio_ahora'] ?? 0) ?></span>
                        </div>
                    <?php elseif ($tipo === 'quantity'): ?>
                        <div class="product-name-offer"><?= $prod ?></div>
                        <div class="qty-display">
                            <span class="qty-number"><?= (int)($o['cantidad'] ?? 0) ?></span>
                            <span class="qty-x">x</span>
                            <span class="qty-price"><span class="price-symbol">$</span><?= fmt_precio($o['precio'] ?? 0) ?></span>
                        </div>
                    <?php elseif ($tipo === 'daily'): ?>
                        <div class="daily-badge">Producto del Día</div>
                        <div class="product-name-offer"><?= $prod ?></div>
                        <div class="daily-price"><span class="price-symbol">$</span><?= fmt_precio($o['precio'] ?? 0) ?></div>
                    <?php endif; ?>
                </div>
                <div class="offer-footer"><?= $h($promo) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
