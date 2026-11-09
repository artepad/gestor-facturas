<?php
declare(strict_types=1);

/**
 * Detalle de una factura: cabecera + líneas de producto + Ver PDF. Puente
 * hacia App\Controllers\FacturasController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\FacturasController())->detalle([]);
