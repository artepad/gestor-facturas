<?php
/**
 * Endpoint JSON de la herramienta "Consultar Catálogo". Recibe ?negocio_id=,
 * ?q= (texto o código de barras) y ?departamento= y devuelve los productos
 * coincidentes con todos sus campos. Distinto de productos_buscar.php (que es
 * liviano, solo para el autocompletar del Gestor de Etiquetas).
 */

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/productos.php';

$usuario = exigir_permiso('herramientas');

$negocioId    = (int)($_GET['negocio_id'] ?? 0);
$q            = (string)($_GET['q'] ?? '');
$departamento = (string)($_GET['departamento'] ?? '');

if (!$negocioId || !puede_ver_negocio($usuario, $negocioId)) {
    responder_json(['error' => 'Negocio no válido'], 403);
}

$resultados = consultar_catalogo(obtener_pdo(), $negocioId, $q, $departamento, 30);
responder_json(['productos' => $resultados]);
