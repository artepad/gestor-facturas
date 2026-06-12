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
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nombre        VARCHAR(150),
  rol           VARCHAR(20) NOT NULL DEFAULT 'vendedor',   -- ver lib/permisos.php
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
  creado_en   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  INDEX idx_fiado_cliente (cliente_id)
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
CREATE TABLE IF NOT EXISTS productos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id     INT NOT NULL,
  codigo         VARCHAR(40)  NOT NULL,           -- codigo de barra / codigo Eleventa
  nombre         VARCHAR(255) NOT NULL,
  precio_costo   DECIMAL(14,2) NULL,
  precio_venta   DECIMAL(14,2) NULL,
  precio_mayoreo DECIMAL(14,2) NULL,
  departamento   VARCHAR(150) NULL,
  tipo_venta     VARCHAR(30)  NULL,
  carga_id       INT NULL,                        -- de que subida vino
  UNIQUE KEY uq_producto (negocio_id, codigo),    -- 1 codigo por negocio
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
  INDEX idx_producto_negocio (negocio_id),
  INDEX idx_producto_nombre (negocio_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una carga = una subida de Excel. Da la "ultima actualizacion" y el historial.
CREATE TABLE IF NOT EXISTS producto_cargas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id      INT NOT NULL,
  archivo_nombre  VARCHAR(255) NULL,
  total_productos INT NOT NULL DEFAULT 0,
  total_omitidos  INT NOT NULL DEFAULT 0,          -- filas sin codigo/nombre descartadas
  cargado_por     INT NULL,
  cargado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)  REFERENCES negocios(id)  ON DELETE CASCADE,
  FOREIGN KEY (cargado_por) REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_carga_negocio (negocio_id, cargado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
