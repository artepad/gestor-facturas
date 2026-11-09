<?php
declare(strict_types=1);

/**
 * Gastos fijos: plantillas de gasto mensual recurrente (sueldos, arriendo...).
 * Puente hacia App\Controllers\GastosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\GastosController())->fijos([]);
