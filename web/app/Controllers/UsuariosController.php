<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Usuario;

/**
 * Gestión de usuarios del dashboard. Solo admin.
 *   usuarios.php            -> listado
 *   usuarios.php?editar=N   -> editar (N=0 crea)
 * Lista, crea, edita (clave opcional), asigna negocios y elimina usuarios.
 */
final class UsuariosController extends BaseController
{
    public function index(array $parametros = []): void
    {
        $usuario = $this->exigirPermiso('usuarios');
        require_once dirname(__DIR__, 2) . '/lib/permisos.php'; // roles_disponibles(), nombre_rol()
        require_once dirname(__DIR__, 2) . '/lib/pos.php';      // huella_pin() del cerrojo de la caja

        $error = '';
        $exito = '';

        if (Request::metodo() === 'POST') {
            $this->verificarCsrf('usuarios.php');
            $accion = Request::post('accion');

            if ($accion === 'guardar') {
                $uid = Request::entero('id');
                $email = Request::post('email');
                $nombre = Request::post('nombre');
                $clave = (string)($_POST['clave'] ?? ''); // nunca se trimea una clave
                $rolPost = Request::post('rol');
                $rol = array_key_exists($rolPost, \roles_disponibles()) ? $rolPost : 'vendedor';
                $negociosSel = array_map('intval', $_POST['negocios'] ?? []);
                $colorPost = Request::post('color');
                $color = preg_match('/^#[0-9a-fA-F]{6}$/', $colorPost) ? strtolower($colorPost) : Usuario::colorSugerido();

                // Salvaguarda: no dejar el sistema sin ningún administrador
                $degradaUltimoAdmin = false;
                if ($uid && $rol !== 'admin' && Usuario::rolDe($uid) === 'admin') {
                    $degradaUltimoAdmin = Usuario::contarAdmins() <= 1;
                }

                if ($email === '') {
                    $error = 'El correo es obligatorio.';
                } elseif ($uid && Usuario::rolDe($uid) === 'representante') {
                    // Defensa en profundidad (ver nota de Usuario::todosConNegocios()):
                    // aunque el formulario nunca ofrece este id, nada impide un POST
                    // armado a mano con el id de un representante — se corta acá,
                    // no solo ocultando el caso en la vista.
                    $error = 'Esta cuenta es de un representante de proveedor: edítala desde Compras → Proveedores.';
                } elseif (!$uid && strlen($clave) < 6) {
                    $error = 'La contraseña debe tener al menos 6 caracteres.';
                } elseif ($degradaUltimoAdmin) {
                    $error = 'Debe quedar al menos un administrador. Nombra otro admin antes de cambiar este rol.';
                } else {
                    try {
                        if ($uid) {
                            if ($clave !== '') {
                                Usuario::actualizarConClave($uid, $email, $nombre, $rol, password_hash($clave, PASSWORD_DEFAULT), $color);
                            } else {
                                Usuario::actualizar($uid, $email, $nombre, $rol, $color);
                            }
                        } else {
                            $uid = Usuario::crear($email, $nombre, $rol, password_hash($clave, PASSWORD_DEFAULT), $color);
                        }
                        // PIN de la caja: cerrojo local del terminal, no una contraseña.
                        // Vacío = no se toca; "0" borra el PIN existente.
                        $pin = preg_replace('/\D/', '', $_POST['pin'] ?? '');
                        if ($pin !== '') {
                            Usuario::actualizarPin($uid, \huella_pin($pin, $uid));
                        }
                        // Reasignar negocios (los roles no-admin trabajan solo sus negocios)
                        Usuario::reasignarNegocios($uid, $rol !== 'admin' ? $negociosSel : []);
                        $exito = 'Usuario guardado.';
                    } catch (\Throwable $e) {
                        $error = str_contains($e->getMessage(), 'Duplicate')
                            ? 'Ya existe un usuario con ese correo.'
                            : 'Error al guardar.';
                    }
                }
            } elseif ($accion === 'eliminar') {
                $uid = Request::entero('id');
                $rolDel = Usuario::rolDe($uid);
                if ($uid === (int)$usuario['id']) {
                    $error = 'No puedes eliminar tu propio usuario.';
                } elseif ($rolDel === 'representante') {
                    // Las cuentas de representantes de proveedor no se tocan desde
                    // acá (ver nota de Usuario::todosConNegocios()): se eliminan
                    // desde Compras -> Proveedores, donde se valida a qué
                    // proveedor pertenecen antes de borrar.
                    $error = 'Esta cuenta es de un representante de proveedor: elimínala desde Compras → Proveedores.';
                } elseif ($rolDel === 'admin' && Usuario::contarAdmins() <= 1) {
                    $error = 'No puedes eliminar al único administrador.';
                } else {
                    Usuario::eliminar($uid);
                    $exito = 'Usuario eliminado.';
                }
            }
        }

        $usuarios = Usuario::todosConNegocios();
        $negociosTodos = Usuario::negociosTodos();

        $editar = null;
        if (Request::get('editar') !== '') {
            $editar = Usuario::porId(Request::entero('editar'));
            // Blindaje: ni por URL a mano se puede traer acá una cuenta de
            // representante (ver nota de Usuario::todosConNegocios()) — el
            // selector de rol de este formulario ni siquiera la contempla.
            if ($editar && $editar['rol'] === 'representante') {
                $editar = null;
            }
            if ($editar) {
                $editar['negocios_ids'] = Usuario::negociosIdsDe((int)$editar['id']);
            }
        }
        $modoFormulario = ($editar !== null || Request::get('editar') !== '');
        $colorSugerido = ($editar === null) ? Usuario::colorSugerido() : '';

        View::render('usuarios/index', compact(
            'usuario', 'error', 'exito', 'usuarios', 'negociosTodos', 'editar', 'modoFormulario', 'colorSugerido'
        ));
    }
}
