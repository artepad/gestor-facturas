<?php
/**
 * Endpoint JSON de búsqueda de productos. Lo consume el autocompletar del Gestor
 * de Etiquetas: recibe ?negocio_id= y ?q= (código de barras o texto) y devuelve
 * las coincidencias del catálogo de ese negocio.
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/productos.php';

$usuario = exigir_permiso('herramientas');

$negocioId = (int)($_GET['negocio_id'] ?? 0);
$q         = (string)($_GET['q'] ?? '');

if (!$negocioId || !puede_ver_negocio($usuario, $negocioId)) {
    responder_json(['error' => 'Negocio no válido'], 403);
}

$resultados = buscar_productos(obtener_pdo(), $negocioId, $q, 20);
responder_json(['productos' => $resultados]);
