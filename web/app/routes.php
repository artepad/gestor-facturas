<?php
declare(strict_types=1);

/**
 * Tabla de rutas de todo el panel (Fase 19 de la migración, ver
 * web/docs/01-plan-migracion.md). Cada ruta es exactamente la misma
 * dirección que antes atendía un archivo "puente" en la raíz de web/ (ej.
 * "/herramienta_caja.php") — acá solo se registra a qué Controlador y
 * método va cada una. index.php (el único archivo real que queda en la
 * raíz) crea el Router e incluye este archivo.
 *
 * Los archivos puente de antes respondían en CUALQUIER método HTTP (PHP los
 * ejecutaba igual en GET o POST; cada Controlador decidía adentro qué hacer
 * según Request::metodo()). Para no cambiar ese comportamiento, acá se
 * registra cada ruta en los dos métodos con el helper `ambos()`.
 *
 * Quedan FUERA de esta tabla (siguen siendo archivos reales, servidos
 * directo por Apache, ver .htaccess): api/facturas.php, migrar.php y
 * procesar_cortes.php — ninguno es una pantalla de sesión.
 */

use App\Controllers\AuthController;
use App\Controllers\CajaController;
use App\Controllers\CatalogoController;
use App\Controllers\ComprasController;
use App\Controllers\EtiquetasController;
use App\Controllers\FacturasController;
use App\Controllers\FiadosController;
use App\Controllers\GastosController;
use App\Controllers\HerramientasController;
use App\Controllers\HomeController;
use App\Controllers\IngresosController;
use App\Controllers\InventarioController;
use App\Controllers\NegociosController;
use App\Controllers\OfertasController;
use App\Controllers\PosApiController;
use App\Controllers\PosController;
use App\Controllers\PreciosFvController;
use App\Controllers\PromocionesController;
use App\Controllers\ProductosController;
use App\Controllers\ServiciosController;
use App\Controllers\UsuariosController;
use App\Controllers\VerPdfController;
use App\Core\Request;

/** @var \App\Core\Router $router */

/** Registra la misma ruta/destino en GET y POST (ver nota arriba). */
$ambos = function (string $ruta, callable|array $destino) use ($router): void {
    $router->get($ruta, $destino);
    $router->post($ruta, $destino);
};

// --- Sistema ---
$ambos('/', function (): void {
    require_once dirname(__DIR__) . '/lib/auth.php';
    \redirigir(\usuario_actual() ? 'home.php' : 'login.php');
});
$ambos('/login.php', [AuthController::class, 'login']);
$ambos('/logout.php', [AuthController::class, 'logout']);
$ambos('/home.php', [HomeController::class, 'index']);
$ambos('/ver_pdf.php', [VerPdfController::class, 'index']);

// --- Usuarios ---
$ambos('/usuarios.php', [UsuariosController::class, 'index']);

// --- Facturas ---
$ambos('/panel_facturas.php', [FacturasController::class, 'panel']);
$ambos('/factura.php', [FacturasController::class, 'detalle']);

// --- Negocios ---
$ambos('/negocios.php', [NegociosController::class, 'index']);
$ambos('/negocio_form.php', [NegociosController::class, 'formulario']);
$ambos('/negocio_tokens.php', [NegociosController::class, 'tokens']);
$ambos('/negocio_panel.php', [NegociosController::class, 'panel']);

// --- Fiados ---
$ambos('/fiados.php', [FiadosController::class, 'listado']);
$ambos('/cliente_form.php', [FiadosController::class, 'formulario']);
$ambos('/cliente.php', [FiadosController::class, 'ficha']);
$ambos('/estado_cuenta_imprimir.php', [FiadosController::class, 'estadoCuenta']);

// --- Gastos ---
$ambos('/gastos.php', [GastosController::class, 'panel']);
$ambos('/gasto_form.php', [GastosController::class, 'formulario']);
$ambos('/categorias_gasto.php', [GastosController::class, 'categorias']);
$ambos('/gastos_fijos.php', [GastosController::class, 'fijos']);

// --- Ingresos ---
$ambos('/ingresos.php', [IngresosController::class, 'panel']);
$ambos('/corte.php', [IngresosController::class, 'detalle']);
$ambos('/corte_pegar.php', [IngresosController::class, 'pegar']);

// --- Herramientas ---
$ambos('/herramientas.php', [HerramientasController::class, 'tablero']);
$ambos('/herramienta_caja.php', [HerramientasController::class, 'caja']);
$ambos('/herramienta_productos.php', [ProductosController::class, 'panel']);
$ambos('/producto_form.php', [ProductosController::class, 'formulario']);
$ambos('/historial_productos.php', [ProductosController::class, 'historial']);
$ambos('/herramienta_etiquetas.php', [EtiquetasController::class, 'formulario']);
$ambos('/etiquetas_imprimir.php', [EtiquetasController::class, 'imprimir']);
$ambos('/productos_buscar.php', [EtiquetasController::class, 'buscarJson']);
$ambos('/herramienta_ofertas.php', [OfertasController::class, 'formulario']);
$ambos('/ofertas_imprimir.php', [OfertasController::class, 'imprimir']);
$ambos('/herramienta_catalogo.php', [CatalogoController::class, 'index']);
$ambos('/catalogo_buscar.php', [CatalogoController::class, 'buscarJson']);

// --- POS ---
$ambos('/pos.php', [PosController::class, 'index']);
$ambos('/pos_api.php', [PosApiController::class, 'index']);
// caja.php: el único puente que no llamaba a un solo método — un match()
// por "accion" del POST elegía abrir()/movimiento()/cerrar(); se conserva
// igual acá, dentro de la ruta.
$ambos('/caja.php', function (): void {
    $c = new CajaController();
    if (Request::metodo() === 'POST') {
        match (Request::post('accion')) {
            'abrir'      => $c->abrir([]),
            'movimiento' => $c->movimiento([]),
            'cerrar'     => $c->cerrar([]),
            default      => $c->index([]),
        };
    } else {
        $c->index([]);
    }
});
$ambos('/inventario.php', [InventarioController::class, 'index']);
$ambos('/compras.php', [ComprasController::class, 'index']);
$ambos('/promociones.php', [PromocionesController::class, 'index']);
$ambos('/servicios.php', [ServiciosController::class, 'index']);
$ambos('/precios_fv.php', [PreciosFvController::class, 'index']);
$ambos('/precios_fv_imprimir.php', [PreciosFvController::class, 'imprimir']);
