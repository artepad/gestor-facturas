<?php
/**
 * Autenticacion por sesion + control de acceso por negocio.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permisos.php';   // roles, matriz de permisos y helpers

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // En HTTPS (produccion) la cookie va marcada Secure para que nunca viaje
        // por HTTP. En local (XAMPP por http) queda en false para no romper el login.
        $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
        // Sesion larga (30 dias): la caja de un almacen no puede pedir
        // contrasena cada manana, y menos si ese dia no hay internet. El
        // cerrojo de la caja es el PIN (ver POS_DISENO.md 3D), no el cierre
        // de sesion.
        $dias = 30 * 24 * 60 * 60;
        ini_set('session.gc_maxlifetime', (string)$dias);
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure'   => $https,
            'cookie_lifetime' => $dias,
            'gc_maxlifetime'  => $dias,
        ]);
    }
}

// --- Bloqueo de intentos de login (Fase 5, hallazgo H1 de la auditoria) ---
// Sin esto, nada impedia probar contraseñas sin limite. Se cuentan los
// fallidos recientes por correo O por IP, asi que cambiar de correo o de
// conexion no sirve para saltarse el bloqueo.
const LOGIN_MAX_INTENTOS    = 5;
const LOGIN_VENTANA_MINUTOS = 15;

/** Hash de la IP (nunca se guarda en claro) con la clave de instalacion como sal. */
function ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $sal = cargar_config()['setup_key'] ?? 'minimark';   // ya es un secreto del proyecto, se reusa
    return hash('sha256', $ip . '|' . $sal);
}

/** Registra cada intento (exitoso o no). Nunca debe tumbar el login si falla. */
function registrar_intento_login(string $email, bool $exitoso): void
{
    try {
        obtener_pdo()->prepare(
            "INSERT INTO intentos_login (email, ip_hash, exitoso) VALUES (?, ?, ?)"
        )->execute([mb_substr(trim($email), 0, 150), ip_hash(), $exitoso ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('No se pudo registrar intento de login: ' . $e->getMessage());
    }
}

/**
 * Si hay demasiados intentos fallidos recientes (por correo o por IP),
 * devuelve el mensaje para mostrar. Si no, null (puede intentar login()).
 * Se llama ANTES de login(), para no gastar un password_verify() de mas.
 *
 * Si la tabla intentos_login todavia no existe (se subio este codigo antes
 * de correr migrar.php en el servidor), NUNCA debe tumbar el login: se
 * trata como "no bloqueado" — se pierde el freno de fuerza bruta por un
 * rato, pero el negocio sigue pudiendo entrar a vender.
 */
function motivo_bloqueo_login(string $email): ?string
{
    try {
        $st = obtener_pdo()->prepare(
            "SELECT COUNT(*) FROM intentos_login
             WHERE exitoso = 0 AND creado_en > (NOW() - INTERVAL ? MINUTE)
               AND (email = ? OR ip_hash = ?)"
        );
        $st->execute([LOGIN_VENTANA_MINUTOS, mb_substr(trim($email), 0, 150), ip_hash()]);
        if ((int)$st->fetchColumn() >= LOGIN_MAX_INTENTOS) {
            return 'Demasiados intentos. Espera ' . LOGIN_VENTANA_MINUTOS . ' minutos e inténtalo de nuevo.';
        }
    } catch (Throwable $e) {
        error_log('motivo_bloqueo_login: ' . $e->getMessage());
    }
    return null;
}

/** Intenta autenticar. Devuelve true si el login fue correcto. */
function login(string $email, string $clave): bool
{
    iniciar_sesion();
    $pdo = obtener_pdo();
    $st = $pdo->prepare("SELECT id, password_hash, nombre, rol FROM usuarios WHERE email = ?");
    $st->execute([trim($email)]);
    $u = $st->fetch();
    $ok = $u && password_verify($clave, $u['password_hash']);
    registrar_intento_login($email, $ok);
    if (!$ok) {
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
