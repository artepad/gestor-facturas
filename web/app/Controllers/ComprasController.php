<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Compra;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Usuario;

/**
 * Compras: registrar la mercadería que llega (suma stock si el negocio lo
 * lleva) y proveedores.
 *
 * Fase 9 (despliegue): vive en web/compras.php (archivo puente que llama
 * directo a este Controlador) — misma direccion de siempre.
 */
final class ComprasController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('productos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';      // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // clp(), icono(), parsear_monto(), fecha_dmy()
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
            $this->verificarCsrf('compras.php');
            $accion = Request::post('accion');
            try {
                $neg = Request::entero('negocio_id');
                if (!in_array($neg, $idsVisibles, true)) {
                    throw new \RuntimeException('Negocio inválido.');
                }

                if ($accion === 'proveedor') {
                    $nombre = Request::post('nombre');
                    if ($nombre === '') throw new \RuntimeException('Escribe el nombre del proveedor.');
                    $id = Compra::crearProveedor(
                        (isset($_POST['ambos']) && $_POST['ambos']) ? null : $neg,
                        $nombre,
                        Request::post('rut') ?: null,
                        Request::post('telefono') ?: null,
                        Request::post('correo') ?: null
                    );
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'crear_proveedor', null,
                        'Creó el proveedor ' . $nombre, null, 'proveedor', $id);
                    \redirigir('compras.php?negocio_id=' . $neg . '&msg=proveedor');
                }

                if ($accion === 'asignar_producto') {
                    $proveedorId = Request::entero('proveedor_id');
                    $productoId = Request::entero('producto_id');
                    $proveedor = Compra::proveedorPorId($proveedorId);
                    $producto = $productoId ? Producto::porId($productoId) : null;
                    if (!$proveedor) throw new \RuntimeException('Ese proveedor no existe.');
                    if (!$producto) throw new \RuntimeException('Ese producto no existe.');
                    $codigoProveedor = trim(Request::post('codigo_proveedor'));
                    Compra::asignarProducto($proveedorId, $productoId, $codigoProveedor !== '' ? $codigoProveedor : null);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'asignar_producto_proveedor', null,
                        'Asignó ' . $producto['nombre'] . ' al proveedor ' . $proveedor['nombre'], null, 'producto', $productoId);
                    \redirigir('compras.php?negocio_id=' . $neg . '&proveedor=' . $proveedorId);
                }

                if ($accion === 'quitar_producto') {
                    $proveedorId = Request::entero('proveedor_id');
                    $productoId = Request::entero('producto_id');
                    $proveedor = Compra::proveedorPorId($proveedorId);
                    if (!$proveedor) throw new \RuntimeException('Ese proveedor no existe.');
                    Compra::quitarProducto($proveedorId, $productoId);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'quitar_producto_proveedor', null,
                        'Quitó un producto del proveedor ' . $proveedor['nombre'], null, 'producto', $productoId);
                    \redirigir('compras.php?negocio_id=' . $neg . '&proveedor=' . $proveedorId);
                }

                if ($accion === 'guardar_representante') {
                    $proveedorId = Request::entero('proveedor_id');
                    $proveedor = Compra::proveedorPorId($proveedorId);
                    if (!$proveedor) throw new \RuntimeException('Ese proveedor no existe.');

                    $repId = Request::entero('id');
                    $email = Request::post('email');
                    $nombreRep = Request::post('nombre');
                    $clave = (string)($_POST['clave'] ?? ''); // nunca se trimea una clave
                    $negociosSel = array_map('intval', $_POST['negocios'] ?? []);

                    if ($repId && !Usuario::porIdYProveedor($repId, $proveedorId)) {
                        throw new \RuntimeException('Ese representante no pertenece a este proveedor.');
                    }
                    if ($email === '') {
                        throw new \RuntimeException('El correo es obligatorio.');
                    }
                    if (!$repId && strlen($clave) < 6) {
                        throw new \RuntimeException('La contraseña debe tener al menos 6 caracteres.');
                    }

                    try {
                        if ($repId) {
                            if ($clave !== '') {
                                Usuario::actualizarRepresentanteConClave(
                                    $repId, $email, $nombreRep, $proveedorId, password_hash($clave, PASSWORD_DEFAULT)
                                );
                            } else {
                                Usuario::actualizarRepresentante($repId, $email, $nombreRep, $proveedorId);
                            }
                            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'editar_representante', null,
                                'Editó al representante ' . $nombreRep . ' de ' . $proveedor['nombre'], null, 'usuario', $repId);
                        } else {
                            $repId = Usuario::crearRepresentante($email, $nombreRep, password_hash($clave, PASSWORD_DEFAULT), $proveedorId);
                            \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'crear_representante', null,
                                'Creó al representante ' . $nombreRep . ' de ' . $proveedor['nombre'], null, 'usuario', $repId);
                        }
                        Usuario::reasignarNegocios($repId, $negociosSel);
                    } catch (\Throwable $e) {
                        throw new \RuntimeException(
                            str_contains($e->getMessage(), 'Duplicate')
                                ? 'Ya existe una cuenta con ese correo.'
                                : 'Error al guardar el representante.'
                        );
                    }
                    \redirigir('compras.php?negocio_id=' . $neg . '&proveedor=' . $proveedorId);
                }

                if ($accion === 'eliminar_representante') {
                    $proveedorId = Request::entero('proveedor_id');
                    $repId = Request::entero('id');
                    $rep = Usuario::porIdYProveedor($repId, $proveedorId);
                    if (!$rep) throw new \RuntimeException('Ese representante no existe para este proveedor.');
                    Usuario::eliminar($repId);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'eliminar_representante', null,
                        'Eliminó al representante ' . $rep['nombre'], null, 'usuario', $repId);
                    \redirigir('compras.php?negocio_id=' . $neg . '&proveedor=' . $proveedorId);
                }

                if ($accion === 'compra') {
                    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', Request::post('fecha'))
                           ? Request::post('fecha') : date('Y-m-d');
                    if ($fecha > date('Y-m-d')) throw new \RuntimeException('La fecha no puede ser futura.');

                    $texto = trim(Request::post('detalle'));
                    if ($texto === '') throw new \RuntimeException('Pega lo que llegó (un producto por línea).');
                    $items = [];
                    foreach (preg_split('/\R/', $texto) as $linea) {
                        $linea = trim($linea);
                        if ($linea === '') continue;
                        $p = preg_split('/[\s;]+/', $linea, -1, PREG_SPLIT_NO_EMPTY);
                        if (count($p) < 2) continue;
                        $cod = $p[0];
                        $cant = \parsear_monto($p[1]);
                        $costo = isset($p[2]) ? \parsear_monto($p[2]) : null;
                        if ($cant === null || $cant <= 0) continue;
                        $items[] = ['codigo' => $cod, 'cantidad' => $cant, 'costo_unitario' => $costo];
                    }
                    if (!$items) throw new \RuntimeException('No se entendió ninguna línea. Usa: código cantidad [costo].');

                    $r = Compra::registrar($usuario, $neg,
                                          Request::entero('proveedor_id') ?: null,
                                          $fecha, $items, Request::post('nota'),
                                          Request::entero('factura_id') ?: null);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'compra', null,
                        'Registró una compra de ' . $r['cargados'] . ' producto(s)'
                        . ($r['total'] > 0 ? ' por ' . \clp($r['total']) : ''),
                        null, 'compra', $r['id']);
                    $aviso = 'Compra registrada: ' . $r['cargados'] . ' producto(s)'
                           . (Inventario::controla($neg) ? ' sumados al stock.' : ' (el inventario está apagado, no se movió stock).')
                           . ($r['no_encontrados'] ? ' No están en el catálogo: '
                               . implode(', ', array_slice($r['no_encontrados'], 0, 6))
                               . (count($r['no_encontrados']) > 6 ? ' y ' . (count($r['no_encontrados']) - 6) . ' más' : '') . '.' : '');
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        if (Request::get('msg') === 'proveedor') $aviso = 'Proveedor creado.';

        $controla = Inventario::controla($negocioId);
        $proveedores = Compra::proveedoresDe($negocioId);
        $compras = Compra::listadas($negocioId);
        $verId = Request::entero('ver');
        $detalle = $verId ? Compra::items($verId) : [];
        $facturas = Compra::facturasRecientes($negocioId);

        $verProveedor = Request::entero('proveedor');
        $proveedorActual = $verProveedor ? Compra::proveedorPorId($verProveedor) : null;
        $productosProveedor = $proveedorActual ? Compra::productosDe($verProveedor) : [];
        $representantes = $proveedorActual ? Usuario::representantesDe($verProveedor) : [];
        $editarRepId = Request::entero('editar_rep');
        $editarRep = ($proveedorActual && $editarRepId) ? Usuario::porIdYProveedor($editarRepId, $verProveedor) : null;
        if ($editarRep) $editarRep['negocios_ids'] = Usuario::negociosIdsDe((int)$editarRep['id']);
        $negociosTodos = Usuario::negociosTodos();

        View::render('compras/index', compact(
            'usuario', 'negocios', 'negocioId', 'error', 'aviso', 'controla', 'proveedores',
            'compras', 'verId', 'detalle', 'facturas', 'verProveedor', 'proveedorActual', 'productosProveedor',
            'representantes', 'editarRep', 'negociosTodos'
        ));
    }

    /** Autocompletar de productos para asignarlos a un proveedor (ver compras/index.php). */
    public function buscarProductoJson(array $parametros = []): void
    {
        $this->exigirPermiso('productos');
        require_once dirname(__DIR__, 2) . '/lib/db.php'; // responder_json()

        $q = trim(Request::get('q'));
        if ($q === '') \responder_json(['productos' => []]);
        \responder_json(['productos' => Producto::buscarParaAsignar($q)]);
    }
}
