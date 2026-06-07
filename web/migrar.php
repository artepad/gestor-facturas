<?php
/**
 * Migraciones de la base de datos del servidor (cambios de esquema sobre
 * bases ya existentes). Es idempotente: solo aplica lo que falta.
 *
 *   https://admin.minimark.cl/migrar.php   (requiere admin logueado)
 *
 * Puedes dejarlo: solo lo corre un admin autenticado y no rompe nada si
 * ya está todo migrado.
 */

require __DIR__ . '/lib/auth.php';

$usuario = exigir_login();
if (($usuario['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Solo un administrador puede ejecutar migraciones.');
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = obtener_pdo();

/** Devuelve true si la tabla ya tiene la columna. */
function tiene_columna(PDO $pdo, string $tabla, string $col): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$tabla, $col]);
    return (int)$st->fetchColumn() > 0;
}

function agregar_columna(PDO $pdo, string $tabla, string $col, string $def): void
{
    if (tiene_columna($pdo, $tabla, $col)) {
        echo "  - $tabla.$col ya existe\n";
        return;
    }
    $pdo->exec("ALTER TABLE $tabla ADD COLUMN $col $def");
    echo "  + $tabla.$col agregada\n";
}

echo "== Migraciones ==\n\n";
echo "[Perfil de negocios]\n";
agregar_columna($pdo, 'negocios', 'rut',       "VARCHAR(20) NULL AFTER nombre");
agregar_columna($pdo, 'negocios', 'telefono',  "VARCHAR(40) NULL AFTER rut");
agregar_columna($pdo, 'negocios', 'direccion', "VARCHAR(255) NULL AFTER telefono");
agregar_columna($pdo, 'negocios', 'correo',    "VARCHAR(150) NULL AFTER direccion");
agregar_columna($pdo, 'negocios', 'activo',    "TINYINT(1) NOT NULL DEFAULT 1 AFTER correo");

echo "\nListo. Migraciones aplicadas.\n";
