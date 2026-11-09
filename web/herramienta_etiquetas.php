<?php
declare(strict_types=1);

/**
 * Gestor de Etiquetas de precio. Puente hacia
 * App\Controllers\EtiquetasController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\EtiquetasController())->formulario([]);
