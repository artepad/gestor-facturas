<?php
declare(strict_types=1);

/**
 * Hoja imprimible de etiquetas de oferta. Puente hacia
 * App\Controllers\OfertasController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\OfertasController())->imprimir([]);
