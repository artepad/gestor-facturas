<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Facturas (espejo de las sincronizadas desde la app de escritorio). El
 * módulo Facturas (panel_facturas.php, factura.php) todavía no está
 * migrado — este Modelo solo trae lo mínimo que necesita
 * App\Controllers\VerPdfController para servir el PDF. Se amplía el día que
 * se migre el módulo completo.
 */
final class Factura
{
    public static function paraPdf(int $id): ?array
    {
        return Database::uno(
            'SELECT negocio_id, ruta_pdf, numero_factura FROM facturas WHERE id = ?',
            [$id]
        );
    }
}
