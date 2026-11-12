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
        // Todas las sesiones abiertas en los negocios visibles: el admin las ve
        // en la tabla "Otras cajas abiertas"; TODOS los roles la necesitan
        // (aunque no se muestre como tabla) para avisar, al elegir el nombre de
        // la caja a abrir, si ese nombre ya está en uso en ese negocio.
        $sesionesAbiertas = CajaSesion::abiertasEn($idsVisibles);
        $otrasAbiertas    = $esAdmin ? $sesionesAbiertas : [];
        // El admin necesita el efectivo esperado de cada caja ajena para
        // poder cerrarla a distancia (botón "Cerrar" de la tabla de abajo):
        // son pocas sesiones abiertas a la vez, así que calcularlo una por
        // una acá es barato y evita duplicar la lógica de resumen_caja().
        foreach ($otrasAbiertas as &$s) {
            if ($sesion && (int)$s['id'] === (int)$sesion['id']) continue;
            $s['esperado'] = CajaSesion::resumen((int)$s['id'])['efectivo_esperado'] ?? 0;
        }
        unset($s);
        // Una vendedora solo ve su propio historial de cierres (no el de sus
        // compañeras); el admin sigue viendo el de todos — mismo criterio
        // que "Cajas abiertas de otros" arriba.
        $historial     = CajaSesion::cerradasEn($idsVisibles, 15, $esAdmin ? null : (int)$usuario['id']);
        $avisoLarga    = $sesion ? CajaSesion::avisoSiLlevaMuchoAbierta($sesion) : null;
        // Caja Express: celular/tablet sin efectivo (ver CLAUDE.md, módulo
        // Ventas/POS). La vista oculta entradas/salidas y simplifica el cierre.
        $esExpress     = $sesion && ($sesion['tipo'] ?? 'fisica') === 'express';

        View::render('caja/index', compact(
            'usuario', 'negocios', 'esAdmin', 'sesion', 'error', 'aviso', 'corteNuevo',
            'resumen', 'movimientos', 'recaudado', 'enEspera', 'otrasAbiertas', 'historial',
            'avisoLarga', 'sesionesAbiertas', 'esExpress'
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
            $tipo = Request::post('tipo') === 'express' ? 'express' : 'fisica';
            $fondo = $tipo === 'express' ? 0.0 : (\parsear_monto(Request::post('fondo_inicial')) ?? 0.0);
            if ($fondo < 0) {
                throw new \RuntimeException('El fondo inicial no puede ser negativo.');
            }
            $caja = Request::post('caja');
            CajaSesion::abrir($usuario, $negocioId, $caja, $fondo, $tipo);
            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'abrir_caja', null,
                'Abrió la caja ' . ($caja !== '' ? $caja : '(sin nombre)')
                . ($tipo === 'express' ? ' (Express, sin efectivo)' : ' con fondo ' . \clp($fondo)));
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
            if (($sesion['tipo'] ?? 'fisica') === 'express') {
                throw new \RuntimeException('La Caja Express no maneja efectivo: no hay movimientos que registrar.');
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

    /**
     * Solo admin: cierra el turno de OTRA persona (quedó abierto por error,
     * sin internet, o la vendedora ya no está disponible). Mismo arqueo que
     * un cierre normal — el admin igual necesita el efectivo contado real
     * (lo pide por teléfono o va a mirar la caja), no se inventa un cuadre.
     * Queda registrado en auditoría y en la nota del turno quién lo cerró y
     * que no fue la persona que lo abrió.
     */
    public function cerrarAjena(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ventas');
        if (($usuario['rol'] ?? '') !== 'admin') {
            \redirigir('home.php');
        }
        $this->verificarCsrf('caja.php');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';
        require_once dirname(__DIR__, 2) . '/lib/ui.php';
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php';

        try {
            $sesionId = Request::entero('sesion_id');
            $sesion = CajaSesion::porId($sesionId);
            if (!$sesion || $sesion['estado'] !== 'abierta') {
                throw new \RuntimeException('Esa caja ya no está abierta (puede que alguien ya la haya cerrado).');
            }
            $idsVisibles = array_map(fn($n) => (int)$n['id'], \negocios_visibles($usuario));
            if (!in_array((int)$sesion['negocio_id'], $idsVisibles, true)) {
                throw new \RuntimeException('No tienes acceso a ese negocio.');
            }
            $contado = \parsear_monto(Request::post('efectivo_contado'));
            if ($contado === null) {
                throw new \RuntimeException('Escribe cuánto efectivo contaste (aunque sea 0).');
            }
            if ($contado < 0) {
                throw new \RuntimeException('El efectivo contado no puede ser negativo.');
            }
            $cajero = $sesion['cajero'] ?: 'quien la abrió';
            $nota = Request::post('nota');
            $notaFinal = "Cerrada por {$usuario['nombre']} (admin), no por $cajero."
                       . ($nota !== '' ? ' ' . $nota : '');

            $r = CajaSesion::cerrar($usuario, $sesionId, $contado, $notaFinal);
            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'cerrar_caja_ajena', null,
                "Cerró la caja de $cajero ({$sesion['negocio_nombre']}"
                . ($sesion['caja'] ? ", {$sesion['caja']}" : '') . '). Esperado '
                . \clp($r['efectivo_esperado']) . ', contado ' . \clp($contado)
                . ', diferencia ' . \clp($r['diferencia']));
            \redirigir('caja.php?cerrada=' . (int)($r['corte_id'] ?? 0));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            \redirigir('caja.php');
        }
    }
}
