<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;

/**
 * Etiquetas de Ofertas: formulario con cola de hasta 4 ofertas (4 tipos) +
 * hoja imprimible A4. La cola se maneja en el navegador (JS) y se envía
 * como JSON al generar.
 *   herramienta_ofertas.php -> formulario()
 *   ofertas_imprimir.php    -> imprimir()
 *
 * imprimir() no persiste nada en la BD, así que no lleva CSRF (mismo
 * criterio que EtiquetasController::imprimir()).
 */
final class OfertasController extends BaseController
{
    private const TIPOS_VALIDOS = ['normal', 'percentage', 'quantity', 'daily'];

    public function formulario(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('herramientas');

        View::render('ofertas/formulario', compact('usuario'));
    }

    public function imprimir(array $parametros = []): void
    {
        $this->exigirPermiso('herramientas');

        $cola = json_decode($_POST['ofertas'] ?? '[]', true);
        if (!is_array($cola)) $cola = [];

        $ofertas = [];
        foreach ($cola as $o) {
            $tipo = $o['tipo'] ?? '';
            $prod = trim((string)($o['producto'] ?? ''));
            if ($prod === '' || !in_array($tipo, self::TIPOS_VALIDOS, true)) continue;
            $ofertas[] = $o;
            if (count($ofertas) >= 4) break;
        }
        // Rellena hasta 4 con espacios vacíos (igual que la app de escritorio).
        while (count($ofertas) < 4) $ofertas[] = ['tipo' => 'empty'];

        $hayOfertas = count(array_filter($ofertas, fn($o) => ($o['tipo'] ?? '') !== 'empty')) > 0;

        View::render('ofertas/imprimir', compact('ofertas', 'hayOfertas'));
    }
}
