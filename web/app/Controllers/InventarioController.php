<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Inventario;

/**
 * Inventario: existencias por negocio, ajustes, mermas, kardex y el
 * encendido/apagado del control de stock (opcional por negocio).
 *
 * Corte de la Fase 6 (ver web/docs/01-plan-migracion.md). Mismo
 * comportamiento que web/inventario.php, que sigue intacto y es el que de
 * verdad atiende admin.minimark.cl hasta que este corte se verifique. CSRF
 * (H3) agregado en sus 3 formularios, nuevo en esta pantalla.
 */
final class InventarioController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('productos');
        require_once dirname(__DIR__, 2) . '/lib/auth.php';      // negocios_visibles()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';        // clp(), icono(), parsear_monto()
        require_once dirname(__DIR__, 2) . '/lib/auditoria.php'; // registrar_auditoria()

        $negocios = \negocios_visibles($usuario);
        $idsVisibles = array_map(fn($n) => (int)$n['id'], $negocios);
        $esAdmin = ($usuario['rol'] ?? '') === 'admin';

        $negocioId = Request::entero('negocio_id') ?: ($idsVisibles[0] ?? 0);
        if (!in_array($negocioId, $idsVisibles, true)) {
            $negocioId = $idsVisibles[0] ?? 0;
        }

        $error = '';
        $aviso = '';

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('/inventario/');
            $accion = Request::post('accion');
            try {
                $neg = Request::entero('negocio_id');
                if (!in_array($neg, $idsVisibles, true)) {
                    throw new \RuntimeException('Negocio inválido.');
                }

                if ($accion === 'control') {
                    if (!$esAdmin) {
                        throw new \RuntimeException('Solo el administrador puede encender o apagar el inventario.');
                    }
                    $on = isset($_POST['encender']);
                    Inventario::fijarControl($neg, $on);
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'control_stock', null,
                        ($on ? 'Encendió' : 'Apagó') . ' el control de inventario del negocio', null, 'negocio', $neg);
                    \redirigir(Request::base() . '/inventario/?negocio_id=' . $neg . '&msg=' . ($on ? 'on' : 'off'));
                }

                if ($accion === 'ajuste') {
                    $pid = Request::entero('producto_id');
                    $tipo = in_array(Request::post('tipo'), ['ajuste', 'merma'], true) ? Request::post('tipo') : 'ajuste';
                    $modo = Request::post('modo') ?: 'sumar';
                    $cant = \parsear_monto(Request::post('cantidad'));
                    $motivo = Request::post('motivo');
                    if ($cant === null) throw new \RuntimeException('Escribe una cantidad válida.');
                    if ($motivo === '') throw new \RuntimeException('Escribe el motivo del movimiento.');

                    $prod = Inventario::productoPorId($pid);
                    if (!$prod) throw new \RuntimeException('Ese producto no existe.');

                    if ($tipo === 'merma') {
                        $delta = -abs($cant);
                    } elseif ($modo === 'dejar_en') {
                        $delta = $cant - Inventario::stockDe($pid, $neg);
                    } else {
                        $delta = $cant;
                    }
                    if (abs($delta) < 0.001) throw new \RuntimeException('Ese movimiento no cambia nada.');

                    $nuevo = Inventario::moverStock($usuario, $neg, $pid, $delta, $tipo, null, null, $motivo);
                    if ($nuevo === null) throw new \RuntimeException('Este negocio no lleva control de inventario.');
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', $tipo === 'merma' ? 'merma' : 'ajuste_stock', null,
                        ($tipo === 'merma' ? 'Merma de ' : 'Ajuste de ') . $prod['nombre'] .
                        ': ' . ($delta > 0 ? '+' : '') . rtrim(rtrim(number_format($delta, 3, ',', '.'), '0'), ',') .
                        ' → quedan ' . rtrim(rtrim(number_format($nuevo, 3, ',', '.'), '0'), ',') .
                        ' · ' . $motivo, null, 'producto', $pid);
                    \redirigir(Request::base() . '/inventario/?negocio_id=' . $neg . '&q=' . urlencode($prod['codigo']) . '&msg=ajuste');
                }

                if ($accion === 'minimo') {
                    $pid = Request::entero('producto_id');
                    $min = \parsear_monto(Request::post('minimo'));
                    Inventario::fijarMinimo($pid, $neg, $min);
                    \redirigir(Request::base() . '/inventario/?negocio_id=' . $neg . '&q=' . urlencode(Request::post('q')) . '&msg=minimo');
                }

                if ($accion === 'conteo') {
                    $texto = trim(Request::post('conteo'));
                    if ($texto === '') throw new \RuntimeException('Pega el conteo (un producto por línea).');
                    $ok = 0;
                    $malos = [];
                    foreach (preg_split('/\R/', $texto) as $n => $linea) {
                        $linea = trim($linea);
                        if ($linea === '') continue;
                        $p = preg_split('/[\s;]+/', $linea, -1, PREG_SPLIT_NO_EMPTY);
                        if (count($p) < 2) {
                            $i = strpos($linea, ',');
                            if ($i === false) { $malos[] = 'línea ' . ($n + 1); continue; }
                            $p = [substr($linea, 0, $i), substr($linea, $i + 1)];
                        }
                        $cant = \parsear_monto(array_pop($p));
                        $cod = trim(implode(' ', $p));
                        if ($cant === null) { $malos[] = 'línea ' . ($n + 1); continue; }
                        $pid = Inventario::productoIdPorCodigo($cod);
                        if (!$pid) { $malos[] = $cod; continue; }
                        $delta = $cant - Inventario::stockDe($pid, $neg);
                        if (abs($delta) > 0.001) {
                            Inventario::moverStock($usuario, $neg, $pid, $delta, 'inicial', null, null, 'Conteo');
                        }
                        $ok++;
                    }
                    \registrar_auditoria(\App\Core\Database::pdo(), $usuario, 'productos', 'conteo_inventario', null,
                        "Cargó un conteo de inventario: $ok producto(s)", null, 'negocio', $neg);
                    $aviso = "Conteo cargado: $ok producto(s) actualizado(s)."
                           . ($malos ? ' No se reconocieron: ' . implode(', ', array_slice($malos, 0, 8))
                               . (count($malos) > 8 ? ' y ' . (count($malos) - 8) . ' más' : '') . '.' : '');
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        switch (Request::get('msg')) {
            case 'on':     $aviso = 'Inventario encendido. Ahora las ventas descuentan stock.'; break;
            case 'off':    $aviso = 'Inventario apagado. El POS deja de hablar de stock.'; break;
            case 'ajuste': $aviso = 'Movimiento registrado.'; break;
            case 'minimo': $aviso = 'Aviso de stock bajo actualizado.'; break;
        }

        $controla = Inventario::controla($negocioId);
        $q = Request::get('q');
        $solo = Request::get('solo');
        $resumen = $controla ? Inventario::resumen($negocioId) : null;
        $lista = ($controla && ($q !== '' || $solo !== '')) ? Inventario::productosConStock($negocioId, $q, $solo) : [];
        $verKardex = Request::entero('kardex');
        $kardex = $verKardex ? Inventario::kardex($verKardex, $negocioId) : [];
        $prodKardex = $verKardex ? Inventario::productoPorId($verKardex) : null;

        $baseAntiguo = $this->baseSitioAntiguo();
        $baseNuevo = Request::base();
        $csrfToken = Session::tokenCsrf();

        View::render('inventario/index', compact(
            'usuario', 'negocios', 'negocioId', 'esAdmin', 'error', 'aviso', 'controla', 'resumen',
            'q', 'solo', 'lista', 'verKardex', 'kardex', 'prodKardex', 'baseAntiguo', 'baseNuevo', 'csrfToken'
        ));
    }
}
