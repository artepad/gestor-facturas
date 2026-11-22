<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Models\Compra;
use App\Models\Usuario;

/**
 * Portal del representante de un proveedor (Etapa 2 de Proveedores/Reposición,
 * ver web/CLAUDE.md). Única cuenta EXTERNA del sistema (rol='representante',
 * lib/permisos.php): catálogo de solo lectura de su propio proveedor, sin
 * precios ni ningún otro dato de la casa. No comparte layout con el
 * dashboard (lib/ui.php::cabecera_dashboard()) — es una pantalla propia.
 *   portal_proveedor.php -> index()
 */
final class PortalProveedorController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('portal_proveedor');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles()

        $datosUsuario = Usuario::porId((int)$usuario['id']);
        $proveedorId = $datosUsuario['proveedor_id'] ?? null;
        $proveedor = $proveedorId ? Compra::proveedorPorId((int)$proveedorId) : null;

        if (!$proveedor) {
            // No debería pasar nunca (toda cuenta 'representante' se crea con
            // proveedor asignado — ver ComprasController), pero si pasa, mejor
            // un aviso claro que una pantalla rota.
            View::render('portal_proveedor/sin_proveedor', ['usuario' => $usuario]);
            return;
        }

        $negocios = \negocios_visibles($usuario);
        $productos = Compra::productosDe((int)$proveedorId);

        View::render('portal_proveedor/index', compact('usuario', 'proveedor', 'negocios', 'productos'));
    }
}
