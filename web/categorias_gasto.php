<?php
declare(strict_types=1);

/**
 * Categorías de gasto: CRUD simple (crear, renombrar, activar/desactivar,
 * eliminar). Puente hacia App\Controllers\GastosController
 * (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\GastosController())->categorias([]);
