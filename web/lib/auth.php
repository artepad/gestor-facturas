<?php
/**
 * Autenticacion por sesion + control de acceso por negocio.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permisos.php';   // roles, matriz de permisos y helpers

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

/** Intenta autenticar. Devuelve true si el login fue correcto. */
function login(string $email, string $clave): bool
{
    iniciar_sesion();
    $pdo = obtener_pdo();
    $st = $pdo->prepare("SELECT id, password_hash, nombre, rol FROM usuarios WHERE email = ?");
    $st->execute([trim($email)]);
    $u = $st->fetch();
    if (!$u || !password_verify($clave, $u['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'     => (int)$u['id'],
        'nombre' => $u['nombre'],
        'rol'    => $u['rol'],
    ];
    return true;
}

function cerrar_sesion(): void
{
    iniciar_sesion();
    $_SESSION = [];
    session_destroy();
}

function usuario_actual(): ?array
{
    iniciar_sesion();
    return $_SESSION['usuario'] ?? null;
}

/** Redirige a login si no hay sesion. Devuelve el usuario si la hay. */
function exigir_login(): array
{
    $u = usuario_actual();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

/**
 * Exige sesion Y rol admin. Si no es admin, manda al inicio.
 * (Para módulos usa preferentemente exigir_permiso(); esto queda para chequeos
 * puntuales de "solo administrador".)
 */
function exigir_admin(): array
{
    $u = exigir_login();
    if (($u['rol'] ?? '') !== 'admin') {
        header('Location: home.php');
        exit;
    }
    return $u;
}

/**
 * IDs de negocios que el usuario puede ver.
 * admin = todos; cualquier otro rol = solo los asignados en usuario_negocio.
 */
function negocios_visibles(array $usuario): array
{
    $pdo = obtener_pdo();
    if ($usuario['rol'] === 'admin') {
        return $pdo->query("SELECT id, nombre FROM negocios ORDER BY nombre")
                   ->fetchAll();
    }
    $st = $pdo->prepare(
        "SELECT n.id, n.nombre FROM negocios n
         JOIN usuario_negocio un ON un.negocio_id = n.id
         WHERE un.usuario_id = ? ORDER BY n.nombre"
    );
    $st->execute([$usuario['id']]);
    return $st->fetchAll();
}

/** True si el usuario puede ver facturas del negocio dado. */
function puede_ver_negocio(array $usuario, int $negocioId): bool
{
    foreach (negocios_visibles($usuario) as $n) {
        if ((int)$n['id'] === $negocioId) {
            return true;
        }
    }
    return false;
}
