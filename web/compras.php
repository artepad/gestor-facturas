<?php
declare(strict_types=1);

/**
 * Compras: registrar la mercadería que llega (suma stock si el negocio lo
 * lleva) y proveedores.
 *
 * Fase 9 de la migración de arquitectura (ver web/docs/01-plan-migracion.md):
 * este archivo es un puente — la misma dirección de siempre, pero ahora
 * llama directo a App\Controllers\ComprasController en vez de tener la
 * lógica acá adentro.
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\ComprasController())->index([]);
