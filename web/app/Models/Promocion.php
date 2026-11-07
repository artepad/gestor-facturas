<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Promociones automáticas del POS ("4 x $1.000"). Corte 2a de la Fase 6:
 * solo lo que necesita la pantalla para mostrarlas al instante mientras se
 * arma la venta. El servidor igual las recalcula al cobrar (eso vive en
 * pos_api.php, corte 2b) — nunca se confía en lo que calculó el navegador.
 */
final class Promocion
{
    private static function cargar(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pos.php';
    }

    public static function vigentes(int $negocioId): array
    {
        self::cargar();
        return \promociones_vigentes(Database::pdo(), $negocioId);
    }
}
