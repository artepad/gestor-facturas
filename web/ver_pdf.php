<?php
declare(strict_types=1);

/**
 * Sirve el PDF de una factura. Puente hacia App\Controllers\VerPdfController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\VerPdfController())->index([]);
