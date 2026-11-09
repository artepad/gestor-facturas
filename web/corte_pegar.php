<?php
declare(strict_types=1);

/**
 * Registrar un corte pegando el correo a mano (respaldo del cron). Puente
 * hacia App\Controllers\IngresosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\IngresosController())->pegar([]);
