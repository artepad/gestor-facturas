<?php
/**
 * Roles y permisos de la plataforma (RBAC simple, definido en código).
 *
 * Para AGREGAR UN ROL nuevo: añade una fila a PERMISOS y una etiqueta en
 * roles_disponibles(). Para AGREGAR UN MÓDULO nuevo: añade su clave a los roles
 * que correspondan, llama exigir_permiso('modulo') en su página y muestra su
 * ítem en el menú (lib/ui.php). Todo el control vive aquí, en un solo lugar.
 */

// Matriz rol -> módulos a los que tiene acceso.
// Módulos: 'facturas', 'fiados', 'ingresos', 'herramientas', 'negocios', 'usuarios'.
const PERMISOS = [
    'admin'    => ['facturas', 'fiados', 'ingresos', 'herramientas', 'negocios', 'usuarios'],
    'vendedor' => ['facturas', 'fiados', 'herramientas'],
];

// Etiquetas legibles de los roles (orden = orden en los formularios).
const ROLES = [
    'admin'    => 'Administrador',
    'vendedor' => 'Vendedor',
];

/** ¿El usuario (por su rol) tiene acceso al módulo dado? */
function puede(array $usuario, string $modulo): bool
{
    $rol = $usuario['rol'] ?? '';
    return in_array($modulo, PERMISOS[$rol] ?? [], true);
}

/**
 * Exige sesión Y permiso sobre un módulo. Si no lo tiene, manda al inicio.
 * Devuelve el usuario si todo está bien.
 */
function exigir_permiso(string $modulo): array
{
    $u = exigir_login();
    if (!puede($u, $modulo)) {
        header('Location: home.php');
        exit;
    }
    return $u;
}

/** Roles que se pueden asignar en el formulario de usuarios. */
function roles_disponibles(): array
{
    return ROLES;
}

/** Etiqueta legible de un rol (o el propio código si es desconocido). */
function nombre_rol(string $rol): string
{
    return ROLES[$rol] ?? $rol;
}
