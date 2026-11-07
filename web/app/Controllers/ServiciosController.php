<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\CajaSesion;
use App\Models\Convenio;

/**
 * Servicios / Recaudación: el saldo de cada convenio (Mercado Pago y otros),
 * las recargas, las liquidaciones y las comisiones ganadas.
 *
 * Corte de la Fase 6 (ver web/docs/01-plan-migracion.md). Mismo
 * comportamiento que web/servicios.php, que sigue intacto y es el que de
 * verdad atiende admin.minimark.cl hasta que este corte se verifique. CSRF
 * (H3) agregado en sus 3 formularios, nuevo en esta pantalla.
 */
final class ServiciosController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('ingresos'); // 'ingresos' = solo administrador
        require_once dirname(__DIR__, 2) . '/lib/auth.php';      // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // clp(), icono(), parsear_monto()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php'; // registrar_auditoria()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

        $negocioId = Request::entero('negocio_id') ?: ($idsVisibles[0] ?? 0);
        if (!in_array($negocioId, $idsVisibles, true)) {
            $negocioId = $idsVisibles[0] ?? 0;
        }

        $error = '';
        $aviso = '';

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('/servicios/');
            $accion = Request::post('accion');
            try {
                if ($accion === 'crear_convenio') {
                    $nombre = Request::post('nombre');
                    $modal = Request::post('modalidad') === 'postpago' ? 'postpago' : 'prepago';
                    $minimo = \parsear_monto(Request::post('saldo_minimo'));
                    $neg = Request::entero('negocio_id');
                    if ($nombre === '') throw new \RuntimeException('Ponle un nombre al convenio.');
                    if (!in_array($neg, $idsVisibles, true)) throw new \RuntimeException('Negocio inválido.');
                    $id = Convenio::crear($neg, $nombre, $modal, $minimo);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'crear_convenio', null,
                        'Creó el convenio ' . $nombre . ' (' . $modal . ')', null, 'convenio', $id);
                    \redirigir(Request::base() . '/servicios/?negocio_id=' . $neg . '&msg=convenio');
                }

                if ($accion === 'operacion') {
                    $cid = Request::entero('convenio_id');
                    $tipo = Request::post('tipo');
                    $monto = \parsear_monto(Request::post('monto'));
                    if ($monto === null) throw new \RuntimeException('Escribe un monto válido.');
                    $ses = Request::post('origen_fondos') === 'caja'
                         ? CajaSesion::abiertaDe((int)$usuario['id']) : null;
                    $r = Convenio::registrarOperacion(
                        $usuario, $cid, $tipo, $monto, Request::post('origen_fondos') ?: 'banco',
                        Request::post('referencia'), Request::post('nota'), $ses
                    );
                    $etiquetas = ['recarga_saldo' => 'Recargó saldo de', 'liquidacion' => 'Liquidó a',
                                  'comision' => 'Registró comisión de'];
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', $tipo, null,
                        ($etiquetas[$tipo] ?? $tipo) . ' ' . $r['convenio']['nombre'] . ' por ' . \clp($monto),
                        null, 'convenio', $cid);
                    \redirigir(Request::base() . '/servicios/?negocio_id=' . $negocioId . '&msg=operacion');
                }

                if ($accion === 'ajustar') {
                    $cid = Request::entero('convenio_id');
                    $real = \parsear_monto(Request::post('saldo_real'));
                    if ($real === null) throw new \RuntimeException('Escribe el saldo que muestra el portal.');
                    $r = Convenio::ajustarSaldo($cid, $negocioId, $real);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'ventas', 'ajustar_convenio', null,
                        'Cuadró ' . $r['convenio']['nombre'] . ': de ' . \clp($r['saldo_anterior']) . ' a ' . \clp($real),
                        null, 'convenio', $cid);
                    \redirigir(Request::base() . '/servicios/?negocio_id=' . $negocioId . '&msg=ajuste');
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        switch (Request::get('msg')) {
            case 'convenio':  $aviso = 'Convenio creado.'; break;
            case 'operacion': $aviso = 'Operación registrada. El saldo estimado ya está actualizado.'; break;
            case 'ajuste':     $aviso = 'Saldo cuadrado con el portal del proveedor.'; break;
        }

        $convenios = Convenio::conSaldo($negocioId, false);
        $verId = Request::entero('ver') ?: (int)($convenios[0]['id'] ?? 0);
        $verConvenio = null;
        foreach ($convenios as $c) if ((int)$c['id'] === $verId) $verConvenio = $c;
        $ops = $verConvenio ? Convenio::operaciones((int)$verConvenio['id']) : [];

        $baseAntiguo = $this->baseSitioAntiguo();
        $baseNuevo = Request::base();
        $csrfToken = Session::tokenCsrf();

        View::render('servicios/index', compact(
            'usuario', 'negocios', 'negocioId', 'error', 'aviso', 'convenios', 'verId',
            'verConvenio', 'ops', 'baseAntiguo', 'baseNuevo', 'csrfToken'
        ));
    }
}
