<?php
declare(strict_types=1);

/**
 * Fiados: listado de clientes de cada negocio con su saldo pendiente.
 *
 * Primer módulo fuera del POS migrado a la arquitectura en capas (ver
 * web/docs/01-plan-migracion.md): este archivo es un puente — la misma
 * dirección de siempre, pero ahora llama directo a
 * App\Controllers\FiadosController en vez de tener la lógica acá adentro.
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\FiadosController())->listado([]);
