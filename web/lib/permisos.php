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
// Módulos: 'ventas', 'facturas', 'fiados', 'ingresos', 'gastos', 'productos', 'herramientas', 'negocios', 'usuarios'.
//
// 'ventas' ya se habilitó para vendedoras (2026-11-08): el POS quedó probado
// y en producción. Fue también el momento de mudar Frutas y Verduras adentro
// de la pantalla de Venta (el módulo 'precios' de antes se retiró — esa
// pantalla vivía sola en el menú, ahora es un botón dentro de Ventas, y por
// eso ya no necesita su propio permiso: lo gatilla 'ventas'. Ver
// web/CLAUDE.md).
// 'productos' = crear/editar el catálogo compartido. Lo tienen admin y
// vendedoras por decisión del usuario: es más ágil y todo cambio queda
// auditado. Para quitárselo a las vendedoras basta con borrar 'productos' de
// su fila.
// 'gastos_registrar' (2026-11-14) = versión limitada de 'gastos' para
// vendedoras: pueden REGISTRAR un gasto nuevo (ej. pagarle en efectivo a un
// proveedor sin factura, como el de los huevos), pero no ven el listado ni
// pueden editar o eliminar gastos existentes — eso sigue siendo solo de
// 'gastos' (admin). GastosController::formulario() es la única acción que
// acepta ambos permisos; panel()/categorias() siguen exigiendo 'gastos' a
// secas. El Controlador decide adentro qué tanto puede hacer cada quien
// mirando puede($usuario, 'gastos') (acceso completo) vs. solo
// 'gastos_registrar' (crear nada más) — ver GastosController::formulario().
// 'representante' (2026-11-22, Etapa 2 de Proveedores) = acceso EXTERNO para
// un representante de un proveedor (ej. el vendedor de Coca-Cola): la unica
// persona ajena a la casa que entra al sistema. Su unico modulo es
// 'portal_proveedor' (catalogo de solo lectura de su propio proveedor, ver
// PortalProveedorController) — al no estar en ninguna otra fila, puede() le
// niega automaticamente cualquier otro modulo sin tener que tocar las demas
// filas. Queda deliberadamente FUERA de ROLES/roles_disponibles() (abajo):
// esa lista alimenta el selector de usuarios.php (personal de la casa), y un
// representante no se crea ahi sino desde Compras -> Proveedores
// (ComprasController), que es quien conoce a que proveedor pertenece.
const PERMISOS = [
    'admin'          => ['ventas', 'facturas', 'fiados', 'ingresos', 'gastos', 'productos', 'herramientas', 'negocios', 'usuarios'],
    'vendedor'       => ['ventas', 'facturas', 'fiados', 'productos', 'herramientas', 'gastos_registrar'],
    'representante'  => ['portal_proveedor'],
];

// Etiquetas legibles de los roles (orden = orden en los formularios).
// 'representante' no esta aca a proposito (ver nota de PERMISOS arriba).
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
