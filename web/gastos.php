<?php
declare(strict_types=1);

/**
 * Módulo Gastos: dashboard del período + generar los gastos fijos del mes.
 * Puente hacia App\Controllers\GastosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\GastosController())->panel([]);
