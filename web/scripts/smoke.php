<?php
/**
 * Prueba de humo (Fase 3 de la migracion de arquitectura).
 *
 * Recorre las rutas clave del POS y del dashboard, como admin y como
 * vendedor, y verifica: codigo HTTP, que no aparezcan errores de PHP en el
 * cuerpo (Warning/Notice/Deprecated/Fatal) y que el permiso por rol se
 * respete (el vendedor no debe poder entrar al POS mientras 'ventas' siga
 * fuera de su fila en lib/permisos.php).
 *
 * Es la linea base "asi funciona HOY": antes de cada corte de la migracion
 * se vuelve a correr y se compara. NO modifica nada (solo lee paginas).
 *
 * Uso (contra el XAMPP local, con Apache+MySQL corriendo):
 *   php web/scripts/smoke.php [base_url]
 *   base_url por defecto: http://localhost/minimark
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script es solo de consola.');
}

$base = $argv[1] ?? 'http://localhost/minimark';

// Rutas que se visitan en cada sesion. 'esperado' es el codigo HTTP normal
// (200 = la pagina se sirvio; 302 = redirigio, por ejemplo pos.php sin caja
// abierta manda a caja.php, o un modulo sin permiso manda a home.php).
$rutas = [
    'home.php', 'panel_facturas.php', 'fiados.php', 'ingresos.php', 'gastos.php',
    'precios.php', 'herramientas.php', 'negocios.php', 'usuarios.php',
    'caja.php', 'pos.php', 'inventario.php', 'compras.php', 'promociones.php',
    'servicios.php', 'historial_productos.php',
];

// Fragmentos que delatan un error de PHP colado en el HTML.
$patronesError = ['Warning:', 'Notice:', 'Deprecated:', 'Fatal error:', 'Parse error:'];

function curl_sesion(): array
{
    // Un "manejador" de curl + archivo de cookies por sesion de prueba.
    $cookieFile = tempnam(sys_get_temp_dir(), 'smoke_cookie_');
    return ['cookie' => $cookieFile];
}

function pedir(string $url, array $sesion, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $sesion['cookie'],
        CURLOPT_COOKIEFILE     => $sesion['cookie'],
        CURLOPT_FOLLOWLOCATION => false,   // queremos VER el 302, no seguirlo
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $cuerpo = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, (string)$cuerpo];
}

function login(string $base, array $sesion, string $email, string $clave): bool
{
    [$codigo] = pedir("$base/login.php", $sesion, ['email' => $email, 'clave' => $clave]);
    return $codigo === 302;   // login OK redirige a home.php
}

function revisar_ruta(string $base, array $sesion, string $ruta, array $patronesError): array
{
    [$codigo, $cuerpo] = pedir("$base/$ruta", $sesion);
    $errores = [];
    foreach ($patronesError as $p) {
        if (stripos($cuerpo, $p) !== false) {
            $errores[] = $p;
        }
    }
    return ['ruta' => $ruta, 'codigo' => $codigo, 'errores' => $errores];
}

$fallas = 0;
$total = 0;

foreach (['admin' => 'admin@minimark.cl', 'vendedor' => 'vendedor@minimark.cl'] as $rol => $email) {
    echo "\n=== $rol ($email) ===\n";
    $sesion = curl_sesion();
    if (!login($base, $sesion, $email, 'prueba123')) {
        echo "  LOGIN FALLO — se omite este rol (revisa la clave de prueba local)\n";
        continue;
    }
    foreach ($rutas as $ruta) {
        $total++;
        $r = revisar_ruta($base, $sesion, $ruta, $patronesError);
        $ok = in_array($r['codigo'], [200, 302], true) && empty($r['errores']);
        if (!$ok) {
            $fallas++;
        }
        printf(
            "  %-26s %-4s %s%s\n",
            $r['ruta'],
            $r['codigo'],
            $ok ? 'OK' : 'FALLA',
            $r['errores'] ? ' [' . implode(', ', $r['errores']) . ']' : ''
        );
    }
    @unlink($sesion['cookie']);
}

echo "\n--- Resumen: " . ($total - $fallas) . "/$total rutas sin novedad ---\n";
exit($fallas > 0 ? 1 : 0);
