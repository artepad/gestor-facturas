<?php
declare(strict_types=1);

/**
 * Hoja imprimible de Frutas y Verduras — ver App\Controllers\PreciosFvController::imprimir().
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\PreciosFvController())->imprimir([]);
