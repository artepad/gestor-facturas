<?php
declare(strict_types=1);

/**
 * Motor de cobro del POS (JSON): catálogo, búsqueda, cobrar, anular, espera,
 * movimientos de caja, recaudación de convenios.
 *
 * Fase 9 de la migración de arquitectura (ver web/docs/01-plan-migracion.md):
 * este archivo es un puente — la misma dirección de siempre, pero ahora
 * llama directo a App\Controllers\PosApiController, que ya decide internamente
 * qué acción correr según ?accion= (igual que antes lo decidía este archivo).
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\PosApiController())->index([]);
