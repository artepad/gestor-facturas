<?php
declare(strict_types=1);

/**
 * Ficha de un cliente de fiados: saldo, historial de fiados/abonos y sus
 * formularios de registro. Puente hacia App\Controllers\FiadosController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\FiadosController())->ficha([]);
