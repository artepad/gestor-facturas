<?php
declare(strict_types=1);

/**
 * Contador de caja. Puente hacia App\Controllers\HerramientasController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\HerramientasController())->caja([]);
