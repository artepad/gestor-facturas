<?php
declare(strict_types=1);

/**
 * Frutas y Verduras: listar/crear/editar/eliminar productos (JSON). Se llama
 * desde el popup de la pantalla de Venta (pos.php) — ver
 * App\Controllers\PreciosFvController.
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\PreciosFvController())->index([]);
