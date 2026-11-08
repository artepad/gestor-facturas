<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Cliente;

/**
 * Fiados: clientes a crédito del almacén (cuenta corriente), sus fiados y
 * abonos, y el estado de cuenta imprimible.
 *   fiados.php                        -> listado()  (listado de clientes con saldo)
 *   cliente.php?id=N                  -> ficha()     (ficha + registrar/editar/eliminar movimientos)
 *   cliente_form.php[?id=N]           -> formulario()(crear/editar cliente)
 *   estado_cuenta_imprimir.php?id=N   -> estadoCuenta()
 *
 * Primer módulo fuera del POS migrado a la arquitectura en capas (el código
 * viejo no tenía CSRF en estos formularios; acá se agrega, mismo patrón que
 * el resto del código nuevo).
 */
final class FiadosController extends BaseController
{
    public function listado(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('fiados');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

        $fNegocio = Request::entero('negocio');
        $fTexto = Request::get('q');

        $clientes = Cliente::conSaldo($idsVisibles, $fNegocio, $fTexto);

        $totalDeuda = 0;
        foreach ($clientes as $c) { $totalDeuda += max(0, (float)$c['saldo']); }

        if ($fNegocio) {
            foreach ($negocios as $n) { if ((int)$n['id'] === $fNegocio) $viendo = $n['nombre']; }
        } elseif (count($negocios) === 1) {
            $viendo = $negocios[0]['nombre'];
        }
        $viendo = $viendo ?? 'Todos los negocios';

        View::render('fiados/listado', compact(
            'usuario', 'negocios', 'fNegocio', 'fTexto', 'clientes', 'totalDeuda', 'viendo'
        ));
    }

    public function ficha(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('fiados');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';      // negocios_visibles(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // clp(), icono(), fecha_dmy(), parsear_monto()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php'; // registrar_auditoria()

        $esAdmin = ($usuario['rol'] ?? '') === 'admin';

        $id = Request::entero('id');
        $cliente = Cliente::porId($id);
        if (!$cliente || !\puede_ver_negocio($usuario, (int)$cliente['negocio_id'])) {
            \redirigir('fiados.php');
        }

        $error = '';
        $accionError = '';

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('cliente.php?id=' . $id);
            $accion = Request::post('accion');
            $nombreCliente = trim($cliente['nombre'] . ' ' . $cliente['apellido']);

            // Editar/eliminar son solo para admin: un vendedor no puede llegar
            // aquí por la UI, pero igual se bloquea en el backend.
            $accionesAdmin = ['editar_fiado', 'editar_abono', 'eliminar_fiado', 'eliminar_abono', 'eliminar_cliente'];
            if (in_array($accion, $accionesAdmin, true) && !$esAdmin) {
                \redirigir('cliente.php?id=' . $id);
            }

            if (in_array($accion, ['nuevo_fiado', 'nuevo_abono', 'editar_fiado', 'editar_abono'], true)) {
                $accionError = $accion;
                $fecha = Request::post('fecha');
                $monto = \parsear_monto(Request::post('monto'));
                $texto = Request::post('texto');
                $texto = $texto !== '' ? $texto : null;
                $refId = Request::entero('ref_id');

                if ($monto === null || $monto <= 0) {
                    $error = 'Ingresa un monto válido mayor a 0.';
                } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                    $error = 'La fecha no es válida.';
                } else {
                    switch ($accion) {
                        case 'nuevo_fiado':
                            $nid = Cliente::crearFiado($id, $fecha, $monto, $texto);
                            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'crear_fiado', $id,
                                "Registró fiado de " . \clp($monto) . " a $nombreCliente" . ($texto ? " ($texto)" : ''),
                                null, 'fiado', $nid);
                            break;
                        case 'nuevo_abono':
                            $nid = Cliente::crearAbono($id, $fecha, $monto, $texto);
                            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'crear_abono', $id,
                                "Registró abono de " . \clp($monto) . " de $nombreCliente" . ($texto ? " ($texto)" : ''),
                                null, 'abono', $nid);
                            break;
                        case 'editar_fiado':
                            $ant = Cliente::fiadoPorId($refId, $id);
                            Cliente::actualizarFiado($refId, $id, $fecha, $monto, $texto);
                            if ($ant) \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'editar_fiado', $id,
                                "Editó fiado de $nombreCliente (" . \clp((float)$ant['monto']) . " → " . \clp($monto) . ")",
                                \cambios_json($ant, ['fecha' => $fecha, 'monto' => $monto, 'descripcion' => $texto], ['fecha', 'monto', 'descripcion']),
                                'fiado', $refId);
                            break;
                        case 'editar_abono':
                            $ant = Cliente::abonoPorId($refId, $id);
                            Cliente::actualizarAbono($refId, $id, $fecha, $monto, $texto);
                            if ($ant) \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'editar_abono', $id,
                                "Editó abono de $nombreCliente (" . \clp((float)$ant['monto']) . " → " . \clp($monto) . ")",
                                \cambios_json($ant, ['fecha' => $fecha, 'monto' => $monto, 'nota' => $texto], ['fecha', 'monto', 'nota']),
                                'abono', $refId);
                            break;
                    }
                    \redirigir('cliente.php?id=' . $id);
                }
            } elseif ($accion === 'eliminar_fiado') {
                $refId = Request::entero('ref_id');
                $reg = Cliente::fiadoPorId($refId, $id);
                Cliente::eliminarFiado($refId, $id);
                if ($reg) \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'eliminar_fiado', $id,
                    "Eliminó fiado de " . \clp((float)$reg['monto']) . " de $nombreCliente (" . \fecha_dmy($reg['fecha']) . ")",
                    json_encode($reg, JSON_UNESCAPED_UNICODE), 'fiado', $refId);
                \redirigir('cliente.php?id=' . $id);
            } elseif ($accion === 'eliminar_abono') {
                $refId = Request::entero('ref_id');
                $reg = Cliente::abonoPorId($refId, $id);
                Cliente::eliminarAbono($refId, $id);
                if ($reg) \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'eliminar_abono', $id,
                    "Eliminó abono de " . \clp((float)$reg['monto']) . " de $nombreCliente (" . \fecha_dmy($reg['fecha']) . ")",
                    json_encode($reg, JSON_UNESCAPED_UNICODE), 'abono', $refId);
                \redirigir('cliente.php?id=' . $id);
            } elseif ($accion === 'eliminar_cliente') {
                \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'eliminar_cliente', $id,
                    "Eliminó al cliente $nombreCliente y todo su historial de fiados/abonos", null, 'cliente', $id);
                Cliente::eliminar($id);   // cascada borra historial
                \redirigir('fiados.php');
            }
        }

        $totales = Cliente::totales($id);
        $totalFiado = $totales['fiado'];
        $totalAbono = $totales['abono'];
        $saldo = $totales['saldo'];
        $movs = Cliente::movimientos($id);

        $nombreCompleto = trim($cliente['nombre'] . ' ' . $cliente['apellido']);
        $hoy = date('Y-m-d');
        $claseSaldo = $saldo > 0 ? 'saldo-deuda' : 'saldo-ok';

        $inicioSemana = date('Y-m-d', strtotime('-6 days'));
        $inicioMes = date('Y-m-01');

        $editFiado = null;
        $editAbono = null;
        if ($accionError === 'editar_fiado' && $error !== '') {
            $editFiado = ['id' => Request::entero('ref_id'), 'fecha' => Request::post('fecha') ?: $hoy,
                          'monto' => Request::post('monto'), 'texto' => Request::post('texto')];
        } elseif ($esAdmin && Request::get('editar_fiado') !== '') {
            $editFiado = $this->cargarEdit('fiados', (int)Request::get('editar_fiado'), $id, 'descripcion');
        }
        if ($accionError === 'editar_abono' && $error !== '') {
            $editAbono = ['id' => Request::entero('ref_id'), 'fecha' => Request::post('fecha') ?: $hoy,
                          'monto' => Request::post('monto'), 'texto' => Request::post('texto')];
        } elseif ($esAdmin && Request::get('editar_abono') !== '') {
            $editAbono = $this->cargarEdit('abonos', (int)Request::get('editar_abono'), $id, 'nota');
        }
        $abrirFiado = $editFiado !== null || $accionError === 'nuevo_fiado';
        $abrirAbono = $editAbono !== null || $accionError === 'nuevo_abono';

        View::render('fiados/ficha', compact(
            'usuario', 'esAdmin', 'id', 'cliente', 'error', 'totalFiado', 'totalAbono', 'saldo',
            'movs', 'nombreCompleto', 'hoy', 'claseSaldo', 'inicioSemana', 'inicioMes',
            'editFiado', 'editAbono', 'abrirFiado', 'abrirAbono'
        ));
    }

    private function cargarEdit(string $tabla, int $movId, int $clienteId, string $campoTexto): ?array
    {
        $r = $tabla === 'fiados' ? Cliente::fiadoPorId($movId, $clienteId) : Cliente::abonoPorId($movId, $clienteId);
        return $r ? ['id' => (int)$r['id'], 'fecha' => $r['fecha'],
                     'monto' => number_format((float)$r['monto'], 0, ',', '.'),
                     'texto' => $r[$campoTexto]] : null;
    }

    public function formulario(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('fiados');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';      // negocios_visibles(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // icono()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php'; // registrar_auditoria()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);

        $id = Request::entero('id');
        $cliente = [
            'negocio_id' => $idsVisibles[0] ?? 0,
            'nombre' => '', 'apellido' => '', 'telefono' => '', 'direccion' => '', 'correo' => '',
        ];

        if ($id) {
            $cliente = Cliente::porId($id);
            if (!$cliente || !\puede_ver_negocio($usuario, (int)$cliente['negocio_id'])) {
                \redirigir('fiados.php');
            }
        }

        $error = '';
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf($id ? 'cliente_form.php?id=' . $id : 'cliente_form.php');

            $datos = [
                'negocio_id' => Request::entero('negocio_id'),
                'nombre'     => Request::post('nombre'),
                'apellido'   => Request::post('apellido'),
                'telefono'   => Request::post('telefono'),
                'direccion'  => Request::post('direccion'),
                'correo'     => Request::post('correo'),
            ];

            if (!in_array($datos['negocio_id'], $idsVisibles, true)) {
                $error = 'Selecciona un negocio válido.';
            } elseif ($datos['nombre'] === '') {
                $error = 'El nombre del cliente es obligatorio.';
            } else {
                $nombreCliente = trim($datos['nombre'] . ' ' . $datos['apellido']);
                if ($id) {
                    // Al editar no se permite cambiar de negocio (evita fugas entre negocios).
                    Cliente::actualizar($id, $datos);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'editar_cliente', $id,
                        "Editó los datos del cliente $nombreCliente", null, 'cliente', $id);
                } else {
                    $id = Cliente::crear($datos);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'fiados', 'crear_cliente', $id,
                        "Creó al cliente $nombreCliente", null, 'cliente', $id);
                }
                \redirigir('cliente.php?id=' . $id);
            }
            $cliente = array_merge($cliente, $datos);
        }

        $titulo = $id ? 'Editar cliente' : 'Nuevo cliente';

        View::render('fiados/formulario', compact(
            'usuario', 'negocios', 'id', 'cliente', 'error', 'titulo'
        ));
    }

    public function estadoCuenta(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('fiados');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // h(), icono(), clp(), fecha_dmy()

        $id = Request::entero('id');
        $cliente = \App\Core\Database::uno(
            "SELECT c.*, n.nombre AS negocio_nombre, n.rut AS negocio_rut,
                    n.telefono AS negocio_telefono, n.direccion AS negocio_direccion,
                    n.correo AS negocio_correo
               FROM clientes c JOIN negocios n ON n.id = c.negocio_id
              WHERE c.id = ?",
            [$id]
        );
        if (!$cliente || !\puede_ver_negocio($usuario, (int)$cliente['negocio_id'])) {
            \redirigir('fiados.php');
        }

        $fmt = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = preg_match($fmt, Request::get('desde')) ? Request::get('desde') : null;
        $hasta = preg_match($fmt, Request::get('hasta')) ? Request::get('hasta') : null;
        if ($desde && $hasta && $desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }
        $esHistorial = ($desde === null && $hasta === null);

        if ($esHistorial) {
            $etiquetaPeriodo = 'Historial completo';
        } elseif ($desde && $hasta) {
            $etiquetaPeriodo = 'Del ' . \fecha_dmy($desde) . ' al ' . \fecha_dmy($hasta);
        } elseif ($desde) {
            $etiquetaPeriodo = 'Desde el ' . \fecha_dmy($desde);
        } else {
            $etiquetaPeriodo = 'Hasta el ' . \fecha_dmy($hasta);
        }

        $movs = Cliente::movimientosPeriodo($id, $desde, $hasta);

        $totalCompras = 0.0; $totalAbonos = 0.0;
        foreach ($movs as $m) {
            if ($m['tipo'] === 'fiado') $totalCompras += $m['monto']; else $totalAbonos += $m['monto'];
        }

        $saldoAnterior = $desde ? Cliente::saldoAntesDe($id, $desde) : 0.0;
        $saldoFinal = $saldoAnterior + $totalCompras - $totalAbonos;

        $nombreCompleto = trim($cliente['nombre'] . ' ' . $cliente['apellido']);
        $hoy = date('Y-m-d');

        View::render('fiados/estado_cuenta', compact(
            'id', 'cliente', 'desde', 'hasta', 'esHistorial', 'etiquetaPeriodo', 'movs',
            'totalCompras', 'totalAbonos', 'saldoAnterior', 'saldoFinal', 'nombreCompleto', 'hoy'
        ));
    }
}
