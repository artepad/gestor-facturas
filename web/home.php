<?php
declare(strict_types=1);

/**
 * Home: tablero de mando. Puente hacia App\Controllers\HomeController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\HomeController())->index([]);
