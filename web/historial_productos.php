<?php
declare(strict_types=1);

/**
 * Historial de auditoría del catálogo (solo admin). Puente hacia
 * App\Controllers\ProductosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\ProductosController())->historial([]);
