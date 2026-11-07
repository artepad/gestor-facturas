<?php
declare(strict_types=1);

/**
 * Funciones sueltas del codigo nuevo. Equivalentes a las que ya existen en
 * lib/ui.php (h(), etc.) para el codigo viejo — no se tocan esas, cada lado
 * usa las suyas hasta que una pantalla se migre del todo (Fase 6).
 */

if (!function_exists('e')) {
    /** Escapa para HTML. Igual que h() en lib/ui.php, con otro nombre. */
    function e(?string $texto): string
    {
        return htmlspecialchars((string)$texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('campo_csrf')) {
    /** <input hidden> listo para pegar dentro de un <form method="post">. */
    function campo_csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . e(\App\Core\Session::tokenCsrf()) . '">';
    }
}

if (!function_exists('redirigir')) {
    function redirigir(string $ruta, int $codigo = 302): never
    {
        header('Location: ' . $ruta, true, $codigo);
        exit;
    }
}
