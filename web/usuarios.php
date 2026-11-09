<?php
declare(strict_types=1);

/**
 * Gestión de usuarios del dashboard. Puente hacia
 * App\Controllers\UsuariosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\UsuariosController())->index([]);
