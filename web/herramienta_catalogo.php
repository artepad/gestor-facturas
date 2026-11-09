<?php
declare(strict_types=1);

/**
 * Consultar Catálogo. Puente hacia App\Controllers\CatalogoController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\CatalogoController())->index([]);
