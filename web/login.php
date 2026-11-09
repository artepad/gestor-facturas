<?php
declare(strict_types=1);

/**
 * Formulario de ingreso. Puente hacia App\Controllers\AuthController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\AuthController())->login([]);
