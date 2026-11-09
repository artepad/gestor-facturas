<?php
declare(strict_types=1);

/**
 * Crear/editar/eliminar un producto del catálogo. Puente hacia
 * App\Controllers\ProductosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\ProductosController())->formulario([]);
