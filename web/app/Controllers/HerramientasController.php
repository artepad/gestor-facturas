<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;

/**
 * Tablero del módulo Herramientas + Contador de Caja. Ambas pantallas no
 * tocan la base de datos (el tablero son solo enlaces; el contador calcula
 * todo en el navegador), así que no hay Modelo detrás.
 *   herramientas.php      -> tablero()
 *   herramienta_caja.php  -> caja()
 */
final class HerramientasController extends BaseController
{
    public function tablero(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('herramientas');
        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()

        View::render('herramientas/tablero', compact('usuario'));
    }

    public function caja(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('herramientas');

        View::render('herramientas/caja', compact('usuario'));
    }
}
