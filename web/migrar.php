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

echo "\n[Modulo Fiados]\n";
// Crea las tablas del modulo Fiados si faltan (CREATE TABLE IF NOT EXISTS es
// idempotente: no toca las que ya existen).
$tablasFiados = [
    'clientes' => "CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NOT NULL,
        nombre VARCHAR(100) NOT NULL, apellido VARCHAR(100) NULL,
        telefono VARCHAR(40) NULL, direccion VARCHAR(255) NULL, correo VARCHAR(150) NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1, creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        INDEX idx_cliente_negocio (negocio_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'fiados' => "CREATE TABLE IF NOT EXISTS fiados (
        id INT AUTO_INCREMENT PRIMARY KEY, cliente_id INT NOT NULL,
        fecha DATE NOT NULL, monto DECIMAL(14,2) NOT NULL, descripcion VARCHAR(255) NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
        INDEX idx_fiado_cliente (cliente_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'abonos' => "CREATE TABLE IF NOT EXISTS abonos (
        id INT AUTO_INCREMENT PRIMARY KEY, cliente_id INT NOT NULL,
        fecha DATE NOT NULL, monto DECIMAL(14,2) NOT NULL, nota VARCHAR(255) NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
        INDEX idx_abono_cliente (cliente_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tablasFiados as $nombre => $ddl) {
    $pdo->exec($ddl);
    echo "  ~ tabla $nombre lista\n";
}

echo "\nListo. Migraciones aplicadas.\n";
