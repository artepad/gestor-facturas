<?php
declare(strict_types=1);

/**
 * Caja: abrir/cerrar turno, movimientos de efectivo, arqueo.
 *
 * Fase 9 de la migración de arquitectura (ver web/docs/01-plan-migracion.md):
 * este archivo es un puente — la misma dirección de siempre, pero ahora
 * llama directo a App\Controllers\CajaController en vez de tener la lógica
 * acá adentro. El accion por POST elige qué método del Controlador correr,
 * igual que antes elegía qué bloque de código correr.
 */

require __DIR__ . '/app/bootstrap.php';

use App\Controllers\CajaController;
use App\Core\Request;

$c = new CajaController();

if (Request::metodo() === 'POST') {
    match (Request::post('accion')) {
        'abrir'      => $c->abrir([]),
        'movimiento' => $c->movimiento([]),
        'cerrar'     => $c->cerrar([]),
        default      => $c->index([]),
    };
} else {
    $c->index([]);
}
