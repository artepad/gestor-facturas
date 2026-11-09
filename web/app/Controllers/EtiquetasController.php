<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Producto;

/**
 * Gestor de Etiquetas de precio: formulario de 14 etiquetas (con buscador
 * desde el catálogo) + hoja imprimible A4.
 *   herramienta_etiquetas.php -> formulario()
 *   etiquetas_imprimir.php    -> imprimir()
 *
 * imprimir() no persiste nada en la BD (solo arma una página para imprimir
 * o guardar como PDF), así que no lleva CSRF — igual que
 * precios_fv_imprimir.php / ofertas_imprimir.php.
 */
final class EtiquetasController extends BaseController
{
    private const FILAS = 14; // una hoja A4 = 14 etiquetas (2 columnas x 7 filas)

    public function formulario(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('herramientas');
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // negocios_visibles(), puede_ver_negocio()
        require_once dirname(__DIR__, 2) . '/lib/ui.php';   // icono()

        $negocios = \negocios_visibles($usuario);
        $negocioId = Request::entero('negocio');
        if ($negocioId && !\puede_ver_negocio($usuario, $negocioId)) $negocioId = 0;
        if (!$negocioId && $negocios) $negocioId = (int)$negocios[0]['id'];

        $totalP = Producto::contar();
        $ultima = Producto::ultimaCarga();
        $estado = Producto::estadoActualizacion($ultima['cargado_en'] ?? null);

        // Precarga de la primera fila al venir desde "Crear etiqueta" del catálogo.
        $preNombre = Request::get('nombre');
        $prePrecio = preg_replace('/[^\d]/', '', Request::get('precio'));

        View::render('etiquetas/formulario', compact(
            'usuario', 'negocios', 'negocioId', 'totalP', 'estado', 'preNombre', 'prePrecio'
        ) + ['FILAS' => self::FILAS]);
    }

    public function imprimir(array $parametros = []): void
    {
        $this->exigirPermiso('herramientas');

        $nombres = $_POST['nombre'] ?? [];
        $precios = $_POST['precio'] ?? [];
        $productos = [];
        foreach ($nombres as $i => $nom) {
            $nom = trim((string)$nom);
            $precio = (float)preg_replace('/[^\d]/', '', (string)($precios[$i] ?? ''));
            if ($nom !== '' && $precio > 0) {
                $productos[] = ['nombre' => $nom, 'precio' => $precio];
            }
        }

        View::render('etiquetas/imprimir', compact('productos'));
    }

    /**
     * Endpoint JSON del autocompletar (código de barras o nombre). Lo
     * consume el buscador de herramienta_etiquetas.php. Igual que el código
     * viejo: la falta de sesión/permiso redirige (vía exigirPermiso, como
     * cualquier pantalla), y solo el negocio inválido responde JSON 403.
     */
    public function buscarJson(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('herramientas');
        require_once dirname(__DIR__, 2) . '/lib/db.php';   // responder_json()
        require_once dirname(__DIR__, 2) . '/lib/auth.php'; // puede_ver_negocio()

        $negocioId = Request::entero('negocio_id');
        $q = Request::get('q');
        if (!$negocioId || !\puede_ver_negocio($usuario, $negocioId)) {
            \responder_json(['error' => 'Negocio no válido'], 403);
        }

        \responder_json(['productos' => Producto::buscar($q, 20)]);
    }
}
