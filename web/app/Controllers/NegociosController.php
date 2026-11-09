<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Gasto;
use App\Models\Home;
use App\Models\Negocio;

/**
 * Negocios (almacenes): perfil, máquinas/tokens de sincronización y el
 * panel de análisis.
 *   negocios.php                  -> index()       (listado, solo admin)
 *   negocio_form.php[?id=N]       -> formulario()   (crear/editar, solo admin)
 *   negocio_tokens.php?id=N       -> tokens()       (PCs y tokens, solo admin)
 *   negocio_panel.php?id=N        -> panel()        (análisis, cualquier rol
 *                                     con acceso a ese negocio — no es "solo admin")
 */
final class NegociosController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('negocios');
        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()

        $negocios = Negocio::listadoConConteos();

        View::render('negocios/index', compact('usuario', 'negocios'));
    }

    public function formulario(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('negocios');
        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()

        $id = Request::entero('id');
        $negocio = [
            'nombre' => '', 'rut' => '', 'telefono' => '',
            'direccion' => '', 'correo' => '', 'activo' => 1,
        ];
        if ($id) {
            $negocio = Negocio::porId($id);
            if (!$negocio) \redirigir('negocios.php');
        }

        $error = '';
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf($id ? 'negocio_form.php?id=' . $id : 'negocio_form.php');

            $datos = [
                'nombre' => Request::post('nombre'),
                'rut' => Request::post('rut'),
                'telefono' => Request::post('telefono'),
                'direccion' => Request::post('direccion'),
                'correo' => Request::post('correo'),
                'activo' => isset($_POST['activo']) ? 1 : 0,
            ];
            if ($datos['nombre'] === '') {
                $error = 'El nombre del negocio es obligatorio.';
                $negocio = array_merge($negocio, $datos);
            } else {
                if ($id) {
                    Negocio::actualizar($id, $datos);
                } else {
                    Negocio::crear($datos);
                }
                \redirigir('negocios.php');
            }
        }

        $titulo = $id ? 'Editar negocio' : 'Crear negocio';

        View::render('negocios/formulario', compact('usuario', 'id', 'negocio', 'error', 'titulo'));
    }

    public function tokens(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('negocios');
        require_once dirname(__DIR__, 2) . '/lib/ui.php'; // icono()

        $id = Request::entero('id');
        $negocio = Negocio::porId($id);
        if (!$negocio) \redirigir('negocios.php');

        $tokenNuevo = '';
        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('negocio_tokens.php?id=' . $id);
            $accion = Request::post('accion');

            if ($accion === 'generar') {
                $nombrePc = Request::post('nombre_pc') ?: 'PC';
                $tokenNuevo = bin2hex(random_bytes(24));
                Negocio::crearMaquina($id, $nombrePc, hash('sha256', $tokenNuevo));
            } elseif ($accion === 'eliminar') {
                Negocio::eliminarMaquina(Request::entero('maquina_id'), $id);
            }
        }

        $maquinas = Negocio::maquinasDe($id);

        View::render('negocios/tokens', compact('usuario', 'id', 'negocio', 'tokenNuevo', 'maquinas'));
    }

    public function panel(array $parametros = []): void
    {
        $usuario = $this->exigirSesion();
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // puede(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono(), fecha_dmy()

        $id = Request::entero('id');
        if (!$id || !\puede_ver_negocio($usuario, $id)) {
            \redirigir('home.php');
        }
        $negocio = Negocio::porId($id);
        if (!$negocio) {
            \redirigir('home.php');
        }

        $verIngresos = \puede($usuario, 'ingresos');
        $verFacturas = \puede($usuario, 'facturas');
        $verGastos = \puede($usuario, 'gastos');
        $verFiados = \puede($usuario, 'fiados');

        $hoy = date('Y-m-d');
        $fDesde = Request::get('desde') ?: date('Y-m-01');
        $fHasta = Request::get('hasta') ?: $hoy;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)) $fDesde = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)) $fHasta = $hoy;
        $inicioMesAnt = date('Y-m-01', strtotime('first day of last month'));
        $finMesAnt = date('Y-m-t', strtotime('last day of last month'));

        $ventas = ['total' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0,
                   'credito' => 0, 'vales' => 0, 'salidas' => 0, 'nventas' => 0];
        $porDia = []; $porDepto = [];
        if ($verIngresos) {
            $ventas = Negocio::ventasResumen($id, $fDesde, $fHasta);
            $porDia = Home::ventasPorDia([$id], $fDesde, $fHasta);
            $porDepto = Negocio::ventasPorDepartamento($id, $fDesde, $fHasta);
        }

        $compras = 0.0; $nFacturas = 0; $topComprados = [];
        if ($verFacturas) {
            $mapaCompras = Home::comprasPorNegocio([$id], $fDesde, $fHasta);
            $compras = $mapaCompras[$id]['m'] ?? 0.0;
            $nFacturas = $mapaCompras[$id]['n'] ?? 0;
            $topComprados = Negocio::topComprados($id, $fDesde, $fHasta);
        }

        $gastosTotal = 0.0; $gastosCat = [];
        if ($verGastos) {
            $gastosTotal = Gasto::totalPeriodo([$id], $fDesde, $fHasta);
            $gastosCat = Gasto::porCategoria([$id], $fDesde, $fHasta);
        }

        $deuda = $verFiados ? Negocio::deudaVigente($id) : 0.0;

        $balance = $ventas['total'] - $compras - $gastosTotal;

        $hero = null;
        if ($verIngresos && $verGastos) {
            $hero = ['titulo' => 'Resultado del período', 'valor' => \clp($balance) ?: '$0',
                     'signo' => $balance >= 0 ? '+ ' : '− ',
                     'color' => $balance >= 0 ? 'valor-verde' : 'valor-rojo',
                     'sub' => 'ventas − compras − gastos'];
        } elseif ($verIngresos) {
            $hero = ['titulo' => 'Ventas del período', 'valor' => \clp($ventas['total']) ?: '$0',
                     'signo' => '', 'color' => 'valor-azul', 'sub' => $ventas['nventas'] . ' venta(s)'];
        } elseif ($verGastos) {
            $hero = ['titulo' => 'Gastos del período', 'valor' => \clp($gastosTotal) ?: '$0',
                     'signo' => '', 'color' => 'valor-rojo', 'sub' => 'total gastado'];
        }
        if ($hero && abs($balance) === 0.0) $hero['signo'] = '';

        $kpis = [];
        if ($verIngresos) $kpis[] = ['Ventas', \clp($ventas['total']) ?: '$0', 'valor-azul', 'k-azul', 'ingresos'];
        if ($verFacturas) $kpis[] = ['Compras', \clp($compras) ?: '$0', 'valor-naranjo', 'k-naranjo', 'facturas'];
        if ($verGastos) $kpis[] = ['Gastos', \clp($gastosTotal) ?: '$0', 'valor-rojo', 'k-rojo', 'gastos'];
        if ($verIngresos && $verGastos) $kpis[] = ['Balance', \clp($balance) ?: '$0', $balance >= 0 ? 'valor-verde' : 'valor-rojo', $balance >= 0 ? 'k-verde' : 'k-rojo', 'ingresos'];
        if ($verFiados) $kpis[] = ['Por cobrar', \clp($deuda) ?: '$0', $deuda > 0 ? 'valor-rojo' : '', 'k-morado', 'fiados'];
        if ($verIngresos) $kpis[] = ['N° de ventas', number_format($ventas['nventas'], 0, ',', '.'), '', 'k-gris', 'reloj'];

        $esEsteMes = ($fDesde === date('Y-m-01') && $fHasta === $hoy);
        $esMesAnt = ($fDesde === $inicioMesAnt && $fHasta === $finMesAnt);
        $esPersonalizado = !$esEsteMes && !$esMesAnt;

        $metodos = [];
        if ($verIngresos) {
            foreach ([['Efectivo', $ventas['efectivo']], ['Tarjeta', $ventas['tarjeta']],
                      ['Transferencia', $ventas['transferencia']], ['Crédito', $ventas['credito']],
                      ['Vales', $ventas['vales']]] as $m) {
                if ($m[1] > 0) $metodos[] = $m;
            }
        }

        View::render('negocios/panel', compact(
            'usuario', 'id', 'negocio', 'verIngresos', 'verFacturas', 'verGastos', 'verFiados',
            'fDesde', 'fHasta', 'inicioMesAnt', 'finMesAnt', 'ventas', 'porDia', 'porDepto',
            'compras', 'topComprados', 'gastosTotal', 'gastosCat', 'hero', 'kpis',
            'esEsteMes', 'esMesAnt', 'esPersonalizado', 'metodos'
        ));
    }
}
