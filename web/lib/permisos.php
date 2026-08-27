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
// Módulos: 'ventas', 'facturas', 'fiados', 'ingresos', 'gastos', 'precios', 'herramientas', 'negocios', 'usuarios'.
//
// NOTA POS (Etapa 1): 'ventas' está SOLO en admin a propósito. El módulo se
// construye y prueba sin que las vendedoras vean nada. Para habilitarlo cuando
// esté listo, basta agregar 'ventas' a la fila 'vendedor'; para volver a
// ocultarlo, quitarlo. Ver web/POS_DISENO.md sección 12.1.
// 'productos' = crear/editar el catálogo compartido. Lo tienen admin y vendedoras
// por decisión del usuario: es más ágil y todo cambio queda auditado. Para
// quitárselo a las vendedoras basta con borrar 'productos' de su fila.
const PERMISOS = [
    'admin'    => ['ventas', 'facturas', 'fiados', 'ingresos', 'gastos', 'precios', 'productos', 'herramientas', 'negocios', 'usuarios'],
    'vendedor' => ['facturas', 'fiados', 'precios', 'productos', 'herramientas'],
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
