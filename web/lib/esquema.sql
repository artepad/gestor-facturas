-- Esquema de la base de datos del Sistema de Gestion de Facturas (servidor web).
-- Lo ejecuta setup.php. Todas las tablas en InnoDB + utf8mb4 (soporta tildes/ñ).

CREATE TABLE IF NOT EXISTS negocios (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  slug      VARCHAR(50)  NOT NULL UNIQUE,        -- identificador corto, ej. "minimark-centro"
  nombre    VARCHAR(150) NOT NULL,
  rut       VARCHAR(20)  NULL,                   -- perfil del negocio
  telefono  VARCHAR(40)  NULL,
  direccion VARCHAR(255) NULL,
  correo    VARCHAR(150) NULL,
  activo    TINYINT(1)   NOT NULL DEFAULT 1,     -- desactivar sin borrar
  -- POS: el control de inventario es opcional y por negocio. Nace APAGADO:
  -- se puede vender sin stock (ver web/POS_DISENO.md seccion 4.0).
  control_stock TINYINT(1) NOT NULL DEFAULT 0,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nombre        VARCHAR(150),
  rol           VARCHAR(20) NOT NULL DEFAULT 'vendedor',   -- ver lib/permisos.php
  pin_hash      VARCHAR(64) NULL,                          -- cerrojo local de la caja
  creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuario_negocio (
  usuario_id INT NOT NULL,
  negocio_id INT NOT NULL,
  PRIMARY KEY (usuario_id, negocio_id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maquinas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id  INT NOT NULL,
  nombre      VARCHAR(150),
  token_hash  CHAR(64) NOT NULL UNIQUE,          -- sha256 del token de la maquina
  ultima_sync TIMESTAMP NULL,
  creado_en   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS facturas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uuid_local     CHAR(36) NOT NULL UNIQUE,       -- mismo UUID que genera el PC (idempotencia)
  negocio_id     INT NOT NULL,
  proveedor      VARCHAR(255),
  razon_social   VARCHAR(255),
  rut_emisor     VARCHAR(20),
  numero_factura VARCHAR(50),
  fecha          DATE,
  total          DECIMAL(14,2),
  moneda         VARCHAR(10) DEFAULT 'CLP',
  confianza      DOUBLE,
  notas          TEXT,
  ruta_pdf       VARCHAR(255),
  creada_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actualizada_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  eliminada_en   TIMESTAMP NULL,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
  INDEX idx_negocio (negocio_id),
  INDEX idx_fecha (fecha),
  INDEX idx_rut (rut_emisor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS detalle_factura (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  factura_id      INT NOT NULL,
  descripcion     VARCHAR(255),
  cantidad        DOUBLE,
  precio_unitario DECIMAL(14,2),
  descuento       DECIMAL(14,2),
  monto           DECIMAL(14,2),
  afecto_iva      TINYINT(1) DEFAULT 1,
  precio_sugerido DECIMAL(14,2),
  FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_log (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  maquina_id   INT,
  accion       VARCHAR(20),
  factura_uuid CHAR(36),
  exito        TINYINT(1),
  error        TEXT,
  ts           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Modulo Fiados (web-nativo, no participa de la sincronizacion) =====

-- Clientes a quienes el negocio vende a credito. Uno por negocio.
CREATE TABLE IF NOT EXISTS clientes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id INT NOT NULL,
  nombre     VARCHAR(100) NOT NULL,
  apellido   VARCHAR(100) NULL,
  telefono   VARCHAR(40)  NULL,
  direccion  VARCHAR(255) NULL,
  correo     VARCHAR(150) NULL,
  activo     TINYINT(1)   NOT NULL DEFAULT 1,     -- desactivar sin borrar historial
  creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
  INDEX idx_cliente_negocio (negocio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fiados: cargos a credito que aumentan la deuda del cliente.
CREATE TABLE IF NOT EXISTS fiados (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id  INT NOT NULL,
  fecha       DATE NOT NULL,
  monto       DECIMAL(14,2) NOT NULL,
  descripcion VARCHAR(255) NULL,
  venta_id    INT NULL,                            -- si nacio de una venta del POS
  creado_en   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  INDEX idx_fiado_cliente (cliente_id),
  INDEX idx_fiado_venta (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Abonos: pagos que disminuyen la deuda del cliente (cuenta corriente).
CREATE TABLE IF NOT EXISTS abonos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  fecha      DATE NOT NULL,
  monto      DECIMAL(14,2) NOT NULL,
  nota       VARCHAR(255) NULL,
  creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  INDEX idx_abono_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Auditoria (rastro de acciones sensibles de los modulos) =====
-- Guarda snapshot del usuario (nombre + rol) para que el registro siga siendo
-- legible aunque el usuario se elimine. cliente_id es un entero suelto (sin FK):
-- si el cliente se borra, la fila de auditoria se conserva con su descripcion.
CREATE TABLE IF NOT EXISTS auditoria (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT NULL,
  usuario_nombre VARCHAR(120) NULL,
  rol            VARCHAR(20) NULL,
  modulo         VARCHAR(30) NOT NULL,
  accion         VARCHAR(40) NOT NULL,
  cliente_id     INT NULL,
  descripcion    VARCHAR(255) NULL,
  detalle        TEXT NULL,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_aud_modulo (modulo, creado_en),
  INDEX idx_aud_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Modulo Ingresos (cortes de caja que envia Eleventa por correo) =====

-- Correo crudo recibido (auditoria + permite reprocesar si cambia el parser).
CREATE TABLE IF NOT EXISTS correos_corte (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id   INT NULL,                          -- NULL si no se pudo identificar
  message_id   VARCHAR(255) NULL UNIQUE,          -- idempotencia (Message-ID o hash manual)
  remitente    VARCHAR(255) NULL,
  destinatario VARCHAR(255) NULL,                 -- corte-{slug}@... identifica el negocio
  asunto       VARCHAR(255) NULL,
  recibido_en  DATETIME NULL,
  cuerpo       MEDIUMTEXT,                        -- HTML/texto tal cual llego
  origen       VARCHAR(10) NOT NULL DEFAULT 'imap',      -- imap | manual
  estado       VARCHAR(15) NOT NULL DEFAULT 'pendiente', -- pendiente | procesado | error
  error        TEXT NULL,
  procesado_en DATETIME NULL,
  creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE SET NULL,
  INDEX idx_correo_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cajeros de Eleventa (se crean solos al aparecer en un corte). El vinculo
-- opcional a un usuario web permite reportes "por vendedor de la plataforma".
CREATE TABLE IF NOT EXISTS cajeros (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id INT NOT NULL,
  nombre     VARCHAR(150) NOT NULL,
  usuario_id INT NULL,
  creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cajero (negocio_id, nombre),
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un corte = un cierre de turno/caja de Eleventa.
CREATE TABLE IF NOT EXISTS cortes (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id           INT NOT NULL,
  correo_id            INT NULL,
  cajero_id            INT NULL,
  caja                 VARCHAR(80) NULL,          -- ej. "Caja Principal" (del asunto)
  abierto_en           DATETIME NULL,
  cerrado_en           DATETIME NOT NULL,
  ventas_totales       DECIMAL(14,2) NULL,
  ganancia             DECIMAL(14,2) NULL,
  numero_ventas        INT NULL,
  fondo_caja           DECIMAL(14,2) NULL,
  ventas_efectivo      DECIMAL(14,2) NULL,
  abonos_efectivo      DECIMAL(14,2) NULL,
  entradas_caja        DECIMAL(14,2) NULL,
  salidas_caja         DECIMAL(14,2) NULL,
  efectivo_esperado    DECIMAL(14,2) NULL,
  ventas_tarjeta       DECIMAL(14,2) NULL,
  ventas_credito       DECIMAL(14,2) NULL,
  ventas_vales         DECIMAL(14,2) NULL,
  ventas_transferencia DECIMAL(14,2) NULL,
  -- Columnas que aporta el POS propio (los cortes de Eleventa las dejan NULL).
  -- recaudado_efectivo: pagos de cuentas cobrados en efectivo. NO son venta,
  -- pero entran al cajon, asi que sin esto el corte no cuadraria consigo mismo
  -- (fondo + ventas_efectivo + recaudado + entradas - salidas = esperado).
  recaudado_efectivo   DECIMAL(14,2) NULL,
  diferencia           DECIMAL(14,2) NULL,   -- contado - esperado en el arqueo
  creado_en            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_corte (negocio_id, cerrado_en, cajero_id),  -- reprocesar no duplica
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
  FOREIGN KEY (correo_id)  REFERENCES correos_corte(id) ON DELETE SET NULL,
  FOREIGN KEY (cajero_id)  REFERENCES cajeros(id) ON DELETE SET NULL,
  INDEX idx_corte_negocio_fecha (negocio_id, cerrado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Movimientos de caja del corte (retiros, pagos a proveedores, entradas).
CREATE TABLE IF NOT EXISTS corte_movimientos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  corte_id    INT NOT NULL,
  tipo        VARCHAR(10) NOT NULL,               -- entrada | salida
  hora        VARCHAR(10) NULL,                   -- ej. "4:55pm" (Eleventa no da la fecha)
  descripcion VARCHAR(255) NULL,
  monto       DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (corte_id) REFERENCES cortes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ventas por departamento del corte (la seccion "Ventas por Departamento").
CREATE TABLE IF NOT EXISTS corte_departamentos (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  corte_id     INT NOT NULL,
  departamento VARCHAR(150) NOT NULL,
  monto        DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (corte_id) REFERENCES cortes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Base de Datos de Productos (catalogo cargado desde el Excel de Eleventa) =====

-- Catalogo de productos por negocio. Lo usa el Gestor de Etiquetas para buscar
-- por codigo de barra o por nombre. Se reemplaza completo en cada carga de Excel.
-- CATALOGO COMPARTIDO entre los negocios (ver web/POS_DISENO.md 7.1): agregar
-- un producto o cambiar un precio se refleja en los dos locales. Mismo criterio
-- que precios_fv y categorias_gasto. El STOCK no se comparte: vive en
-- producto_stock, una fila por producto y negocio.
CREATE TABLE IF NOT EXISTS productos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  codigo         VARCHAR(40)  NOT NULL,           -- codigo de barra / codigo Eleventa
  nombre         VARCHAR(255) NOT NULL,
  precio_costo   DECIMAL(14,2) NULL,
  precio_venta   DECIMAL(14,2) NULL,
  precio_mayoreo DECIMAL(14,2) NULL,
  departamento   VARCHAR(150) NULL,
  tipo_venta     VARCHAR(30)  NULL,
  carga_id       INT NULL,                        -- de que subida vino
  activo         TINYINT(1)   NOT NULL DEFAULT 1, -- ocultar de la venta sin borrar
  afecto_iva     TINYINT(1)   NOT NULL DEFAULT 1,
  creado_por      INT NULL,
  actualizado_por INT NULL,
  actualizado_en  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_producto_codigo (codigo),         -- 1 codigo en todo el sistema
  INDEX idx_producto_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existencias por local. "Que es y cuanto vale" es compartido; "cuantos tengo"
-- es de cada negocio. Se llena recien al activar el inventario (Etapa 4).
CREATE TABLE IF NOT EXISTS producto_stock (
  producto_id    INT NOT NULL,
  negocio_id     INT NOT NULL,
  stock          DECIMAL(14,3) NOT NULL DEFAULT 0,
  stock_minimo   DECIMAL(14,3) NULL,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (producto_id, negocio_id),
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  FOREIGN KEY (negocio_id)  REFERENCES negocios(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una carga = una subida de Excel. Da la "ultima actualizacion" y el historial.
CREATE TABLE IF NOT EXISTS producto_cargas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id      INT NOT NULL,
  archivo_nombre  VARCHAR(255) NULL,
  total_productos INT NOT NULL DEFAULT 0,
  total_omitidos  INT NOT NULL DEFAULT 0,          -- filas sin codigo/nombre descartadas
  total_nuevos       INT NOT NULL DEFAULT 0,       -- productos que no existian
  total_actualizados INT NOT NULL DEFAULT 0,       -- productos que ya estaban
  cargado_por     INT NULL,
  cargado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)  REFERENCES negocios(id)  ON DELETE CASCADE,
  FOREIGN KEY (cargado_por) REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_carga_negocio (negocio_id, cargado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Modulo Gastos (gastos operacionales del negocio: agua, luz, sueldos...) =====
-- Web-nativo (la web es la fuente de verdad). Solo lo ve el administrador.

-- Categorias editables de gasto (compartidas entre negocios; el dueno es uno).
CREATE TABLE IF NOT EXISTS categorias_gasto (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(80) NOT NULL,
  activo    TINYINT(1)  NOT NULL DEFAULT 1,           -- desactivar sin borrar
  orden     INT         NOT NULL DEFAULT 0,           -- orden de aparicion
  creado_en TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categoria_gasto (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Plantillas de gasto fijo mensual (sueldos, arriendo...). Se "generan" como
-- gastos reales una vez al mes con un clic (ver registrar_gastos_fijos).
CREATE TABLE IF NOT EXISTS gastos_fijos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id     INT NOT NULL,
  categoria_id   INT NOT NULL,
  descripcion    VARCHAR(255) NULL,
  monto_estimado DECIMAL(14,2) NOT NULL,
  dia_mes        TINYINT NULL,                        -- dia sugerido de pago (1-31)
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)   REFERENCES negocios(id)        ON DELETE CASCADE,
  FOREIGN KEY (categoria_id) REFERENCES categorias_gasto(id) ON DELETE RESTRICT,
  INDEX idx_gfijo_negocio (negocio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada gasto real registrado.
CREATE TABLE IF NOT EXISTS gastos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id    INT NOT NULL,
  categoria_id  INT NOT NULL,
  fecha         DATE NOT NULL,
  monto         DECIMAL(14,2) NOT NULL,
  descripcion   VARCHAR(255) NULL,
  gasto_fijo_id INT NULL,                             -- nacio de una plantilla fija
  creado_por    INT NULL,
  creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)    REFERENCES negocios(id)         ON DELETE CASCADE,
  FOREIGN KEY (categoria_id)  REFERENCES categorias_gasto(id) ON DELETE RESTRICT,
  FOREIGN KEY (gasto_fijo_id) REFERENCES gastos_fijos(id)     ON DELETE SET NULL,
  FOREIGN KEY (creado_por)    REFERENCES usuarios(id)         ON DELETE SET NULL,
  INDEX idx_gasto_negocio_fecha (negocio_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categorias base (idempotente: no duplica si ya existen).
INSERT IGNORE INTO categorias_gasto (nombre, orden) VALUES
  ('Agua', 10), ('Luz', 20), ('Gas', 30), ('Sueldos', 40),
  ('Arriendo', 50), ('Internet/Teléfono', 60), ('Mantención', 70), ('Otros', 999);

-- ===== Modulo Frutas y Verduras (lista de precios compartida) =====
-- Web-nativo. Una sola lista para ambos negocios (sin negocio_id): la compra de
-- frutas/verduras la hace una persona para todos. Editan admin y vendedores;
-- todos consultan. actualizado_en se refresca solo al editar (fecha vigente).
CREATE TABLE IF NOT EXISTS precios_fv (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(120) NOT NULL,
  precio          DECIMAL(14,2) NOT NULL,
  unidad          VARCHAR(20) NOT NULL DEFAULT 'kilo',   -- kilo, unidad, bandeja...
  observacion     VARCHAR(255) NULL,
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  actualizado_por INT NULL,
  actualizado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  creado_en       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_precio_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Modulo Ventas / POS (reemplazo de Eleventa) =====
-- Diseno completo en web/POS_DISENO.md. Web-nativo: la web es la fuente de
-- verdad. Las tablas se crean desde la Etapa 1 aunque algunas columnas (stock)
-- recien se usen cuando se active el control de inventario (Etapa 4).

-- Turno de caja: apertura con fondo, cierre con arqueo y diferencia.
CREATE TABLE IF NOT EXISTS caja_sesiones (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36) NOT NULL UNIQUE,
  negocio_id        INT NOT NULL,
  caja              VARCHAR(80) NULL,                 -- nombre del terminal ("Caja 1")
  usuario_apertura  INT NULL,
  fondo_inicial     DECIMAL(14,2) NOT NULL DEFAULT 0,
  abierto_en        DATETIME NOT NULL,
  usuario_cierre    INT NULL,
  cerrado_en        DATETIME NULL,
  efectivo_contado  DECIMAL(14,2) NULL,               -- lo que el cajero cuenta a mano
  efectivo_esperado DECIMAL(14,2) NULL,               -- lo que calcula el sistema
  diferencia        DECIMAL(14,2) NULL,               -- contado - esperado
  estado            VARCHAR(8) NOT NULL DEFAULT 'abierta',  -- abierta | cerrada
  corte_id          INT NULL,                         -- corte generado al cerrar
  nota              VARCHAR(255) NULL,
  creado_en         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)       REFERENCES negocios(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_apertura) REFERENCES usuarios(id) ON DELETE SET NULL,
  FOREIGN KEY (usuario_cierre)   REFERENCES usuarios(id) ON DELETE SET NULL,
  FOREIGN KEY (corte_id)         REFERENCES cortes(id)   ON DELETE SET NULL,
  INDEX idx_sesion_negocio (negocio_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Entradas/salidas de efectivo del turno (F7/F8 en la pantalla de venta).
CREATE TABLE IF NOT EXISTS caja_movimientos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  caja_sesion_id INT NOT NULL,
  tipo           VARCHAR(8) NOT NULL,                 -- entrada | salida
  monto          DECIMAL(14,2) NOT NULL,
  motivo         VARCHAR(255) NULL,
  usuario_id     INT NULL,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)      ON DELETE SET NULL,
  INDEX idx_cajamov_sesion (caja_sesion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cabecera de venta. uuid = idempotencia (lo genera la caja, incluso offline);
-- folio = correlativo por negocio que asigna el servidor al registrarla.
CREATE TABLE IF NOT EXISTS ventas (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL UNIQUE,
  negocio_id       INT NOT NULL,
  caja_sesion_id   INT NULL,
  usuario_id       INT NULL,                          -- cajero
  cliente_id       INT NULL,                          -- solo si fue venta fiado
  folio            INT NULL,                          -- correlativo POR NEGOCIO
  fecha            DATETIME NOT NULL,
  subtotal         DECIMAL(14,2) NOT NULL,
  descuento_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
  total            DECIMAL(14,2) NOT NULL,
  costo_total      DECIMAL(14,2) NULL,                -- para la ganancia real
  pago_con         DECIMAL(14,2) NULL,                -- con cuanto pago (efectivo)
  vuelto           DECIMAL(14,2) NULL,
  estado           VARCHAR(12) NOT NULL DEFAULT 'completada',  -- completada | anulada
  anulada_en       DATETIME NULL,
  anulada_por      INT NULL,
  motivo_anulacion VARCHAR(255) NULL,
  origen           VARCHAR(8) NOT NULL DEFAULT 'online',       -- online | offline
  nota             VARCHAR(255) NULL,
  creado_en        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)     REFERENCES negocios(id)      ON DELETE CASCADE,
  FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE SET NULL,
  FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)      ON DELETE SET NULL,
  FOREIGN KEY (anulada_por)    REFERENCES usuarios(id)      ON DELETE SET NULL,
  FOREIGN KEY (cliente_id)     REFERENCES clientes(id)      ON DELETE SET NULL,
  UNIQUE KEY uq_venta_folio (negocio_id, folio),
  INDEX idx_venta_negocio_fecha (negocio_id, fecha),
  INDEX idx_venta_sesion (caja_sesion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lineas de la venta. producto_id es NULL en "Producto Comun" y monto libre;
-- nombre/codigo/departamento son snapshots (el catalogo puede cambiar despues).
CREATE TABLE IF NOT EXISTS venta_items (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  venta_id        INT NOT NULL,
  producto_id     INT NULL,
  codigo          VARCHAR(40) NULL,
  nombre          VARCHAR(255) NOT NULL,
  departamento    VARCHAR(150) NULL,
  cantidad        DECIMAL(14,3) NOT NULL,
  precio_unitario DECIMAL(14,2) NOT NULL,
  descuento       DECIMAL(14,2) NOT NULL DEFAULT 0,
  total           DECIMAL(14,2) NOT NULL,
  costo_unitario  DECIMAL(14,2) NULL,
  afecto_iva      TINYINT(1) NOT NULL DEFAULT 1,
  promocion_id    INT NULL,                          -- que promo rebajo esta linea
  FOREIGN KEY (venta_id)    REFERENCES ventas(id)    ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
  INDEX idx_vitem_venta (venta_id),
  INDEX idx_vitem_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pagos de la venta. Varias filas = pago mixto (efectivo + tarjeta, etc.).
CREATE TABLE IF NOT EXISTS venta_pagos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  venta_id   INT NOT NULL,
  medio      VARCHAR(15) NOT NULL,      -- efectivo | tarjeta | transferencia | credito
  monto      DECIMAL(14,2) NOT NULL,
  referencia VARCHAR(120) NULL,         -- n. de operacion
  FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
  INDEX idx_vpago_venta (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tickets aparcados (F6 "Pendiente" de Eleventa): carritos sin cobrar todavia.
-- No son ventas: no tienen folio ni aparecen en reportes ni en el corte.
CREATE TABLE IF NOT EXISTS ventas_espera (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,
  negocio_id     INT NOT NULL,
  caja_sesion_id INT NULL,
  usuario_id     INT NULL,
  numero         INT NULL,                            -- correlativo visible del ticket
  etiqueta       VARCHAR(80) NULL,                    -- "Sra. Maria", "el del pan"...
  items          TEXT NOT NULL,                       -- carrito serializado (JSON)
  total_estimado DECIMAL(14,2) NULL,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)     REFERENCES negocios(id)      ON DELETE CASCADE,
  FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)      ON DELETE SET NULL,
  INDEX idx_espera_sesion (caja_sesion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Recaudacion de servicios (Klap y cualquier otro convenio) =====
-- CRITICO para el balance: la plata recaudada NO es venta y las recargas de
-- saldo NO son gasto. El unico ingreso real del negocio es la comision.

CREATE TABLE IF NOT EXISTS convenios (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id   INT NOT NULL,
  nombre       VARCHAR(80) NOT NULL,                  -- Klap, Sencillito, Servipag...
  modalidad    VARCHAR(10) NOT NULL DEFAULT 'prepago', -- prepago | postpago
  saldo_minimo DECIMAL(14,2) NULL,                    -- umbral de alerta (prepago)
  saldo_ajuste DECIMAL(14,2) NOT NULL DEFAULT 0,      -- correccion manual vs. el portal
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_convenio (negocio_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS servicio_operaciones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,
  negocio_id     INT NOT NULL,
  convenio_id    INT NOT NULL,
  caja_sesion_id INT NULL,
  usuario_id     INT NULL,
  tipo           VARCHAR(14) NOT NULL,   -- recaudacion | recarga_saldo | comision | liquidacion
  servicio       VARCHAR(80) NULL,       -- Luz, Agua, Telefono, Recarga...
  monto          DECIMAL(14,2) NOT NULL,
  medio_pago     VARCHAR(15) NULL,       -- recaudacion: efectivo|tarjeta|transferencia
  comision       DECIMAL(14,2) NULL,
  origen_fondos  VARCHAR(10) NULL,       -- recarga/liquidacion: banco | caja
  referencia     VARCHAR(120) NULL,      -- n. de comprobante
  fecha          DATETIME NOT NULL,
  nota           VARCHAR(255) NULL,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)     REFERENCES negocios(id)      ON DELETE CASCADE,
  FOREIGN KEY (convenio_id)    REFERENCES convenios(id)     ON DELETE RESTRICT,
  FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE SET NULL,
  FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)      ON DELETE SET NULL,
  INDEX idx_servop_negocio_fecha (negocio_id, fecha),
  INDEX idx_servop_convenio (convenio_id, fecha),
  INDEX idx_servop_sesion (caja_sesion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Promociones automaticas (el POS las aplica solo al vender) =====
-- El caso que pidio el usuario: "4 yogures por $1.000" cuando el yogur suelto
-- vale $300 -> al pasar 4, el total es 1.000 y no 1.200.
CREATE TABLE IF NOT EXISTS promociones (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id   INT NULL,                     -- NULL = vale en los dos negocios
  nombre       VARCHAR(120) NOT NULL,        -- "4 yogures por $1.000"
  tipo         VARCHAR(12) NOT NULL,         -- pack | precio | porcentaje | nxm
  producto_id  INT NULL,                     -- aplica a un producto...
  departamento VARCHAR(150) NULL,            -- ...o a un departamento entero
  cantidad     DECIMAL(14,3) NULL,           -- pack/nxm: cuantas unidades entran
  valor        DECIMAL(14,2) NOT NULL,       -- pack: precio del pack
                                             -- precio: precio unitario rebajado
                                             -- porcentaje: % de descuento
                                             -- nxm: cuantas se pagan
  desde        DATE NULL,                    -- vigencia (NULL = sin limite)
  hasta        DATE NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  creado_por   INT NULL,
  creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)  REFERENCES negocios(id)  ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  FOREIGN KEY (creado_por)  REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_promo_producto (producto_id, activo),
  INDEX idx_promo_depto (departamento, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
