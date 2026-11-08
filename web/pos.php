<?php
declare(strict_types=1);

/**
 * Pantalla de venta.
 *
 * Fase 9 de la migración de arquitectura (ver web/docs/01-plan-migracion.md):
 * este archivo es un puente — la misma dirección de siempre, pero ahora
 * llama directo a App\Controllers\PosController en vez de tener la lógica
 * acá adentro. El motor de cobro vive en pos_api.php (también puenteado).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\PosController())->index([]);
