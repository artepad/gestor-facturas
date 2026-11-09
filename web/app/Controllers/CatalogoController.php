<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Producto;

/**
 * Consultar Catálogo: buscador móvil-primero (solo lectura) con escáner de
 * código de barras. Distinto del Gestor de Etiquetas: este devuelve TODOS
 * los campos del producto, no solo nombre/precio.
 *   herramienta_catalogo.php -> index()
 *   catalogo_buscar.php      -> buscarJson()
 */
final class CatalogoController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('herramientas');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // icono()

        $negocios = \negocios_visibles($usuario);
        $negocioId = Request::entero('negocio');
        if ($negocioId && !\puede_ver_negocio($usuario, $negocioId)) $negocioId = 0;
        if (!$negocioId && $negocios) $negocioId = (int)$negocios[0]['id'];

        $totalP = Producto::contar();
        $deptos = Producto::departamentos();

        $nombreNegocio = '';
        foreach ($negocios as $n) if ((int)$n['id'] === $negocioId) $nombreNegocio = $n['nombre'];

        View::render('catalogo/index', compact(
            'usuario', 'negocios', 'negocioId', 'totalP', 'deptos', 'nombreNegocio'
        ));
    }

    /** Igual que EtiquetasController::buscarJson(): permiso redirige, negocio inválido responde JSON 403. */
    public function buscarJson(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('herramientas');
        require_once dirname(__DIR__, 2) . '/lib/db.php';   // responder_json()
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // puede_ver_negocio()

        $negocioId = Request::entero('negocio_id');
        $q = Request::get('q');
        $departamento = Request::get('departamento');
        if (!$negocioId || !\puede_ver_negocio($usuario, $negocioId)) {
            \responder_json(['error' => 'Negocio no válido'], 403);
        }

        \responder_json(['productos' => Producto::consultarCatalogo($q, $departamento, 30)]);
    }
}
