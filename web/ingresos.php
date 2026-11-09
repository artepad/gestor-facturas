<?php
declare(strict_types=1);

/**
 * Módulo Ingresos: dashboard de los cortes de caja que envía Eleventa.
 * Puente hacia App\Controllers\IngresosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\IngresosController())->panel([]);
