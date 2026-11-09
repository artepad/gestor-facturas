<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Models\Gasto;
use App\Models\Home;

/**
 * Tablero de mando. Cruza Ingresos (cortes), Fiados, Facturas y Gastos para
 * dar el estado de los negocios de un vistazo. Todo respeta los permisos del
 * rol y el alcance por negocios visibles; las secciones aparecen según
 * corresponda.
 *   home.php -> index()
 */
final class HomeController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirSesion();
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles(), puede()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // clp(), icono(), fecha_dmy()

        $verFacturas = \puede($usuario, 'facturas');
        $verFiados   = \puede($usuario, 'fiados');
        $verIngresos = \puede($usuario, 'ingresos');
        $verGastos   = \puede($usuario, 'gastos');

        $negocios = \negocios_visibles($usuario);
        $ids = array_map(fn($n) => (int)$n['id'], $negocios);
        $nNegocios = count($negocios);

        // Rango del mes en curso y del mes anterior (para comparativa)
        $inicioMes = date('Y-m-01');
        $hoy = date('Y-m-d');
        $inicioMesAnt = date('Y-m-01', strtotime('first day of last month'));
        $finMesAnt = date('Y-m-t', strtotime('last day of last month'));
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $mesNombre = $meses[(int)date('n')] . ' ' . date('Y');

        // --- Métricas por negocio (este mes) ---
        $ventasNeg = []; $comprasNeg = []; $gastosNeg = [];
        if ($ids && $verIngresos) $ventasNeg = Home::ventasPorNegocio($ids, $inicioMes, $hoy);
        if ($ids && $verFacturas) $comprasNeg = Home::comprasPorNegocio($ids, $inicioMes, $hoy);
        $nGastosMes = 0;
        if ($ids && $verGastos) {
            $gastosNeg = Gasto::porNegocio($ids, $inicioMes, $hoy);
            [, $nGastosMes] = Gasto::totalYCantidad($ids, $inicioMes, $hoy);
        }

        // Totales (KPIs) derivados de los mapas por negocio
        $ventasMes = array_sum($ventasNeg);
        $comprasMes = array_sum(array_column($comprasNeg, 'm'));
        $nFacturasMes = array_sum(array_column($comprasNeg, 'n'));
        $gastosMes = array_sum($gastosNeg);

        // Ventas del mes anterior (para la comparativa de la tarjeta destacada)
        $ventasMesAnt = ($ids && $verIngresos) ? Home::ventasTotal($ids, $inicioMesAnt, $finMesAnt) : 0.0;
        $varVentas = $ventasMesAnt > 0 ? (($ventasMes - $ventasMesAnt) / $ventasMesAnt * 100) : null;

        // Ventas por día del mes (gráfico)
        $porDia = ($ids && $verIngresos) ? Home::ventasPorDia($ids, $inicioMes, $hoy) : [];

        // --- Requiere atención (desglosado por negocio) ---
        $nombreNeg = [];
        foreach ($negocios as $n) $nombreNeg[(int)$n['id']] = $n['nombre'];
        $multiNeg = $nNegocios > 1;

        $facturasRevisarNeg = ($ids && $verFacturas) ? Home::facturasPorRevisarPorNegocio($ids) : [];
        $correosError = ($ids && $verIngresos) ? Home::correosErrorCount($ids) : 0;
        $fiadosNeg = ($ids && $verFiados) ? Home::fiadosConDeudaPorNegocio($ids) : [];

        $alertas = [];
        foreach ($facturasRevisarNeg as $nid => $cnt) {
            if ($cnt <= 0) continue;
            $alertas[] = ['modulo' => 'facturas', 'color' => 'modulo-naranjo',
                'titulo' => "$cnt factura(s) por revisar",
                'desc'   => ($multiNeg ? $nombreNeg[$nid] . ' · ' : '') . 'Baja confianza; revísalas para confirmarlas.',
                'link'   => 'panel_facturas.php'];
        }
        if ($correosError > 0) $alertas[] = [
            'modulo' => 'ingresos', 'color' => 'modulo-azul',
            'titulo' => "$correosError corte(s) sin procesar",
            'desc'   => 'Llegaron por correo pero no se pudieron leer.',
            'link'   => 'ingresos.php'];
        foreach ($fiadosNeg as $nid => $d) {
            if ($d['n'] <= 0) continue;
            $alertas[] = ['modulo' => 'fiados', 'color' => 'modulo-morado',
                'titulo' => $d['n'] . ' cliente(s) con deuda',
                'desc'   => ($multiNeg ? $nombreNeg[$nid] . ' · ' : '') . 'Por cobrar: ' . (\clp($d['monto']) ?: '$0'),
                'link'   => 'fiados.php'];
        }

        // --- KPIs (cada uno con el color de su valor) ---
        $kpis = [];
        if ($verIngresos) $kpis[] = ['Ventas del mes', \clp($ventasMes) ?: '$0', 'color' => 'valor-azul', 'borde' => 't-azul', 'destacado' => true, 'comp' => $varVentas, 'link' => 'ingresos.php'];
        if ($verGastos)   $kpis[] = ['Gastos del mes', \clp($gastosMes) ?: '$0', 'color' => 'valor-rojo', 'borde' => 't-rojo', 'sub' => $nGastosMes . ' gasto(s)', 'link' => 'gastos.php'];
        if ($verFacturas) $kpis[] = ['Compras del mes', \clp($comprasMes) ?: '$0', 'color' => 'valor-naranjo', 'borde' => 't-naranjo', 'sub' => $nFacturasMes . ' factura(s)', 'link' => 'panel_facturas.php'];
        if ($verIngresos && $verGastos) {
            $balanceMes = $ventasMes - $comprasMes - $gastosMes;
            $kpis[] = ['Balance del mes', \clp($balanceMes) ?: '$0',
                       'color' => $balanceMes >= 0 ? 'valor-verde' : 'valor-rojo',
                       'borde' => $balanceMes >= 0 ? 't-verde' : 't-rojo',
                       'sub'   => 'ventas − compras − gastos'];
        }

        // --- Accesos directos a Herramientas (los 4 más usados, sin Base de Datos) ---
        $herramientas = [];
        if (\puede($usuario, 'herramientas')) {
            $herramientas = [
                ['codigo-barras', 'Consultar Catálogo', 'herramienta_catalogo.php'],
                ['calculadora',   'Contador de Caja',   'herramienta_caja.php'],
                ['etiqueta',      'Gestor de Etiquetas', 'herramienta_etiquetas.php'],
                ['oferta',        'Etiquetas de Ofertas', 'herramienta_ofertas.php'],
            ];
        }

        View::render('home/index', compact(
            'usuario', 'verFacturas', 'verFiados', 'verIngresos', 'verGastos',
            'negocios', 'nNegocios', 'mesNombre', 'meses',
            'ventasNeg', 'comprasNeg', 'gastosNeg', 'porDia',
            'kpis', 'herramientas', 'alertas', 'multiNeg'
        ));
    }
}
