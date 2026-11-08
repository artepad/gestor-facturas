<?php
declare(strict_types=1);

/**
 * Promociones automáticas: las ofertas que el POS aplica solo al vender.
 *
 * Fase 9 de la migración de arquitectura (ver web/docs/01-plan-migracion.md):
 * este archivo es un puente — la misma dirección de siempre, pero ahora
 * llama directo a App\Controllers\PromocionesController en vez de tener la
 * lógica acá adentro.
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\PromocionesController())->index([]);
