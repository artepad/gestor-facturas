<?php
declare(strict_types=1);

/**
 * Endpoint JSON del autocompletar del Gestor de Etiquetas. Puente hacia
 * App\Controllers\EtiquetasController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\EtiquetasController())->buscarJson([]);
