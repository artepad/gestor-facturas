<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Models\CajaSesion;
use App\Models\Promocion;
use App\Models\Venta;

/**
 * Pantalla de venta.
 *
 * Fase 9 (despliegue): vive en web/pos.php (archivo puente que llama directo
 * a este Controlador) — misma direccion de siempre. pos_api.php es el motor
 * de cobro (ver PosApiController), tambien puenteado a su Controlador.
 */
final class PosController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ventas');

        $sesion = CajaSesion::abiertaDe((int)$usuario['id']);
        if (!$sesion) {
            // Sin turno abierto no se vende (decisión de diseño, igual que pos.php).
            \redirigir('caja.php');
        }

        require_once dirname(__DIR__, 2) . '/lib/auth.php';   // puede_ver_negocio()
        if (!\puede_ver_negocio($usuario, (int)$sesion['negocio_id'])) {
            \redirigir('home.php');
        }

        // Cerrojo local de la caja: misma huella que el PC valida sin internet.
        $pinHash = (string)(Database::valor('SELECT pin_hash FROM usuarios WHERE id = ?', [$usuario['id']]) ?: '');

        $espera   = Venta::listarEspera((int)$sesion['id']);
        $ultima   = Venta::ultimaDelTurno((int)$sesion['id']);
        $ventas   = Venta::delTurno((int)$sesion['id'], 50);
        $resumen  = CajaSesion::resumen((int)$sesion['id']);
        $avisoLarga = CajaSesion::avisoSiLlevaMuchoAbierta($sesion);
        $promos   = Promocion::vigentes((int)$sesion['negocio_id']);
        $csrfToken = Session::tokenCsrf();

        View::render('pos/index', compact(
            'usuario', 'sesion', 'pinHash', 'espera', 'ultima', 'ventas', 'resumen',
            'avisoLarga', 'promos', 'csrfToken'
        ));
    }
}
