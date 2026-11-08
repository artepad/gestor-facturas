<?php
declare(strict_types=1);

/**
 * Inventario: existencias por negocio, ajustes, mermas, kardex y el
 * encendido/apagado del control de stock (opcional por negocio).
 *
 * Fase 9 de la migración de arquitectura (ver web/docs/01-plan-migracion.md):
 * este archivo es un puente — la misma dirección de siempre, pero ahora
 * llama directo a App\Controllers\InventarioController en vez de tener la
 * lógica acá adentro.
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\InventarioController())->index([]);
