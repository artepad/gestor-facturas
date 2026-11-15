<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\CajaSesion;
use App\Models\Corte;

/**
 * Ingresos: cortes de caja (hoy los genera el POS propio al cerrar turno;
 * hasta 2026-11-14 también llegaban por correo desde Eleventa, ya retirado
 * de la web — ver web/CLAUDE.md, módulo Ingresos).
 *   ingresos.php          -> panel()   (dashboard)
 *   corte.php?id=N        -> detalle() (resumen completo de un corte)
 */
final class IngresosController extends BaseController
{
    public function panel(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ingresos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono(), fecha_dmy()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

        $fNegocio = Request::entero('negocio');
        if ($fNegocio && !in_array($fNegocio, $idsVisibles, true)) $fNegocio = 0;
        $hoy = date('Y-m-d');
        $fDesde = Request::get('desde');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)) $fDesde = date('Y-m-01');
        $fHasta = Request::get('hasta');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)) $fHasta = $hoy;
        $fCajero = Request::entero('cajero');

        $ids = $fNegocio ? [$fNegocio] : $idsVisibles;
        $cortes = []; $cajerosFiltro = [];

        if ($ids) {
            $cortes = Corte::listado($ids, $fDesde, $fHasta, $fCajero);
            $cajerosFiltro = Corte::cajerosDe($idsVisibles);
        }

        $mismoNegocio = count($negocios) === 1;

        if ($fNegocio) {
            foreach ($negocios as $n) { if ((int)$n['id'] === $fNegocio) $viendo = $n['nombre']; }
        } elseif ($mismoNegocio) {
            $viendo = $negocios[0]['nombre'];
        }
        $viendo = $viendo ?? 'Todos los negocios';

        View::render('ingresos/panel', compact(
            'usuario', 'negocios', 'mismoNegocio', 'fNegocio', 'fDesde', 'fHasta', 'fCajero',
            'cortes', 'cajerosFiltro', 'viendo'
        ));
    }

    public function detalle(array $parametros = []): void
    {
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // exigir_login(), puede(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono(), fecha_dmy()

        $usuario = \exigir_login();
        $esAdmin = ($usuario['rol'] ?? '') === 'admin';

        $id = Request::entero('id');
        $corte = Corte::porId($id);

        // El admin ve cualquier corte de sus negocios (como siempre, con el
        // permiso 'ingresos'). Una vendedora NO tiene ese permiso, pero puede
        // ver el corte de un turno que ELLA MISMA abrió — es su propia
        // información, igual que ya ve su propio historial en caja.php. Antes
        // de esto, "Ver corte" exigía 'ingresos' sin excepción y una
        // vendedora terminaba siempre en home.php, aunque fuera su turno.
        $puedeVer = $corte && \puede_ver_negocio($usuario, (int)$corte['negocio_id']) && (
            ($esAdmin && \puede($usuario, 'ingresos'))
            || (!$esAdmin && \puede($usuario, 'ventas') && CajaSesion::corteEsPropio($id, (int)$usuario['id']))
        );
        if (!$puedeVer) {
            \redirigir($esAdmin ? 'ingresos.php' : 'caja.php');
        }

        $movimientos = Corte::movimientosDe($id);
        $departamentos = Corte::departamentosDe($id);
        $correo = $corte['correo_id'] ? Corte::correoDe((int)$corte['correo_id']) : null;

        View::render('ingresos/detalle', compact('usuario', 'esAdmin', 'corte', 'movimientos', 'departamentos', 'correo'));
    }
}
