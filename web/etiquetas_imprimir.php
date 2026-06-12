<?php
/**
 * Hoja imprimible de etiquetas de precio. Recibe por POST los productos del
 * formulario (herramienta_etiquetas.php) y arma una página A4 lista para
 * imprimir o guardar como PDF desde el navegador. Es una página "desnuda"
 * (sin el layout del dashboard) para que la impresión salga limpia.
 *
 * El diseño de la etiqueta replica el de la app de escritorio: precio grande
 * arriba + nombre del producto, con tamaños de fuente automáticos según el largo.
 */

require __DIR__ . '/lib/auth.php';

$usuario = exigir_permiso('herramientas');

// --- Productos válidos (nombre + precio > 0) ---
$nombres = $_POST['nombre'] ?? [];
$precios = $_POST['precio'] ?? [];
$productos = [];
foreach ($nombres as $i => $nom) {
    $nom = trim((string)$nom);
    $precio = (float) preg_replace('/[^\d]/', '', (string)($precios[$i] ?? ''));  // CLP: solo dígitos
    if ($nom !== '' && $precio > 0) {
        $productos[] = ['nombre' => $nom, 'precio' => $precio];
    }
}

/** Precio chileno: 633020 -> "633.020". */
function fmt_precio($p) { return number_format(round($p), 0, ',', '.'); }

/** Tamaño de fuente del precio según el largo del texto (como en la app). */
function fuente_precio(string $txt): string {
    $l = strlen($txt);
    if ($l <= 4)  return '1.2cm';
    if ($l <= 6)  return '1.1cm';
    if ($l <= 8)  return '1.0cm';
    if ($l <= 10) return '0.90cm';
    if ($l <= 12) return '0.80cm';
    if ($l <= 14) return '0.8cm';
    return '0.7cm';
}

/** Tamaño de fuente del nombre según su largo. */
function fuente_nombre(string $n): string {
    $l = mb_strlen($n);
    if ($l <= 20) return '0.48cm';
    if ($l <= 35) return '0.43cm';
    if ($l <= 50) return '0.38cm';
    if ($l <= 70) return '0.35cm';
    if ($l <= 90) return '0.32cm';
    return '0.30cm';
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiquetas de precios</title>
    <style>
        @page { margin: 0.5cm; size: A4 portrait; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif; background: #eef1f4;
            color: black; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* Barra superior (solo en pantalla, no se imprime) */
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

        .page-title { text-align: center; font-size: 22px; font-weight: bold;
            margin: 0.3cm 0 0.6cm; color: #2c3e50; letter-spacing: 2px; text-transform: uppercase; }
        .price-grid { display: grid; grid-template-columns: 7cm 7cm; grid-auto-rows: 3.4cm;
            gap: 0.3cm; width: 14.6cm; margin: 0 auto; justify-content: center; }
        .price-label { display: flex; flex-direction: column; align-items: center;
            justify-content: center; background: #fff; border: 2px solid #2c3e50;
            border-radius: 0.2cm; padding: 0.4cm; text-align: center; height: 3.4cm;
            width: 100%; overflow: hidden; page-break-inside: avoid; }
        .price-text { font-weight: 900; color: #2c3e50; margin-bottom: 0.3cm; line-height: 1.1; }
        .product-name { font-weight: bold; color: #34495e; line-height: 1.3; max-width: 100%;
            word-wrap: break-word; overflow: hidden; display: -webkit-box;
            -webkit-line-clamp: 2; -webkit-box-orient: vertical; }

        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { box-shadow: none; margin: 0; max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="barra">
        <h1>Etiquetas de precios</h1>
        <?php if ($productos): ?>
            <span class="aviso"><?= count($productos) ?> etiqueta(s)</span>
            <button class="b-print" onclick="window.print()">Imprimir / Guardar como PDF</button>
        <?php else: ?>
            <span class="aviso">No ingresaste productos válidos (nombre + precio).</span>
        <?php endif; ?>
        <a class="b-volver" href="herramienta_etiquetas.php">Volver</a>
    </div>

    <?php if ($productos): ?>
    <div class="hoja">
        <div class="page-title">Etiquetas de precios</div>
        <div class="price-grid">
            <?php foreach ($productos as $p):
                $ptxt = '$' . fmt_precio($p['precio']); ?>
            <div class="price-label">
                <div class="price-text" style="font-size: <?= fuente_precio($ptxt) ?>;"><?= $h($ptxt) ?></div>
                <div class="product-name" style="font-size: <?= fuente_nombre($p['nombre']) ?>;"><?= $h($p['nombre']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
