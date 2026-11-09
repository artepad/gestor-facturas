<?php
declare(strict_types=1);

/**
 * Crear, editar o eliminar un gasto.
 *   gasto_form.php      -> crear
 *   gasto_form.php?id=N -> editar / eliminar
 * Puente hacia App\Controllers\GastosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\GastosController())->formulario([]);
