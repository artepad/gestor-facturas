<?php
declare(strict_types=1);

/**
 * Cerrar sesión. Puente hacia App\Controllers\AuthController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\AuthController())->logout([]);
