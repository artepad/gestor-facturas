<?php
declare(strict_types=1);

/**
 * Crear o editar un cliente de fiados.
 *   cliente_form.php      -> crear
 *   cliente_form.php?id=N -> editar
 * Puente hacia App\Controllers\FiadosController (ver web/docs/01-plan-migracion.md).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\FiadosController())->formulario([]);
