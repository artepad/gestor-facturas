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

/** Devuelve true si la tabla existe en la base actual. */
function tiene_tabla(PDO $pdo, string $tabla): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $st->execute([$tabla]);
    return (int)$st->fetchColumn() > 0;
}

/** Nombre de la clave foránea de una columna, o null si no tiene. */
function nombre_fk(PDO $pdo, string $tabla, string $col): ?string
{
    $st = $pdo->prepare(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
           AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1"
    );
    $st->execute([$tabla, $col]);
    return $st->fetchColumn() ?: null;
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

echo "\n[Auditoria]\n";
// Rastro de acciones sensibles (idempotente). Igual que lib/esquema.sql.
$pdo->exec("CREATE TABLE IF NOT EXISTS auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    usuario_nombre VARCHAR(120) NULL,
    rol VARCHAR(20) NULL,
    modulo VARCHAR(30) NOT NULL,
    accion VARCHAR(40) NOT NULL,
    cliente_id INT NULL,
    descripcion VARCHAR(255) NULL,
    detalle TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_aud_modulo (modulo, creado_en),
    INDEX idx_aud_cliente (cliente_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "  ~ tabla auditoria lista\n";

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

echo "\n[Modulo Ventas / POS]\n";
// Etapa 1 del POS (ver web/POS_DISENO.md). Solo agrega: no toca datos ni
// tablas existentes. Las columnas de stock quedan en 0 y sin usar hasta que
// se active el control de inventario (Etapa 4).
agregar_columna($pdo, 'negocios',  'control_stock', "TINYINT(1) NOT NULL DEFAULT 0");
agregar_columna($pdo, 'productos', 'stock',         "DECIMAL(14,3) NOT NULL DEFAULT 0");
agregar_columna($pdo, 'productos', 'stock_minimo',  "DECIMAL(14,3) NULL");
agregar_columna($pdo, 'productos', 'activo',        "TINYINT(1) NOT NULL DEFAULT 1");
agregar_columna($pdo, 'productos', 'afecto_iva',    "TINYINT(1) NOT NULL DEFAULT 1");
// El corte que genera el POS al cerrar caja aporta dos datos que el de
// Eleventa no trae (quedan NULL en los cortes que llegan por correo).
agregar_columna($pdo, 'cortes', 'recaudado_efectivo', "DECIMAL(14,2) NULL");
agregar_columna($pdo, 'cortes', 'diferencia',         "DECIMAL(14,2) NULL");

// Tablas del POS. El orden importa por las claves foraneas:
// caja_sesiones -> ventas -> venta_items/venta_pagos/ventas_espera,
// convenios -> servicio_operaciones.
$tablasPos = [
    'caja_sesiones' => "CREATE TABLE IF NOT EXISTS caja_sesiones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL UNIQUE,
        negocio_id INT NOT NULL,
        caja VARCHAR(80) NULL,
        usuario_apertura INT NULL,
        fondo_inicial DECIMAL(14,2) NOT NULL DEFAULT 0,
        abierto_en DATETIME NOT NULL,
        usuario_cierre INT NULL,
        cerrado_en DATETIME NULL,
        efectivo_contado DECIMAL(14,2) NULL,
        efectivo_esperado DECIMAL(14,2) NULL,
        diferencia DECIMAL(14,2) NULL,
        estado VARCHAR(8) NOT NULL DEFAULT 'abierta',
        corte_id INT NULL,
        nota VARCHAR(255) NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_apertura) REFERENCES usuarios(id) ON DELETE SET NULL,
        FOREIGN KEY (usuario_cierre) REFERENCES usuarios(id) ON DELETE SET NULL,
        FOREIGN KEY (corte_id) REFERENCES cortes(id) ON DELETE SET NULL,
        INDEX idx_sesion_negocio (negocio_id, estado)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'caja_movimientos' => "CREATE TABLE IF NOT EXISTS caja_movimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        caja_sesion_id INT NOT NULL,
        tipo VARCHAR(8) NOT NULL,
        monto DECIMAL(14,2) NOT NULL,
        motivo VARCHAR(255) NULL,
        usuario_id INT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_cajamov_sesion (caja_sesion_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'ventas' => "CREATE TABLE IF NOT EXISTS ventas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL UNIQUE,
        negocio_id INT NOT NULL,
        caja_sesion_id INT NULL,
        usuario_id INT NULL,
        cliente_id INT NULL,
        folio INT NULL,
        fecha DATETIME NOT NULL,
        subtotal DECIMAL(14,2) NOT NULL,
        descuento_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL,
        costo_total DECIMAL(14,2) NULL,
        pago_con DECIMAL(14,2) NULL,
        vuelto DECIMAL(14,2) NULL,
        estado VARCHAR(12) NOT NULL DEFAULT 'completada',
        anulada_en DATETIME NULL,
        anulada_por INT NULL,
        motivo_anulacion VARCHAR(255) NULL,
        origen VARCHAR(8) NOT NULL DEFAULT 'online',
        nota VARCHAR(255) NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE SET NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        FOREIGN KEY (anulada_por) REFERENCES usuarios(id) ON DELETE SET NULL,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
        UNIQUE KEY uq_venta_folio (negocio_id, folio),
        INDEX idx_venta_negocio_fecha (negocio_id, fecha),
        INDEX idx_venta_sesion (caja_sesion_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'venta_items' => "CREATE TABLE IF NOT EXISTS venta_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venta_id INT NOT NULL,
        producto_id INT NULL,
        codigo VARCHAR(40) NULL,
        nombre VARCHAR(255) NOT NULL,
        departamento VARCHAR(150) NULL,
        cantidad DECIMAL(14,3) NOT NULL,
        precio_unitario DECIMAL(14,2) NOT NULL,
        descuento DECIMAL(14,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL,
        costo_unitario DECIMAL(14,2) NULL,
        afecto_iva TINYINT(1) NOT NULL DEFAULT 1,
        FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
        INDEX idx_vitem_venta (venta_id),
        INDEX idx_vitem_producto (producto_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'venta_pagos' => "CREATE TABLE IF NOT EXISTS venta_pagos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venta_id INT NOT NULL,
        medio VARCHAR(15) NOT NULL,
        monto DECIMAL(14,2) NOT NULL,
        referencia VARCHAR(120) NULL,
        FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
        INDEX idx_vpago_venta (venta_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'ventas_espera' => "CREATE TABLE IF NOT EXISTS ventas_espera (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL UNIQUE,
        negocio_id INT NOT NULL,
        caja_sesion_id INT NULL,
        usuario_id INT NULL,
        numero INT NULL,
        etiqueta VARCHAR(80) NULL,
        items TEXT NOT NULL,
        total_estimado DECIMAL(14,2) NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_espera_sesion (caja_sesion_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'convenios' => "CREATE TABLE IF NOT EXISTS convenios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        negocio_id INT NOT NULL,
        nombre VARCHAR(80) NOT NULL,
        modalidad VARCHAR(10) NOT NULL DEFAULT 'prepago',
        saldo_minimo DECIMAL(14,2) NULL,
        saldo_ajuste DECIMAL(14,2) NOT NULL DEFAULT 0,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        UNIQUE KEY uq_convenio (negocio_id, nombre)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'servicio_operaciones' => "CREATE TABLE IF NOT EXISTS servicio_operaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL UNIQUE,
        negocio_id INT NOT NULL,
        convenio_id INT NOT NULL,
        caja_sesion_id INT NULL,
        usuario_id INT NULL,
        tipo VARCHAR(14) NOT NULL,
        servicio VARCHAR(80) NULL,
        monto DECIMAL(14,2) NOT NULL,
        medio_pago VARCHAR(15) NULL,
        comision DECIMAL(14,2) NULL,
        origen_fondos VARCHAR(10) NULL,
        referencia VARCHAR(120) NULL,
        fecha DATETIME NOT NULL,
        nota VARCHAR(255) NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
        FOREIGN KEY (convenio_id) REFERENCES convenios(id) ON DELETE RESTRICT,
        FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE SET NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_servop_negocio_fecha (negocio_id, fecha),
        INDEX idx_servop_convenio (convenio_id, fecha),
        INDEX idx_servop_sesion (caja_sesion_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tablasPos as $nombre => $ddl) {
    $pdo->exec($ddl);
    echo "  ~ tabla $nombre lista\n";
}

echo "\n[Catalogo compartido entre negocios]\n";
// A DIFERENCIA DEL RESTO DE ESTE ARCHIVO, este bloque NO es solo aditivo:
// deduplica filas y quita la columna negocio_id de `productos`. Ver
// POS_DISENO.md 7.1. Antes de tocar nada verifica que los catalogos de los
// negocios sean identicos; si NO lo son, ABORTA el bloque sin cambiar nada y
// muestra las diferencias para que el usuario decida.
if (!tiene_columna($pdo, 'productos', 'negocio_id')) {
    echo "  - el catalogo ya esta unificado\n";
} else {
    $difs = $pdo->query(
        "SELECT codigo, COUNT(*) copias,
                COUNT(DISTINCT nombre) n, COUNT(DISTINCT COALESCE(precio_venta,-1)) pv,
                COUNT(DISTINCT COALESCE(precio_costo,-1)) pc,
                COUNT(DISTINCT COALESCE(precio_mayoreo,-1)) pm,
                COUNT(DISTINCT COALESCE(departamento,'~')) dep,
                COUNT(DISTINCT COALESCE(tipo_venta,'~')) tv
           FROM productos GROUP BY codigo
         HAVING n > 1 OR pv > 1 OR pc > 1 OR pm > 1 OR dep > 1 OR tv > 1"
    )->fetchAll();

    if ($difs) {
        echo "  !! ABORTADO: hay " . count($difs) . " producto(s) con datos DISTINTOS entre negocios.\n";
        echo "     No se cambio nada. Revisa y decide cual dato queda antes de unificar:\n";
        foreach (array_slice($difs, 0, 20) as $d) {
            echo "       codigo {$d['codigo']}\n";
        }
        if (count($difs) > 20) echo "       ... y " . (count($difs) - 20) . " mas\n";
    } else {
        // 1. Stock por negocio (el stock NUNCA se comparte: ver POS_DISENO.md 7.1).
        $pdo->exec("CREATE TABLE IF NOT EXISTS producto_stock (
            producto_id INT NOT NULL,
            negocio_id INT NOT NULL,
            stock DECIMAL(14,3) NOT NULL DEFAULT 0,
            stock_minimo DECIMAL(14,3) NULL,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (producto_id, negocio_id),
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
            FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "  ~ tabla producto_stock lista\n";

        // 2. Rescata el stock que hubiera antes de deduplicar, mapeandolo a la
        //    fila que va a sobrevivir (la de menor id por codigo).
        if (tiene_columna($pdo, 'productos', 'stock')) {
            $n = $pdo->exec(
                "INSERT IGNORE INTO producto_stock (producto_id, negocio_id, stock, stock_minimo)
                 SELECT (SELECT MIN(q.id) FROM productos q WHERE q.codigo = p.codigo),
                        p.negocio_id, p.stock, p.stock_minimo
                   FROM productos p WHERE p.stock <> 0 OR p.stock_minimo IS NOT NULL"
            );
            echo "  ~ stock preservado: $n fila(s)\n";
        }

        // 3. Deduplica: se queda con la fila de menor id de cada codigo.
        $antes = (int)$pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();
        $pdo->exec(
            "DELETE p FROM productos p
               JOIN (SELECT codigo, MIN(id) id FROM productos GROUP BY codigo) k
                 ON k.codigo = p.codigo AND p.id > k.id"
        );
        $despues = (int)$pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();
        echo "  ~ deduplicado: $antes -> $despues productos\n";

        // 4. Quita negocio_id y sus indices; el codigo pasa a ser unico global.
        if ($fk = nombre_fk($pdo, 'productos', 'negocio_id')) {
            $pdo->exec("ALTER TABLE productos DROP FOREIGN KEY `$fk`");
        }
        foreach (['uq_producto', 'idx_producto_negocio', 'idx_producto_nombre'] as $idx) {
            try { $pdo->exec("ALTER TABLE productos DROP INDEX `$idx`"); } catch (Throwable $e) {}
        }
        $pdo->exec("ALTER TABLE productos DROP COLUMN negocio_id");
        $pdo->exec("ALTER TABLE productos ADD UNIQUE KEY uq_producto_codigo (codigo)");
        $pdo->exec("ALTER TABLE productos ADD INDEX idx_producto_nombre (nombre)");
        echo "  + productos: catalogo unificado (codigo unico global)\n";

        // 5. El stock sale de productos: ahora vive en producto_stock.
        foreach (['stock', 'stock_minimo'] as $c) {
            if (tiene_columna($pdo, 'productos', $c)) {
                $pdo->exec("ALTER TABLE productos DROP COLUMN $c");
                echo "  - productos.$c movida a producto_stock\n";
            }
        }
    }
}
// La carga de Excel ya no reemplaza el catalogo: informa cuantos entraron
// nuevos y cuantos se actualizaron.
agregar_columna($pdo, 'producto_cargas', 'total_nuevos',       "INT NOT NULL DEFAULT 0");
agregar_columna($pdo, 'producto_cargas', 'total_actualizados', "INT NOT NULL DEFAULT 0");
// Quien creo y quien edito cada producto (se muestra al editarlo).
agregar_columna($pdo, 'productos', 'creado_por',      "INT NULL");
agregar_columna($pdo, 'productos', 'actualizado_por', "INT NULL");
agregar_columna($pdo, 'productos', 'actualizado_en',
    "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

echo "
[Venta fiado]
";
// Enlaza el fiado con la venta que lo genero. Asi anular una venta puede
// revertir su fiado sin adivinar cual era (Etapa 2C).
agregar_columna($pdo, 'fiados', 'venta_id', "INT NULL");
try {
    $pdo->exec("ALTER TABLE fiados ADD INDEX idx_fiado_venta (venta_id)");
    echo "  + indice idx_fiado_venta agregado
";
} catch (Throwable $e) { echo "  - indice idx_fiado_venta ya existe
"; }

echo "\n[Promociones]\n";
// Promociones automaticas que el POS aplica solo al vender (ej. "4 x $1.000").
// Ver POS_DISENO.md. Idempotente.
$pdo->exec("CREATE TABLE IF NOT EXISTS promociones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NULL,
    nombre VARCHAR(120) NOT NULL,
    tipo VARCHAR(12) NOT NULL,
    producto_id INT NULL,
    departamento VARCHAR(150) NULL,
    cantidad DECIMAL(14,3) NULL,
    valor DECIMAL(14,2) NOT NULL,
    desde DATE NULL,
    hasta DATE NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_promo_producto (producto_id, activo),
    INDEX idx_promo_depto (departamento, activo)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "  ~ tabla promociones lista\n";
// Que promocion se aplico a cada linea vendida (para reportes y para explicar
// por que una linea salio mas barata).
agregar_columna($pdo, 'venta_items', 'promocion_id', "INT NULL");

echo "
[PIN de la caja]
";
// PIN corto para desbloquear la caja en el propio equipo, util cuando el PC
// arranca sin internet. NO reemplaza la contrasena: es un cerrojo local para
// que otra persona no use la caja que quedo abierta. Ver POS_DISENO.md 3D.
agregar_columna($pdo, 'usuarios', 'pin_hash', "VARCHAR(64) NULL");

echo "\n[Inventario]\n";
// Libro mayor del stock: cada venta, compra, ajuste o merma deja una linea
// aca. producto_stock guarda el saldo rapido; esto es el historial auditable
// (el "kardex"). Solo se llena si el negocio tiene control_stock = 1.
$pdo->exec("CREATE TABLE IF NOT EXISTS inventario_movimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    negocio_id INT NOT NULL,
    producto_id INT NULL,
    tipo VARCHAR(12) NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    stock_resultante DECIMAL(14,3) NULL,
    referencia_tipo VARCHAR(12) NULL,
    referencia_id INT NULL,
    motivo VARCHAR(255) NULL,
    usuario_id INT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_invmov_producto (producto_id, negocio_id, creado_en),
    INDEX idx_invmov_negocio (negocio_id, creado_en),
    INDEX idx_invmov_ref (referencia_tipo, referencia_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "  ~ tabla inventario_movimientos lista\n";

echo "\n[Auditoria: referencia generica]\n";
// Hasta ahora la auditoria solo podia apuntar a un cliente. Con estas dos
// columnas puede referirse a cualquier cosa (producto, venta, caja...).
agregar_columna($pdo, 'auditoria', 'referencia_tipo', "VARCHAR(20) NULL");
agregar_columna($pdo, 'auditoria', 'referencia_id',   "INT NULL");
try {
    $pdo->exec("ALTER TABLE auditoria ADD INDEX idx_aud_ref (referencia_tipo, referencia_id)");
    echo "  + indice idx_aud_ref agregado\n";
} catch (Throwable $e) { echo "  - indice idx_aud_ref ya existe\n"; }

echo "\nListo. Migraciones aplicadas.\n";
