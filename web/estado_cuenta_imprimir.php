<?php
declare(strict_types=1);

/**
 * Estado de cuenta de un cliente de fiados, en formato imprimible / PDF.
 * Puente hacia App\Controllers\FiadosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\FiadosController())->estadoCuenta([]);
