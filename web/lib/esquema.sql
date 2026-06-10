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
