<?php
declare(strict_types=1);

/**
 * Etiquetas de Ofertas. Puente hacia App\Controllers\OfertasController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\OfertasController())->formulario([]);
