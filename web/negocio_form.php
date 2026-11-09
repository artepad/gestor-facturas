<?php
declare(strict_types=1);

/**
 * Crear o editar el perfil de un negocio. Puente hacia
 * App\Controllers\NegociosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\NegociosController())->formulario([]);
