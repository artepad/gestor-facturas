<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Corte;

/**
 * Ingresos: cortes de caja que envía Eleventa por correo.
 *   ingresos.php          -> panel()   (dashboard + descartar un correo sin procesar)
 *   corte.php?id=N        -> detalle() (resumen completo de un corte)
 *   corte_pegar.php       -> pegar()   (registrar un corte pegando el correo a mano)
 *
 * procesar_cortes.php (el worker de IMAP/cron) queda fuera de esta
 * migración a propósito: no es una pantalla del panel (no hay sesión, se
 * autoriza con PHP_SAPI==='cli' o ?clave=setup_key, y no renderiza nada,
 * solo texto plano) — mismo criterio que api/facturas.php o migrar.php.
 * Sigue llamando lib/eleventa.php directo, igual que antes.
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

        if (Request::metodo() === 'POST' && Request::post('accion') === 'descartar') {
            $this->verificarCsrf('ingresos.php');
            $cid = Request::entero('correo_id');
            $negId = Corte::correoNegocioId($cid);
            if ($negId === null || in_array($negId, $idsVisibles, true)) {
                Corte::descartarCorreo($cid);
            }
            \redirigir('ingresos.php');
        }

        $fNegocio = Request::entero('negocio');
        if ($fNegocio && !in_array($fNegocio, $idsVisibles, true)) $fNegocio = 0;
        $hoy = date('Y-m-d');
        $fDesde = Request::get('desde');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)) $fDesde = date('Y-m-01');
        $fHasta = Request::get('hasta');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)) $fHasta = $hoy;
        $fCajero = Request::entero('cajero');

        $ids = $fNegocio ? [$fNegocio] : $idsVisibles;
        $resumen = ['ventas' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0, 'salidas' => 0, 'n' => 0, 'nventas' => 0];
        $resumenAnt = null;
        $cortes = []; $porCajero = []; $porDia = []; $cajerosFiltro = [];
        $pendientes = []; $correosError = 0;

        if ($ids) {
            $resumen = Corte::resumenPeriodo($ids, $fDesde, $fHasta, $fCajero);

            // Período anterior equivalente (mismos días hacia atrás) para la comparativa
            $dias = max(1, (int)((strtotime($fHasta) - strtotime($fDesde)) / 86400) + 1);
            $antHasta = date('Y-m-d', strtotime("$fDesde -1 day"));
            $antDesde = date('Y-m-d', strtotime("$antHasta -" . ($dias - 1) . " day"));
            $resumenAntCrudo = Corte::resumenPeriodo($ids, $antDesde, $antHasta, $fCajero);
            if ($resumenAntCrudo['n'] > 0) {
                $resumenAnt = ['ventas' => $resumenAntCrudo['ventas'], 'desde' => $antDesde, 'hasta' => $antHasta];
            }

            $porDia = Corte::ventasPorDia($ids, $fDesde, $fHasta, $fCajero);
            $porCajero = Corte::porCajero($ids, $fDesde, $fHasta, $fCajero);
            $cortes = Corte::listado($ids, $fDesde, $fHasta, $fCajero);
            $cajerosFiltro = Corte::cajerosDe($idsVisibles);
            $pendientes = Corte::correosPendientes($idsVisibles);
            $correosError = count($pendientes);
        }

        $variacion = null;
        if ($resumenAnt && $resumenAnt['ventas'] > 0) {
            $variacion = (($resumen['ventas'] - $resumenAnt['ventas']) / $resumenAnt['ventas']) * 100;
        }
        $mismoNegocio = count($negocios) === 1;

        View::render('ingresos/panel', compact(
            'usuario', 'negocios', 'mismoNegocio', 'fNegocio', 'fDesde', 'fHasta', 'fCajero',
            'resumen', 'variacion', 'cortes', 'porCajero', 'porDia', 'cajerosFiltro',
            'pendientes', 'correosError'
        ));
    }

    public function detalle(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ingresos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono(), fecha_dmy()

        $id = Request::entero('id');
        $corte = Corte::porId($id);
        if (!$corte || !\puede_ver_negocio($usuario, (int)$corte['negocio_id'])) {
            \redirigir('ingresos.php');
        }

        $movimientos = Corte::movimientosDe($id);
        $departamentos = Corte::departamentosDe($id);
        $correo = $corte['correo_id'] ? Corte::correoDe((int)$corte['correo_id']) : null;

        View::render('ingresos/detalle', compact('usuario', 'corte', 'movimientos', 'departamentos', 'correo'));
    }

    public function pegar(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ingresos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles(), puede_ver_negocio()

        $negocios = \negocios_visibles($usuario);
        $error = '';

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('corte_pegar.php');

            $negocioId = Request::entero('negocio_id');
            $asunto = Request::post('asunto');
            $cuerpo = trim($_POST['cuerpo'] ?? '');

            if (!$negocioId || !\puede_ver_negocio($usuario, $negocioId)) {
                $error = 'Elige un negocio válido.';
            } elseif ($cuerpo === '') {
                $error = 'Pega el contenido del correo del corte.';
            } else {
                // Mismo flujo que el cron: se guarda el correo crudo. El hash del
                // contenido evita duplicar si se pega dos veces el mismo correo.
                $msgId = 'manual-' . sha1($negocioId . '|' . $cuerpo);
                $correoId = Corte::correoPorMessageId($msgId);
                if ($correoId) {
                    Corte::actualizarCorreoManual($correoId, $asunto ?: null, $cuerpo);
                } else {
                    $correoId = Corte::crearCorreoManual($negocioId, $msgId, $asunto ?: null, $cuerpo);
                }

                $correo = Corte::correoCompletoPorId($correoId);
                $correo['negocio_id'] = $negocioId; // en manual el negocio lo elige el admin

                $corteId = Corte::procesarCorreo($correo);
                if ($corteId) {
                    \redirigir('corte.php?id=' . $corteId);
                }
                $error = 'No se pudo leer el corte: ' . (Corte::errorDeCorreo($correoId) ?: 'error desconocido');
            }
        }

        View::render('ingresos/pegar', compact('usuario', 'negocios', 'error'));
    }
}
