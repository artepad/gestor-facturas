<?php
declare(strict_types=1);

/**
 * Detalle de un corte de caja. Puente hacia
 * App\Controllers\IngresosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\IngresosController())->detalle([]);
