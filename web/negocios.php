<?php
declare(strict_types=1);

/**
 * Lista de negocios (tarjetas). Puente hacia
 * App\Controllers\NegociosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\NegociosController())->index([]);
