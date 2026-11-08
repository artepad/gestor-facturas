<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\CajaSesion;

/**
 * Caja: abrir/cerrar turno, movimientos de efectivo, arqueo.
 *
 * Fase 9 (despliegue): esta pantalla vive ahora en web/caja.php (un archivo
 * puente que llama directo a este Controlador) — misma direccion de siempre.
 */
final class CajaController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ventas');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';     // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';       // clp(), icono(), cabecera/pie
        require_once dirname(__DIR__, 2) . '/lib/pos.php';      // parsear_monto() vive en ui.php; pos.php por si acaso

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
        $esAdmin = ($usuario['rol'] ?? '') === 'admin';

        $sesion = CajaSesion::abiertaDe((int)$usuario['id']);

        $error = Session::flash('error') ?? '';
        $aviso = '';
        $corteNuevo = (int)Request::entero('cerrada');
        if ($corteNuevo > 0) {
            $aviso = 'Caja cerrada. El corte del turno ya quedó registrado en Ingresos.';
        }

        $resumen       = $sesion ? CajaSesion::resumen((int)$sesion['id']) : null;
        $movimientos   = $sesion ? CajaSesion::movimientos((int)$sesion['id']) : [];
        $recaudado     = $sesion ? CajaSesion::recaudacionesDelTurno((int)$sesion['id']) : [];
        $enEspera      = $sesion ? CajaSesion::ticketsEnEspera((int)$sesion['id']) : 0;
        $otrasAbiertas = $esAdmin ? CajaSesion::abiertasEn($idsVisibles) : [];
        $historial     = CajaSesion::cerradasEn($idsVisibles, 15);
        $avisoLarga    = $sesion ? CajaSesion::avisoSiLlevaMuchoAbierta($sesion) : null;

        View::render('caja/index', compact(
            'usuario', 'negocios', 'esAdmin', 'sesion', 'error', 'aviso', 'corteNuevo',
            'resumen', 'movimientos', 'recaudado', 'enEspera', 'otrasAbiertas', 'historial',
            'avisoLarga'
        ));
    }

    public function abrir(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ventas');
        $this->verificarCsrf('caja.php');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/ui.php';
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php';

        try {
            if (CajaSesion::abiertaDe((int)$usuario['id'])) {
                throw new \RuntimeException('Ya tienes una caja abierta.');
            }
            $negocioId = Request::entero('negocio_id');
            $idsVisibles = array_map(fn($n) => (int)$n['id'], \negocios_visibles($usuario));
            if (!in_array($negocioId, $idsVisibles, true)) {
                throw new \RuntimeException('Selecciona un negocio válido.');
            }
            $fondo = \parsear_monto(Request::post('fondo_inicial')) ?? 0.0;
            if ($fondo < 0) {
                throw new \RuntimeException('El fondo inicial no puede ser negativo.');
            }
            $caja = Request::post('caja');
            CajaSesion::abrir($usuario, $negocioId, $caja, $fondo);
            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'abrir_caja', null,
                'Abrió la caja ' . ($caja !== '' ? $caja : '(sin nombre)') . ' con fondo ' . \clp($fondo));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        \redirigir('caja.php');
    }

    public function movimiento(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ventas');
        $this->verificarCsrf('caja.php');
        require_once dirname(__DIR__, 2) . '/lib/ui.php';
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php';

        try {
            $sesion = CajaSesion::abiertaDe((int)$usuario['id']);
            if (!$sesion) {
                throw new \RuntimeException('No tienes una caja abierta.');
            }
            $tipo = Request::post('tipo') === 'entrada' ? 'entrada' : 'salida';
            $monto = \parsear_monto(Request::post('monto'));
            if ($monto === null || $monto <= 0) {
                throw new \RuntimeException('Indica un monto válido mayor que cero.');
            }
            $motivo = Request::post('motivo');
            if ($tipo === 'salida' && $motivo === '') {
                throw new \RuntimeException('Escribe el motivo de la salida (para qué se sacó la plata).');
            }
            CajaSesion::registrarMovimiento($usuario, (int)$sesion['id'], $tipo, $monto, $motivo);
            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'movimiento_caja', null,
                ucfirst($tipo) . ' de efectivo por ' . \clp($monto) . ($motivo !== '' ? " ($motivo)" : ''));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        \redirigir('caja.php');
    }

    public function cerrar(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ventas');
        $this->verificarCsrf('caja.php');
        require_once dirname(__DIR__, 2) . '/lib/ui.php';
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php';

        try {
            $sesion = CajaSesion::abiertaDe((int)$usuario['id']);
            if (!$sesion) {
                throw new \RuntimeException('No tienes una caja abierta.');
            }
            $contado = \parsear_monto(Request::post('efectivo_contado'));
            if ($contado === null) {
                throw new \RuntimeException('Escribe cuánto efectivo contaste (aunque sea 0).');
            }
            if ($contado < 0) {
                throw new \RuntimeException('El efectivo contado no puede ser negativo.');
            }
            $nota = Request::post('nota');
            $r = CajaSesion::cerrar($usuario, (int)$sesion['id'], $contado, $nota);
            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'cerrar_caja', null,
                'Cerró la caja. Esperado ' . \clp($r['efectivo_esperado']) . ', contado ' . \clp($contado)
                . ', diferencia ' . \clp($r['diferencia']));
            \redirigir('caja.php?cerrada=' . (int)($r['corte_id'] ?? 0));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            \redirigir('caja.php');
        }
    }
}
