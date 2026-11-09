<?php
declare(strict_types=1);

/**
 * Listado de facturas + filtros. Puente hacia
 * App\Controllers\FacturasController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\FacturasController())->panel([]);
