<?php
/**
 * Helpers de interfaz compartidos por las páginas del dashboard.
 */

/** Cabecera con franja, título y menú de navegación. */
function cabecera_dashboard(array $usuario, string $activo = 'facturas'): void
{
    $esAdmin = ($usuario['rol'] ?? '') === 'admin';
    $nombre = htmlspecialchars($usuario['nombre'] ?? '');
    $rol = htmlspecialchars($usuario['rol'] ?? '');
    $itm = fn(string $id, string $txt, string $href) =>
        '<a class="nav-item' . ($activo === $id ? ' activo' : '') . '" href="'
        . $href . '">' . $txt . '</a>';
    echo '<div class="franja"></div>';
    echo '<div class="header">';
    echo '  <div class="header-izq">';
    echo '    <h1>Sistema de Gestión</h1>';
    echo '    <nav class="nav">';
    echo        $itm('facturas', 'Facturas', 'panel.php');
    if ($esAdmin) {
        echo    $itm('negocios', 'Negocios', 'negocios.php');
    }
    echo '    </nav>';
    echo '  </div>';
    echo '  <div class="usuario">' . $nombre . ' (' . $rol . ')'
       . ' <a href="logout.php">Salir</a></div>';
    echo '</div>';
}

function pie_dashboard(): void
{
    echo '<div class="pie">Sistema de Gestión de Facturas</div>';
}

/** Formatea un número como pesos chilenos: 119990 -> $119.990 */
function clp($v): string
{
    if ($v === null || $v === '') return '';
    return '$' . number_format((float)$v, 0, ',', '.');
}

/** ISO (2026-05-20) -> dd-mm-yyyy (20-05-2026) */
function fecha_dmy($iso): string
{
    if (!$iso) return '';
    $p = explode('-', $iso);
    return count($p) === 3 ? "$p[2]-$p[1]-$p[0]" : $iso;
}

/** Estado visual de una factura según su confianza y notas. */
function estado_factura(array $f): array
{
    $conf = $f['confianza'];
    $notas = strtolower($f['notas'] ?? '');
    $adv = preg_match('/revisar|sospechos|advertencia|ilegible/', $notas);
    if ($conf !== null && $conf < 0.4) return ['rojo', 'Revisar (baja lectura)'];
    if ($conf === null || $conf < 0.7 || $adv) return ['amarillo', 'Revisar'];
    return ['verde', 'Correcto'];
}
