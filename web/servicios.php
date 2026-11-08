<?php
declare(strict_types=1);

/**
 * Servicios / Recaudación: el saldo de cada convenio (Mercado Pago y otros),
 * las recargas, las liquidaciones y las comisiones ganadas.
 *
 * Fase 9 de la migración de arquitectura (ver web/docs/01-plan-migracion.md):
 * este archivo es un puente — la misma dirección de siempre, pero ahora
 * llama directo a App\Controllers\ServiciosController en vez de tener la
 * lógica acá adentro.
 */

require __DIR__ . '/app/bootstrap.php';

(new \App\Controllers\ServiciosController())->index([]);
