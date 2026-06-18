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

echo "\n[Roles y permisos]\n";
// rol como VARCHAR (permite nuevos roles sin ALTER) y migra 'sucursal' -> 'vendedor'
$tipoRol = $pdo->query(
    "SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'rol'"
)->fetchColumn();
if (strtolower((string)$tipoRol) !== 'varchar') {
    $pdo->exec("ALTER TABLE usuarios MODIFY rol VARCHAR(20) NOT NULL DEFAULT 'vendedor'");
    echo "  + usuarios.rol convertido a VARCHAR(20)\n";
} else {
    echo "  - usuarios.rol ya es VARCHAR\n";
}
$conv = $pdo->exec("UPDATE usuarios SET rol='vendedor' WHERE rol='sucursal'");
echo "  ~ usuarios 'sucursal' -> 'vendedor': $conv\n";

echo "\n[Modulo Ingresos (cortes de Eleventa)]\n";
// Crea las tablas del modulo Ingresos si faltan (idempotente). Mismas
// definiciones que lib/esquema.sql.
$tablasIngresos = [
    'correos_corte' => "CREATE TABLE IF NOT EXISTS correos_corte (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NULL,
        message_id VARCHAR(255) NULL UNIQUE,
        remitente VARCHAR(255) NULL, destinatario VARCHAR(255) NULL, asunto VARCHAR(255) NULL,
        recibido_en DATETIME NULL,
        cuerpo MEDIUMTEXT,
        origen VARCHAR(10) NOT NULL DEFAULT 'imap',
        estado VARCHAR(15) NOT NULL DEFAULT 'pendiente',
        error TEXT NULL, procesado_en DATETIME NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE SET NULL,
        INDEX idx_correo_estado (estado)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cajeros' => "CREATE TABLE IF NOT EXISTS cajeros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NOT NULL,
        nombre VARCHAR(150) NOT NULL,
        usuario_id INT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cajero (negocio_id, nombre),
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cortes' => "CREATE TABLE IF NOT EXISTS cortes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NOT NULL,
        correo_id INT NULL,
        cajero_id INT NULL,
        caja VARCHAR(80) NULL,
        abierto_en DATETIME NULL,
        cerrado_en DATETIME NOT NULL,
        ventas_totales DECIMAL(14,2) NULL,
        ganancia DECIMAL(14,2) NULL,
        numero_ventas INT NULL,
        fondo_caja DECIMAL(14,2) NULL,
        ventas_efectivo DECIMAL(14,2) NULL,
        abonos_efectivo DECIMAL(14,2) NULL,
        entradas_caja DECIMAL(14,2) NULL,
        salidas_caja DECIMAL(14,2) NULL,
        efectivo_esperado DECIMAL(14,2) NULL,
        ventas_tarjeta DECIMAL(14,2) NULL,
        ventas_credito DECIMAL(14,2) NULL,
        ventas_vales DECIMAL(14,2) NULL,
        ventas_transferencia DECIMAL(14,2) NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_corte (negocio_id, cerrado_en, cajero_id),
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (correo_id)  REFERENCES correos_corte(id) ON DELETE SET NULL,
        FOREIGN KEY (cajero_id)  REFERENCES cajeros(id) ON DELETE SET NULL,
        INDEX idx_corte_negocio_fecha (negocio_id, cerrado_en)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'corte_movimientos' => "CREATE TABLE IF NOT EXISTS corte_movimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        corte_id INT NOT NULL,
        tipo VARCHAR(10) NOT NULL,
        hora VARCHAR(10) NULL,
        descripcion VARCHAR(255) NULL,
        monto DECIMAL(14,2) NOT NULL,
        FOREIGN KEY (corte_id) REFERENCES cortes(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'corte_departamentos' => "CREATE TABLE IF NOT EXISTS corte_departamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        corte_id INT NOT NULL,
        departamento VARCHAR(150) NOT NULL,
        monto DECIMAL(14,2) NOT NULL,
        FOREIGN KEY (corte_id) REFERENCES cortes(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tablasIngresos as $nombre => $ddl) {
    $pdo->exec($ddl);
    echo "  ~ tabla $nombre lista\n";
}

echo "\n[Base de Datos de Productos (catalogo Eleventa)]\n";
// Crea las tablas del catalogo de productos si faltan (idempotente). Mismas
// definiciones que lib/esquema.sql.
$tablasProductos = [
    'productos' => "CREATE TABLE IF NOT EXISTS productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NOT NULL,
        codigo VARCHAR(40) NOT NULL,
        nombre VARCHAR(255) NOT NULL,
        precio_costo DECIMAL(14,2) NULL,
        precio_venta DECIMAL(14,2) NULL,
        precio_mayoreo DECIMAL(14,2) NULL,
        departamento VARCHAR(150) NULL,
        tipo_venta VARCHAR(30) NULL,
        carga_id INT NULL,
        UNIQUE KEY uq_producto (negocio_id, codigo),
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        INDEX idx_producto_negocio (negocio_id),
        INDEX idx_producto_nombre (negocio_id, nombre)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'producto_cargas' => "CREATE TABLE IF NOT EXISTS producto_cargas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NOT NULL,
        archivo_nombre VARCHAR(255) NULL,
        total_productos INT NOT NULL DEFAULT 0,
        total_omitidos INT NOT NULL DEFAULT 0,
        cargado_por INT NULL,
        cargado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (cargado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_carga_negocio (negocio_id, cargado_en)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tablasProductos as $nombre => $ddl) {
    $pdo->exec($ddl);
    echo "  ~ tabla $nombre lista\n";
}

echo "\n[Modulo Gastos]\n";
// Crea las tablas del modulo Gastos si faltan (idempotente). Mismas
// definiciones que lib/esquema.sql. Orden importa por las FKs.
$tablasGastos = [
    'categorias_gasto' => "CREATE TABLE IF NOT EXISTS categorias_gasto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(80) NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        orden INT NOT NULL DEFAULT 0,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_categoria_gasto (nombre)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'gastos_fijos' => "CREATE TABLE IF NOT EXISTS gastos_fijos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NOT NULL,
        categoria_id INT NOT NULL,
        descripcion VARCHAR(255) NULL,
        monto_estimado DECIMAL(14,2) NOT NULL,
        dia_mes TINYINT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (categoria_id) REFERENCES categorias_gasto(id) ON DELETE RESTRICT,
        INDEX idx_gfijo_negocio (negocio_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'gastos' => "CREATE TABLE IF NOT EXISTS gastos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NOT NULL,
        categoria_id INT NOT NULL,
        fecha DATE NOT NULL,
        monto DECIMAL(14,2) NOT NULL,
        descripcion VARCHAR(255) NULL,
        gasto_fijo_id INT NULL,
        creado_por INT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (categoria_id) REFERENCES categorias_gasto(id) ON DELETE RESTRICT,
        FOREIGN KEY (gasto_fijo_id) REFERENCES gastos_fijos(id) ON DELETE SET NULL,
        FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_gasto_negocio_fecha (negocio_id, fecha)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tablasGastos as $nombre => $ddl) {
    $pdo->exec($ddl);
    echo "  ~ tabla $nombre lista\n";
}
$semilla = $pdo->exec(
    "INSERT IGNORE INTO categorias_gasto (nombre, orden) VALUES
       ('Agua', 10), ('Luz', 20), ('Gas', 30), ('Sueldos', 40),
       ('Arriendo', 50), ('Internet/Teléfono', 60), ('Mantención', 70), ('Otros', 999)"
);
echo "  ~ categorias base sembradas (nuevas: $semilla)\n";

echo "\n[Modulo Frutas y Verduras]\n";
// Lista de precios compartida (sin negocio_id). Idempotente.
$pdo->exec("CREATE TABLE IF NOT EXISTS precios_fv (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    precio DECIMAL(14,2) NOT NULL,
    unidad VARCHAR(20) NOT NULL DEFAULT 'kilo',
    observacion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    actualizado_por INT NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_precio_nombre (nombre)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "  ~ tabla precios_fv lista\n";

echo "\nListo. Migraciones aplicadas.\n";
