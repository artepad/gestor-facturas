# POS_DISENO.md — Diseño del módulo de Ventas / Punto de Venta

Este documento es el **plano** del nuevo módulo de Ventas (POS) que reemplazará a
Eleventa. Es la fuente de la verdad del diseño: contra esto se construye, por
etapas. No es código; es el acuerdo de *qué* vamos a hacer y *por qué*. Cuando algo
del diseño cambie, se actualiza acá primero.

> **Idioma:** todo en español (código, UI, comentarios, commits), igual que el resto
> de la web.
>
> **Ámbito:** vive **dentro de `web/`** (PHP 8.4 plano + MySQL, sin frameworks, sin
> npm/Composer, despliegue manual por cPanel). No toca `src/` (la app de escritorio).

---

## 1. Objetivo

Dejar de usar Eleventa y **gestionar las ventas desde la propia web**, con un POS
adaptado 100% al almacén: rápido para vender con lector de código de barras,
robusto ante cortes de luz/internet, e **integrado** con los módulos que ya existen
(Fiados, Ingresos, Gastos, Compras/Facturas, Catálogo de productos, Reportes por
negocio).

## 2. Decisiones tomadas (con el usuario)

| Tema | Decisión |
|------|----------|
| **Dónde vive la venta** | **Web / PWA local-first.** La pantalla de venta es una página web que se "instala" en el PC y guarda las ventas localmente; sincroniza con el servidor. Un solo proyecto (`web/`). |
| **Nº de cajas por local** | **Dos o más cajas a la vez.** Con internet, el **servidor es el dueño del stock** (las cajas se coordinan). Sin internet, cada caja sigue vendiendo y reconcilia al reconectar. |
| **Boleta / hardware** | **Boleta en pantalla / PDF / WhatsApp.** Sin impresora térmica ni cajón de dinero por ahora (se puede sumar después con un ayudante local, sin rehacer nada). |
| **Regla de oro** | La venta se guarda **primero localmente** y **después** se sincroniza (misma filosofía que la sincronización de facturas ya existente). |

## 3. Arquitectura general

```
   ┌─────────────────────────── PC de la Caja 1 ───────────────────────────┐
   │  Navegador (PWA "Minimark POS")                                        │
   │   • App shell cacheada por Service Worker  → abre sin internet         │
   │   • Catálogo del negocio copiado en IndexedDB → escanear es local      │
   │   • Carrito persistido continuamente → sobrevive cierre / corte de luz │
   │   • "Outbox": ventas y aperturas/cierres en cola con uuid              │
   └───────────────┬───────────────────────────────────────────────────────┘
                   │  (al Cobrar: 1 sola petición; si falla, queda en la cola)
                   ▼
   ┌───────────────────────── admin.minimark.cl (nube) ────────────────────┐
   │  pos_api.php  → upsert por uuid (idempotente, sin duplicar)            │
   │  MySQL: DUEÑO DEL STOCK cuando hay internet                            │
   │  Al cerrar caja → genera un `corte` → alimenta el módulo Ingresos      │
   └────────────────────────────────────────────────────────────────────────┘
                   ▲
   Caja 2, Caja 3… (otros PC del mismo o de otro negocio) hablan con el mismo servidor
```

**Modo normal (con internet):** escanear es instantáneo porque busca en el catálogo
guardado localmente; solo el botón **"Cobrar"** manda **una** petición al servidor,
que descuenta el stock de forma segura. Así todas las cajas ven el mismo stock.

**Modo corte (sin internet):** la caja sigue vendiendo desde el catálogo local; las
ventas quedan en la cola (`outbox`). Al volver internet, la cola se vacía sola: cada
venta sube con su `uuid` y el servidor la aplica una sola vez.

**Sin build step:** la PWA se hace con archivos estáticos planos (`manifest.webmanifest`
+ `sw.js` + JS de página) e IndexedDB nativo del navegador. **No** se agrega npm,
Composer ni compilación — igual que ya se hizo con `assets/zxing.min.js`.

**Autenticación:** el POS corre dentro de la sesión web normal del vendedor
(`exigir_permiso('ventas')` + alcance por `negocios_visibles()`), usando las cookies
de sesión. (El *token de máquina* de `maquinas` es solo para la sincronización de
facturas del escritorio; el POS **no** lo usa.)

**Sesión larga + PIN local (para poder abrir sin internet).** Un login normal exige
servidor; si la caja arranca sin conexión, el vendedor quedaría encerrado afuera. Por
eso, en el terminal POS:
- La cookie de sesión del POS es **de larga duración** (semanas) en ese dispositivo,
  renovada en cada sincronización exitosa.
- El vendedor desbloquea la pantalla con un **PIN corto** que se valida **localmente**
  (se guarda en el dispositivo solo el `hash` del PIN, nunca el PIN en claro; se
  descarga en la última conexión junto con el catálogo).
- El PIN **no da permisos nuevos**: solo desbloquea el terminal para operar con la
  identidad ya autorizada. Al reconectar, el servidor revalida la sesión; si el usuario
  fue desactivado o le cambiaron el rol, la caja se cierra y exige login real.
- **Requisito ineludible**: el PC/celular necesita **internet la primera vez** (para
  instalar la app, bajar el catálogo y el hash del PIN). Después ya puede arrancar
  offline.

### 3.1 Terminales: PC de caja y "caja móvil" (celular)

Al ser web, **cualquier dispositivo con sesión y permiso `ventas` puede ser un
terminal** — sin licencia por caja (a diferencia de Eleventa). Casos previstos:

- **Caja fija (PC + pistola láser)**: el flujo principal, el más rápido.
- **Caja móvil (celular)**: en horas peak se abre una `caja_sesiones` extra (ej. "Caja
  Móvil") desde el teléfono para descongestionar la fila. Escanea con la **cámara**,
  reutilizando el escáner híbrido ya existente (`BarcodeDetector` nativo +
  `assets/zxing.min.js` de respaldo, ver `herramienta_catalogo.php`), o busca por
  nombre.
  - *Limitación real*: la cámara es más lenta que la pistola láser (sirve como caja de
    apoyo, no reemplaza a la principal). Un lector Bluetooth es un accesorio opcional
    que la iguala en velocidad, sin cambios en el sistema.
  - *Efectivo*: el celular no tiene cajón. Dos modos de trabajo válidos: **(A)** la caja
    móvil lleva su propio fondo en riñonera y hace su **propio arqueo** al cerrar, o
    **(B)** se usa solo para pagos sin efectivo (transferencia/tarjeta) y el efectivo
    queda en las cajas fijas.
- Cada terminal **abre y cierra su propio turno** (`caja_sesiones`) y genera su propio
  `corte`. La tabla `cortes` ya distingue por cajero, así que encaja sin cambios.

## 4. El stock con varias cajas (la regla honesta)

### 4.0 El inventario es OPCIONAL, por negocio (así se parte)

Vender **no requiere stock**: para cobrar basta con código, nombre y precio — lo que ya
está cargado desde el Excel. Por eso el control de inventario es un **interruptor por
negocio**:

```sql
ALTER TABLE negocios ADD COLUMN control_stock TINYINT(1) NOT NULL DEFAULT 0;
```

- **Apagado (estado inicial de los dos negocios)**: el POS **no menciona el stock** en
  ninguna parte — no lo muestra, no lo descuenta, no genera avisos ni movimientos de
  inventario. Se vende y punto. Aun así se obtiene todo lo importante: ventas, caja y
  arqueo, medios de pago, fiados, cortes, reportes y **ganancia** (si el producto tiene
  `precio_costo`).
- **Encendido**: se hace un **conteo físico inicial** (movimientos tipo `inicial`) y
  desde ese momento el stock se descuenta y se controla.
- Se puede activar **cuando se quiera y por negocio** (uno sí, el otro no) **sin rehacer
  nada**: el inventario arranca desde el conteo del día que se activa; las ventas
  previas no necesitan reconstruirse.

> **Criterio**: un stock mal mantenido es peor que no tener stock (da números falsos).
> Primero que el POS funcione y las vendedoras se acostumbren; el inventario se activa
> después, con calma, incluso empezando solo por los productos caros o de alta rotación.

Todo lo que sigue en esta sección 4 aplica **solo cuando `control_stock = 1`**.

### 4.1 Reglas cuando el control de stock está activo

- **Fuente de verdad del stock = el servidor** (columna `productos.stock` +
  el libro mayor `inventario_movimientos`). Con internet, cada "Cobrar" descuenta
  ahí, dentro de una transacción, **una sola vez por `uuid`**.
- **Nunca se bloquea una venta por stock en 0.** En un almacén, si el producto está
  en la mano, se vende. Si una venta deja el stock negativo, se **marca** para que el
  dueño lo revise (no es un error que frene la caja).
- **Durante un corte de luz con dos cajas** es posible sobrevender la última unidad
  (ambas cajas vendieron algo físico real). Eso se **verá** en el stock negativo al
  reconectar. Lo que se **garantiza siempre**: ninguna venta se pierde y ninguna se
  duplica.

## 5. Modo offline y anti-duplicados

**Cómo NO se pierde una venta**
1. Al "Cobrar", la venta se escribe **local** al instante (IndexedDB) con un `uuid`
   propio y estado `pendiente`.
2. Se intenta subir al servidor. Si hay 200 OK → se marca `confirmada`. Si falla o no
   hay internet → **queda en la cola** y se reintenta luego. Nunca se borra sin
   confirmación.
3. IndexedDB está en disco: sobrevive al cierre del navegador y al corte de luz. En el
   peor caso se pierde solo la acción "en vuelo" (el carrito se guarda tras cada línea).

**Cómo NO se duplica una venta**
- El servidor hace **upsert por `uuid`** (si esa venta ya llegó, la ignora y responde
  OK). Es **exactamente** el patrón que ya usa `api/facturas.php`. Reenviar la misma
  venta mil veces nunca la duplica ni descuenta el stock dos veces (el descuento de
  stock ocurre solo en la rama "es nueva", dentro de la transacción).

**Frescura del catálogo (aviso de precios desactualizados)**

Sin internet la caja vende con **la última copia del catálogo que alcanzó a bajar**. Si
mientras tanto se cambió un precio desde otro dispositivo, esa caja seguirá cobrando el
precio viejo — no es una falla, es que no tuvo cómo enterarse. Para que nadie cobre mal
sin darse cuenta:

- Se guarda la marca de tiempo de la última sincronización del catálogo
  (`catalogo_actualizado_en` en el almacén local).
- Estando offline (o si la copia quedó vieja), la pantalla muestra una **franja de aviso
  permanente y visible**, por ejemplo:
  `⚠️ Sin conexión — lista de precios del lunes 9 de agosto`.
- El aviso escala con la antigüedad (mismo criterio que `estado_actualizacion()` del
  catálogo: informativo → naranja → rojo), para que "un rato sin internet" no se vea
  igual que "cinco días desactualizado".
- Al reconectar, el catálogo se refresca solo y el aviso desaparece.

**Avisos de seguridad (UX)**
- Cerrar la pestaña con una venta a medias o con la cola sin vaciar → aviso
  (`beforeunload`).
- Caja abierta o sin cuadrar al salir/entrar → aviso.
- Ventas pendientes de subir → indicador siempre visible con el contador
  (ej. `3 ventas por sincronizar`).

## 6. Estructura de archivos (en `web/`)

| Archivo | Rol |
|---------|-----|
| `pos.php` | **Pantalla de venta** (PWA): input de escaneo grande, carrito, totales, botones de pago. |
| `pos_api.php` | El "cerebro": endpoints JSON (registrar venta *upsert por uuid*, buscar producto, abrir/cerrar caja, anular). |
| `manifest.webmanifest`, `sw.js` | Hacen que `pos.php` sea instalable y funcione offline (app shell + caché). |
| `ventas.php` / `venta.php` | Historial y detalle de ventas (ver, anular, reimprimir boleta). |
| `boleta_imprimir.php` | Boleta imprimible (patrón `*_imprimir.php` ya existente). |
| `caja.php` | Estado de caja: apertura, cierre, arqueo, diferencias, historial de turnos. |
| `inventario.php` | *(Etapa 4, opcional)* Stock actual, ajustes, mermas, alertas de stock bajo, kardex por producto. Solo visible si el negocio tiene `control_stock = 1`. |
| `producto_form.php` | Crear/editar un producto a mano (hoy solo se carga por Excel). |
| `proveedores.php`, `compras.php`, `compra_form.php` | Proveedores y recepción de mercadería que **suma stock**. |
| `promociones.php` | Promos y descuentos con vigencia. |
| `servicios.php` | **Recaudación (Klap u otro convenio)**: historial de pagos de cuentas, recargas/liquidaciones y comisiones; saldo estimado + alerta. Solo si el negocio tiene algún `convenio` activo. (El registro rápido de un pago de cuenta se hace desde `pos.php`.) |
| `convenios.php` | Alta/edición de convenios de recaudación (nombre, modalidad prepago/postpago, umbral de alerta). Permite cambiar de proveedor sin tocar código. |
| **Reportes** | Se reutilizan `ingresos.php` y `negocio_panel.php` (ya existen), alimentados por los `corte` que genera el POS. |

**Se reutiliza tal cual:** `lib/db.php`, `lib/auth.php`, `lib/permisos.php`,
`lib/ui.php`, `lib/auditoria.php`, `productos_buscar.php` / `catalogo_buscar.php`
(escaneo), el módulo Fiados (`clientes`/`fiados`/`abonos`) y la generación de cortes
(`registrar_corte()` en `lib/eleventa.php`).

## 7. Modelo de datos

Definición canónica futura en `lib/esquema.sql` + `migrar.php` (aditivo, idempotente:
`CREATE TABLE IF NOT EXISTS` / `ALTER TABLE ADD COLUMN`, nunca `DROP`/`DELETE`).
Convenciones existentes: InnoDB + utf8mb4, montos `DECIMAL(14,2)`, cantidades con
decimales `DECIMAL(14,3)` (permite granel/peso), fechas `DATE`/`DATETIME`, `uuid`
`CHAR(36) UNIQUE` como clave de idempotencia.

### 7.1 Catálogo de productos: **compartido entre los negocios**

**Decisión del usuario (ago-2026):** un **único catálogo para los dos negocios**. Si se
agrega un producto en uno, aparece en el otro; si se cambia un precio, cambia en ambos.
Mismo criterio que ya usan `precios_fv` (Frutas y Verduras) y `categorias_gasto`.

**La migración es sin pérdida.** Verificado sobre los datos reales antes de decidir: los
dos negocios tenían **exactamente los mismos 2.694 productos**, con nombre, precio de
venta, costo, mayoreo, departamento y tipo de venta **idénticos**; cero productos
exclusivos de un local y cero diferencias de precio. No hay ningún conflicto que
resolver: unificar es quedarse con una copia.

```sql
-- productos deja de tener negocio_id: el codigo pasa a ser unico global.
ALTER TABLE productos ADD COLUMN activo          TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE productos ADD COLUMN afecto_iva      TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE productos ADD COLUMN creado_por      INT NULL;
ALTER TABLE productos ADD COLUMN actualizado_por INT NULL;
ALTER TABLE productos ADD COLUMN actualizado_en  TIMESTAMP
      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
-- (la migración deduplica por codigo, borra negocio_id y deja UNIQUE (codigo))
```

> **⚠️ El stock NO se comparte.** "Qué es el producto y cuánto vale" es común a los dos
> locales; "cuántos tengo" es de cada uno. Si el stock fuera compartido, vender una Coca
> en Minimark descontaría existencias en El Maitén. Por eso el stock **no vive en
> `productos`** sino en una tabla puente por negocio (Etapa 4):
>
> ```sql
> CREATE TABLE producto_stock (
>   producto_id  INT NOT NULL,
>   negocio_id   INT NOT NULL,
>   stock        DECIMAL(14,3) NOT NULL DEFAULT 0,
>   stock_minimo DECIMAL(14,3) NULL,
>   PRIMARY KEY (producto_id, negocio_id),
>   FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
>   FOREIGN KEY (negocio_id)  REFERENCES negocios(id)  ON DELETE CASCADE
> ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
> ```
>
> Corrige lo hecho en el paso 1A, que había puesto `stock`/`stock_minimo` dentro de
> `productos`. Se hace **ahora que esas columnas están en cero y sin usar**.

> **⚠️ Cambio crítico en la importación de Excel.** Hoy `reemplazar_catalogo()`
> **borra y reinserta** todo el catálogo en cada carga. Se cambia a un **upsert por
> `codigo`** que actualiza nombre/precios/departamento **pero conserva el stock y las
> marcas de quién editó**. Con el catálogo compartido, además, **basta subir el Excel una
> vez** (antes había que hacerlo por cada negocio). "Vaciar catálogo" mantiene sus
> salvaguardas (archivo vacío no borra nada; exige escribir `ELIMINAR`).

**Precios distintos por local**: hoy no se necesitan (0 diferencias) y el usuario quiere
que los cambios se propaguen. Si algún día hiciera falta, se agrega una tabla de
excepciones `producto_precio_negocio` sin rehacer nada de lo anterior.

*(Opcional, etapa posterior)* `producto_codigos` para varios códigos de barra por
producto: `(id, producto_id FK, codigo)`.

### 7.1.1 Registro de cambios del catálogo

**Decisión del usuario:** llevar historial **con pantalla para consultarlo** (a diferencia
de Fiados, donde se optó por solo guardar).

Se **reutiliza la tabla `auditoria`** que ya existe, en vez de crear una paralela. Como
hoy su única referencia es `cliente_id`, se le agregan dos columnas genéricas:

```sql
ALTER TABLE auditoria ADD COLUMN referencia_tipo VARCHAR(20) NULL;  -- producto, venta, caja...
ALTER TABLE auditoria ADD COLUMN referencia_id   INT NULL;
```

Se registra `crear_producto`, `editar_producto` (con el antes/después en `detalle`, para
poder leer "precio: $1.200 → $1.500"), `eliminar_producto` y `carga_excel`. Además,
`productos.actualizado_por`/`actualizado_en` dan el "actualizado por Fulana el 17-08"
que se muestra al editar, igual que en Frutas y Verduras.

**Permisos (decisión del usuario):** crear y editar productos lo pueden hacer **admin y
vendedoras**. Todo queda registrado con nombre y rol, así que un cambio de precio
indebido se puede rastrear.

### 7.2 Ventas

```sql
CREATE TABLE ventas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,          -- idempotencia (lo genera la caja)
  negocio_id     INT NOT NULL,
  caja_sesion_id INT NULL,                          -- a qué turno de caja pertenece
  usuario_id     INT NULL,                          -- cajero que vendió (snapshot en auditoría)
  cliente_id     INT NULL,                          -- solo si fue venta fiado
  folio          INT NULL,                          -- correlativo POR NEGOCIO (uso interno)
  fecha          DATETIME NOT NULL,                 -- momento de la venta (lo pone la caja)
  subtotal       DECIMAL(14,2) NOT NULL,
  descuento_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  total          DECIMAL(14,2) NOT NULL,
  costo_total    DECIMAL(14,2) NULL,                -- suma de costos → ganancia real
  estado         VARCHAR(12) NOT NULL DEFAULT 'completada', -- completada | anulada
  anulada_en     DATETIME NULL,
  anulada_por    INT NULL,
  motivo_anulacion VARCHAR(255) NULL,
  origen         VARCHAR(8) NOT NULL DEFAULT 'online',      -- online | offline
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)     REFERENCES negocios(id)     ON DELETE CASCADE,
  FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE SET NULL,
  FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)     ON DELETE SET NULL,
  FOREIGN KEY (cliente_id)     REFERENCES clientes(id)     ON DELETE SET NULL,
  UNIQUE KEY uq_venta_folio (negocio_id, folio),     -- correlativo sin repetir por negocio
  INDEX idx_venta_negocio_fecha (negocio_id, fecha),
  INDEX idx_venta_sesion (caja_sesion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> **Folio y offline**: el correlativo lo **asigna el servidor** al recibir la venta (no la
> caja), así dos terminales nunca generan el mismo número. Mientras una venta está en la
> cola offline se identifica por su `uuid`; al subir recibe su folio definitivo.

```sql

CREATE TABLE venta_items (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  venta_id       INT NOT NULL,
  producto_id    INT NULL,                          -- SET NULL: el historial sobrevive
  codigo         VARCHAR(40) NULL,                  -- snapshot
  nombre         VARCHAR(255) NOT NULL,             -- snapshot (el nombre puede cambiar luego)
  departamento   VARCHAR(150) NULL,                 -- snapshot (para corte_departamentos)
  cantidad       DECIMAL(14,3) NOT NULL,
  precio_unitario DECIMAL(14,2) NOT NULL,
  descuento      DECIMAL(14,2) NOT NULL DEFAULT 0,
  total          DECIMAL(14,2) NOT NULL,
  costo_unitario DECIMAL(14,2) NULL,                -- snapshot para ganancia
  afecto_iva     TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (venta_id)    REFERENCES ventas(id)    ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
  INDEX idx_vitem_venta (venta_id),
  INDEX idx_vitem_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ventas_espera (      -- tickets aparcados: carritos sin cobrar todavía
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,
  negocio_id     INT NOT NULL,
  caja_sesion_id INT NULL,                          -- turno en que se aparcó
  usuario_id     INT NULL,
  numero         INT NULL,                          -- correlativo visible del ticket
  etiqueta       VARCHAR(80) NULL,                  -- "Sra. María", "el del pan"...
  items          TEXT NOT NULL,                     -- carrito serializado en JSON
  total_estimado DECIMAL(14,2) NULL,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)     REFERENCES negocios(id)      ON DELETE CASCADE,
  FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE CASCADE,
  INDEX idx_espera_sesion (caja_sesion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE venta_pagos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  venta_id   INT NOT NULL,
  medio      VARCHAR(15) NOT NULL,                  -- efectivo | tarjeta | transferencia | credito
  monto      DECIMAL(14,2) NOT NULL,
  referencia VARCHAR(120) NULL,                     -- nº de operación, etc.
  FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Medios de pago reales del negocio** (confirmados con el usuario; son los únicos que se
ofrecen en pantalla):

| Medio | Entra al arqueo de efectivo | Notas |
|-------|:---:|-------|
| `efectivo` | **Sí** | Es lo que se cuenta al cerrar caja. |
| `tarjeta` | No | Débito/crédito por máquina (Transbank/Redelcom/etc.). |
| `transferencia` | No | Muy usado; se puede guardar el nº de operación en `referencia`. |
| `credito` | No | **Fiado**: genera un `fiado` del `cliente_id` (integración con Fiados). Exige elegir cliente. |

> **"Vales" queda descartado.** Ese campo venía de Eleventa (software mexicano, donde los
> *vales de despensa* son comunes); en Chile no aplica y en la práctica siempre estuvo en
> cero. **No se ofrece en el POS.** La columna `cortes.ventas_vales` **no se elimina**
> (los cortes históricos de Eleventa la usan y borrarla rompería datos viejos), pero el
> POS siempre la deja en 0 y la UI no la muestra cuando vale 0.

**Cambiar de máquina de tarjetas (adquirente)**: el medio `tarjeta` es **agnóstico al
proveedor** — da lo mismo si la máquina es Klap, Transbank, SumUp o Mercado Pago, porque
el POS solo registra que se pagó con tarjeta. Si algún día interesa separar por máquina
(para cuadrar liquidaciones), basta con agregar una columna opcional `adquirente
VARCHAR(30)` a `venta_pagos`; **no cambia nada más del diseño**.

**Pago mixto**: no es un medio aparte, es tener **varias filas** en `venta_pagos` para la
misma venta (ej. `$6.000 efectivo` + `$4.000 tarjeta`). Sirve para cualquier combinación,
incluida `efectivo + credito` (paga una parte y el resto queda fiado). Regla: la suma de
los pagos debe igualar el `total` de la venta; el vuelto se calcula solo sobre la parte
en efectivo y **no** se guarda como pago negativo.

### 7.3 Caja

```sql
CREATE TABLE caja_sesiones (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36) NOT NULL UNIQUE,
  negocio_id        INT NOT NULL,
  caja              VARCHAR(80) NULL,               -- nombre del terminal ("Caja 1")
  usuario_apertura  INT NULL,
  fondo_inicial     DECIMAL(14,2) NOT NULL DEFAULT 0,
  abierto_en        DATETIME NOT NULL,
  usuario_cierre    INT NULL,
  cerrado_en        DATETIME NULL,
  efectivo_contado  DECIMAL(14,2) NULL,             -- lo que el cajero cuenta a mano
  efectivo_esperado DECIMAL(14,2) NULL,             -- lo que el sistema calcula
  diferencia        DECIMAL(14,2) NULL,             -- contado − esperado (faltante/sobrante)
  estado            VARCHAR(8) NOT NULL DEFAULT 'abierta', -- abierta | cerrada
  corte_id          INT NULL,                       -- corte generado al cerrar
  creado_en         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
  FOREIGN KEY (corte_id)   REFERENCES cortes(id)   ON DELETE SET NULL,
  INDEX idx_sesion_negocio (negocio_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE caja_movimientos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  caja_sesion_id INT NOT NULL,
  tipo           VARCHAR(8) NOT NULL,               -- entrada | salida
  monto          DECIMAL(14,2) NOT NULL,
  motivo         VARCHAR(255) NULL,                 -- "pago pan proveedor", "retiro", ...
  usuario_id     INT NULL,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 7.4 Inventario (libro mayor)

```sql
CREATE TABLE inventario_movimientos (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id       INT NOT NULL,
  producto_id      INT NULL,
  tipo             VARCHAR(12) NOT NULL,            -- inicial | venta | compra | ajuste | merma | devolucion
  cantidad         DECIMAL(14,3) NOT NULL,          -- con signo: negativo = salida
  stock_resultante DECIMAL(14,3) NULL,              -- foto del stock tras el movimiento
  referencia_tipo  VARCHAR(12) NULL,                -- venta | compra | ajuste
  referencia_id    INT NULL,
  motivo           VARCHAR(255) NULL,
  usuario_id       INT NULL,
  creado_en        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)  REFERENCES negocios(id)  ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
  INDEX idx_invmov_producto (producto_id, creado_en),
  INDEX idx_invmov_negocio (negocio_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`productos.stock` es el **saldo rápido**; `inventario_movimientos` es el **historial
auditable** (el "kardex"). Cada venta/compra/ajuste escribe un movimiento y actualiza
el saldo, en la misma transacción. Así el stock nunca cambia "misteriosamente".

### 7.5 Proveedores y compras

```sql
CREATE TABLE proveedores (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id INT NOT NULL,
  nombre     VARCHAR(150) NOT NULL,
  rut        VARCHAR(20) NULL,
  telefono   VARCHAR(40) NULL,
  correo     VARCHAR(150) NULL,
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE compras (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id   INT NOT NULL,
  proveedor_id INT NULL,
  factura_id   INT NULL,                            -- enlace a una factura escaneada (opcional)
  fecha        DATE NOT NULL,
  total        DECIMAL(14,2) NULL,
  nota         VARCHAR(255) NULL,
  usuario_id   INT NULL,
  creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)   REFERENCES negocios(id)    ON DELETE CASCADE,
  FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL,
  FOREIGN KEY (factura_id)   REFERENCES facturas(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE compra_items (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  compra_id      INT NOT NULL,
  producto_id    INT NULL,
  codigo         VARCHAR(40) NULL,
  nombre         VARCHAR(255) NOT NULL,
  cantidad       DECIMAL(14,3) NOT NULL,
  costo_unitario DECIMAL(14,2) NULL,
  total          DECIMAL(14,2) NULL,
  FOREIGN KEY (compra_id)   REFERENCES compras(id)   ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Recibir una compra **suma stock** (escribe `inventario_movimientos` tipo `compra`).
Una factura de proveedor ya escaneada (`facturas` + `detalle_factura`) puede
convertirse en una compra que actualiza inventario (integración con el escritorio).

### 7.6 Promociones

```sql
CREATE TABLE promociones (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id   INT NOT NULL,
  nombre       VARCHAR(120) NOT NULL,
  tipo         VARCHAR(12) NOT NULL,                -- precio_fijo | porcentaje | monto | 2x1
  producto_id  INT NULL,                            -- promo de un producto
  departamento VARCHAR(150) NULL,                   -- o de un departamento
  valor        DECIMAL(14,2) NULL,
  desde        DATE NULL,
  hasta        DATE NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id)  REFERENCES negocios(id)  ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 7.7 Servicios de recaudación (Klap y cualquier otro convenio) — **crítico para que el balance sea real**

El negocio recibe pagos de cuentas de luz, agua, teléfono, recargas, etc. de sus clientes.
Hoy lo hace con una máquina **Klap** (ex Multicaja), pero **el diseño NO se amarra a Klap**:
mañana puede sumarse o cambiarse a **Sencillito, Servipag, Caja Vecina** u otro, o convivir
varios a la vez. Eleventa no permitía registrar nada de esto; el POS sí debe hacerlo, o el
balance y el arqueo quedan falseados.

**Cada convenio es una fila configurable**, no código:

```sql
CREATE TABLE convenios (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id   INT NOT NULL,
  nombre       VARCHAR(80) NOT NULL,             -- Klap, Sencillito, Servipag, Caja Vecina...
  modalidad    VARCHAR(10) NOT NULL DEFAULT 'prepago',  -- prepago | postpago
  saldo_minimo DECIMAL(14,2) NULL,               -- umbral de alerta (solo prepago)
  saldo_ajuste DECIMAL(14,2) NOT NULL DEFAULT 0, -- corrección manual para cuadrar con el portal
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negocio_id) REFERENCES negocios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_convenio (negocio_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Las dos modalidades que existen en el mercado** (el diseño soporta ambas):

| Modalidad | Cómo funciona | Ejemplo | Fórmula del saldo |
|---|---|---|---|
| **prepago** | El comercio **carga saldo por adelantado**; cada pago de cliente lo descuenta. | **Klap (SLC)** | `recargas + comisiones − recaudaciones + ajuste` → es un **haber** (te queda saldo) |
| **postpago** | El comercio cobra y **después liquida** lo recaudado (transferencia o descuento bancario). | Otros convenios | `recaudaciones − liquidaciones + ajuste` → es una **deuda** (debes plata) |

Agregar un convenio nuevo es **crear una fila y elegir su modalidad** — sin tocar código
ni migrar la base. Las operaciones ya registradas de Klap no se ven afectadas.

**Cómo funciona Klap, el convenio actual (verificado en su documentación, ago-2026):**
es de modalidad **prepago**. Opera con un **saldo
prepagado** llamado **SLC (Saldo Lógico Comercio)**. El comercio **carga saldo por
adelantado** (depósito/transferencia + "aviso de depósito" desde el POS o el Portal
Comercio; se activa en <20 min). Cuando un cliente paga una cuenta en efectivo, **el
efectivo entra al cajón y el saldo SLC baja** por el mismo monto. Las **comisiones** se
pagan dentro de los **primeros 5 días hábiles del mes** siguiente y **se abonan al propio
SLC** (no al banco), con un mínimo de ~6 transacciones al mes; el porcentaje **varía por
servicio** y se consulta en *Portal Comercio → Saldo Comercio (SLC) → Detalle comisiones*.
Luz/agua/gas admiten débito, crédito o efectivo; los demás servicios, solo efectivo.

> **Consecuencia contable — las dos trampas que hay que evitar:**
> 1. **La recaudación NO es venta.** Los $30.000 que paga el cliente no son ingreso del
>    negocio: es plata de la empresa de servicios. Contarla como venta inflaría las ventas
>    con dinero ajeno.
> 2. **La recarga de saldo Klap NO es un gasto.** Es plata propia cambiando de bolsillo
>    (banco → saldo Klap). Registrarla en el módulo Gastos produciría pérdidas falsas.
>    **Pendiente de revisar con el usuario**: si hoy hay recargas de Klap cargadas como
>    gasto en `gastos`, hay que depurarlas.
>
> **El único ingreso real del negocio es la comisión.**

```sql
CREATE TABLE servicio_operaciones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,          -- idempotencia (igual que ventas)
  negocio_id     INT NOT NULL,
  convenio_id    INT NOT NULL,                      -- Klap, Sencillito, Servipag...
  caja_sesion_id INT NULL,                          -- turno en que ocurrió
  usuario_id     INT NULL,
  tipo           VARCHAR(14) NOT NULL,              -- recaudacion | recarga_saldo | comision | liquidacion
  servicio       VARCHAR(80) NULL,                  -- Luz, Agua, Teléfono, Recarga...
  monto          DECIMAL(14,2) NOT NULL,
  medio_pago     VARCHAR(15) NULL,                  -- solo en recaudacion: efectivo|tarjeta|transferencia
  comision       DECIMAL(14,2) NULL,                -- si se conoce por operación
  origen_fondos  VARCHAR(10) NULL,                  -- recarga_saldo/liquidacion: banco | caja
  referencia     VARCHAR(120) NULL,                 -- nº de comprobante del convenio
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
```

**No hace falta un interruptor en `negocios`**: un negocio "tiene recaudación" si tiene al
menos un `convenio` activo. Si no tiene ninguno, el POS no muestra nada del tema. Un
convenio con operaciones registradas **no se borra** (FK `RESTRICT`): se desactiva
(`activo=0`), igual que se hace con `categorias_gasto`. Así, si algún día dejas Klap y te
pasas a otro, el historial de Klap se conserva intacto.

**Efecto de cada operación** (esta tabla es la especificación; que no se confunda nunca):

| `tipo` | ¿Suma a ventas? | Efecto en el arqueo de caja | Saldo del convenio |
|--------|:---:|---|:---:|
| `recaudacion` (medio `efectivo`) | ❌ **No** | **+** (el efectivo entró al cajón) | prepago: **baja** · postpago: sube la deuda |
| `recaudacion` (tarjeta/transferencia) | ❌ No | sin efecto | ídem |
| `recarga_saldo` *(solo prepago)* `origen_fondos=banco` | ❌ No (**no es gasto**) | sin efecto | **sube** |
| `recarga_saldo` *(solo prepago)* `origen_fondos=caja` | ❌ No | **−** (sale plata del cajón) → genera un `caja_movimientos` tipo `salida` | sube |
| `liquidacion` *(solo postpago)* | ❌ No (**no es gasto**) | `−` si sale de la caja; sin efecto si es transferencia bancaria | baja la deuda |
| `comision` | ✅ **Sí — ganancia real** | sin efecto | sube (Klap la abona al propio saldo) |

**Saldo estimado del convenio**: se calcula según su `modalidad` (ver la tabla de
modalidades arriba). Es un **cálculo propio** — no viene en línea del proveedor — así que
se muestra siempre como *estimado*, con un **ajuste manual** (`convenios.saldo_ajuste`)
para cuadrarlo contra el portal del proveedor. En prepago, si cae bajo `saldo_minimo` se
muestra una **alerta** — evita quedarse sin saldo con un cliente esperando.

## 8. Flujo de caja y de inventario

**Turno de caja**
1. **Abrir caja**: el cajero declara un `fondo_inicial`. Se crea una `caja_sesiones`
   (`estado='abierta'`) para ese terminal/negocio.
2. **Vender**: cada venta se asocia a esa sesión abierta.
3. **Entradas/salidas**: retiros, pagos a proveedores en efectivo, etc.
   (`caja_movimientos`).
4. **Cerrar caja / arqueo**: primero se verifica que **no queden tickets en espera** (si
   los hay, el cierre se bloquea y pide cobrarlos o descartarlos). Luego el sistema
   calcula el **efectivo esperado**:

   ```
   efectivo_esperado = fondo_inicial
                     + ventas_en_efectivo
                     + recaudaciones_klap_en_efectivo    ← ¡NO olvidar!
                     + abonos_de_fiados_en_efectivo
                     + entradas_de_caja
                     − salidas_de_caja                   (incluye recargas Klap desde caja)
   ```

   El cajero cuenta el efectivo real (`efectivo_contado`). La **diferencia** = contado −
   esperado (faltante o sobrante) se muestra y se guarda.

   > **Ojo**: si las recaudaciones de Klap no se suman aquí, el arqueo mostrará un
   > **sobrante todos los días** y será imposible detectar un descuadre real. Por eso el
   > registro de pagos de cuentas entra desde la **Etapa 1**, junto con la caja.
5. **Al cerrar, se genera un `corte`** (reutilizando `registrar_corte()`): ventas por
   medio de pago, nº de ventas, fondo, entradas/salidas, esperado, **ganancia real**
   (`total − costo_total`), más `corte_departamentos` (agrupando `venta_items` por
   departamento) y `corte_movimientos`. Las **recaudaciones Klap se reflejan en el
   efectivo esperado pero NO en `ventas_totales`** (van como total recaudado aparte, para
   no inflar las ventas). **Resultado: el módulo Ingresos y todos los
   reportes actuales siguen funcionando idénticos**, pero con datos reales del POS en
   vez del correo de Eleventa.

**Inventario** *(solo si `negocios.control_stock = 1`; ver 4.0)*: venta → resta stock
(`inventario_movimientos` tipo `venta`, negativo); compra/recepción → suma; ajuste/merma
→ corrige con motivo. `productos.stock` se actualiza en la misma transacción.
`inventario.php` muestra stock actual, alertas (`stock <= stock_minimo`) y el kardex por
producto. Con el control **apagado**, nada de esto se ejecuta ni se muestra, y el cierre
de caja/corte funciona exactamente igual.

**Activar el inventario más adelante** (por negocio, sin rehacer nada):
1. Se enciende `control_stock` para ese negocio.
2. Se hace el **conteo físico inicial** (carga por Excel `codigo,stock` o conteo en
   pantalla) → genera movimientos tipo `inicial`.
3. Desde ese instante las ventas descuentan y las compras suman. No hay que reconstruir
   el historial anterior: el inventario nace con ese conteo.

## 9. Integración con los módulos actuales

| Módulo actual | Cómo se conecta el POS |
|---------------|------------------------|
| **Ingresos** (`cortes`) | El **cierre de caja genera el `corte`** → reemplaza el correo de Eleventa. Reportes intactos. `corte_pegar.php` queda como respaldo/legado durante la transición. |
| **Fiados** (`clientes`/`fiados`/`abonos`) | Vender con medio `credito` crea un `fiado` del cliente automáticamente. |
| **Compras / Facturas** (`facturas`) | Una factura de proveedor puede volverse una `compra` que **suma stock**. |
| **Catálogo de productos** (`productos`) | Es el **maestro de venta**, ahora con stock. La importación de Excel se vuelve *upsert que conserva stock*. |
| **Gastos / Balance por negocio** (`negocio_panel.php`) | El POS aporta las ventas reales al balance ventas − compras − gastos. **Recaudación**: las recaudaciones **no** entran como venta y las recargas/liquidaciones **no** entran como gasto; solo la **comisión** suma como ingreso. Conviene mostrar el total recaudado como dato informativo aparte (volumen del servicio), nunca sumado a ventas. |
| **Usuarios y permisos** (`permisos.php`) | Se agrega el módulo `ventas` a la matriz `PERMISOS`. |
| **Auditoría** (`auditoria`) | Registra apertura/cierre de caja, anulaciones, descuentos, ajustes de stock: quién y cuándo. |
| **Sincronización** (`maquinas`, upsert por uuid) | El patrón anti-duplicados y la cola offline se calcan de aquí (ya probado). |

## 10. Roles, permisos y auditoría

- **Nuevo módulo `ventas`** en `PERMISOS`. Propuesta:
  - `vendedor`: `ventas` (vender, abrir/cerrar **su** caja).
  - `admin`: todo, incluyendo **anular ventas**, hacer **descuentos** sobre un tope,
    ver **reportes de ganancia**, gestionar **inventario/compras/promociones**.
  - Igual que en Fiados, ciertas acciones sensibles (anular, descuento grande, ajuste
    de stock) se **ocultan en la UI y se bloquean en el backend** si no es admin.
- **Auditoría**: se reutiliza `registrar_auditoria()` con `modulo='ventas'`. (Si hace
  falta, se agrega a `auditoria` un `referencia_id`/`referencia_tipo` genérico para
  apuntar a una venta o sesión de caja, además del `cliente_id` actual.)

## 11. Migración desde Eleventa

1. **Catálogo**: exportar de Eleventa a Excel → importar (¡ya se hace hoy!). Solo se
   ajusta para que **conserve stock**.
2. **Inventario inicial** *(opcional, solo si se activa `control_stock`)*: una vez,
   cargar el stock por producto (Excel de `codigo,stock` o conteo en pantalla →
   movimientos tipo `inicial`). **Se puede dejar para mucho después**: el POS reemplaza
   a Eleventa sin esto.
3. **Clientes/fiados**: si Eleventa los tiene, exportar/importar; si no, ya viven en el
   módulo Fiados.
4. **Historial de ventas viejo**: **no se migra**. El POS arranca en una fecha de corte;
   Eleventa queda de consulta del pasado. Los `cortes` históricos (de los correos) se
   conservan.
5. **Cutover seguro**: correr el POS **en paralelo** unos días, **cuadrar** contra el
   cierre de Eleventa, y recién ahí dejarlo solo.

## 12. Plan por etapas

Cada etapa es **usable por sí sola** (no hay que esperar al final para vender).

> **Estado (ago-2026): etapas 1 a 5 construidas y verificadas en desarrollo.**
> Lo que queda es del usuario: subir a producción (`DESPLIEGUE.md`, anexo del POS),
> hacer el cambio desde Eleventa (`MIGRACION_POS.md`) y confirmar en su propio
> Chrome las dos cosas que no se pudieron probar acá: el **modo kiosco** (que
> libera F12) y **vender con el wifi realmente desconectado**.
>
> Cambios sobre el plan original, todos por preguntarle al usuario antes de
> construir: el **2D (descuentos manuales) se descartó** —no los usa, resuelve las
> frutas con Producto Común— y se reemplazó por **promociones automáticas**, que
> era su necesidad real.

- **Etapa 1 — Vender de verdad (mínimo usable), SIN inventario**
  - `negocios.control_stock` (nace **apagado** en ambos negocios) → el POS no habla de
    stock en ninguna parte. Esto simplifica bastante esta etapa.
  - **Catálogo (paso 1F, el último por ser el más delicado)**, en cuatro tramos:
    **1F-a** unificar el catálogo entre los dos negocios y mover el stock a
    `producto_stock` (ver 7.1); **1F-b** `producto_form.php` para crear/editar a mano,
    con permiso para admin y vendedoras y auditoría de cada cambio; **1F-c** importación
    de Excel por **upsert** (deja de borrar y reinsertar, y basta subirla una vez);
    **1F-d** pantalla de historial de cambios del catálogo (ver 7.1.1).
  - `pos.php`: escanear / buscar por nombre / **producto común** / monto libre → carrito →
    cobrar con **un medio por venta** (efectivo con cálculo de vuelto, tarjeta o
    transferencia) → boleta en pantalla/PDF. Folio correlativo por negocio.
  - **Ventas en espera** (varios tickets a la vez, con etiqueta) — se usa a diario, no es
    opcional.
  - `caja.php`: abrir/cerrar caja con arqueo y diferencia.
  - **Pago de cuentas / recaudación** (con el convenio actual, Klap): registrar el pago
    del cliente para que el **efectivo entre al arqueo sin contarse como venta**. Va en
    esta etapa porque sin esto la caja **no cuadra** (ver 7.7 y el recuadro del arqueo).
  - Al cerrar caja → **genera `corte`** (Ingresos funciona al instante).
  - Permiso `ventas` + auditoría de acciones clave.
  - *(Todavía online-first; el offline completo llega en la Etapa 3.)*
- **Etapa 2 — Cobro completo + Klap completo**: **pago mixto** (varias filas en
  `venta_pagos`), venta **fiado** (→ módulo Fiados, con selección de cliente), descuentos
  y **promociones**, **anulaciones** y devoluciones. **Recaudación completa**:
  `convenios.php`, recargas/liquidaciones de saldo, comisión como ingreso, **saldo
  estimado con alerta de saldo bajo** y ajuste manual contra el portal del proveedor.
- **Etapa 3 — Blindaje offline**: PWA (`manifest` + `sw.js`), catálogo en IndexedDB,
  carrito persistente, `outbox` con sincronización y recuperación; **sesión larga + PIN
  local** (poder abrir la caja sin internet); **aviso de catálogo desactualizado**;
  avisos de cierre y contador de ventas por sincronizar.
- **Etapa 4 — Inventario y compras** *(opcional, se activa por negocio cuando el dueño
  quiera; ver 4.0)*: columnas de stock, `control_stock` encendido, conteo inicial,
  kardex, mermas, alertas de stock bajo, proveedores y compras que suman stock,
  reportes de **ganancia**. Se puede activar en un negocio y en el otro no, o postergarla
  indefinidamente sin que el POS deje de servir.
- **Etapa 5 — Migración/cutover** (+ opcional impresora térmica/cajón vía ayudante
  local, si se decide sumarlo).

> **Nota sobre el orden**: las etapas 1–3 dejan el POS **completo y confiable para
> vender** sin haber tocado inventario. La Etapa 4 es una mejora sobre un sistema que
> ya está funcionando, no un requisito para arrancar.

## 12.0 Decisiones de UX de la venta (confirmadas con el usuario)

Cómo se agrega un producto al carrito. **Cuatro caminos, todos en la misma pantalla**:

1. **Escanear código de barras** — el camino principal (lector láser en la caja fija,
   cámara en la caja móvil).
2. **Buscar por nombre** — se escriben las primeras letras y se elige de la lista.
3. **"Producto común"** — *equivalente a la función homónima de Eleventa, que el usuario
   ya usa a diario*: se escribe **un nombre libre + un precio** y se agrega la línea, sin
   necesidad de que el producto exista en el catálogo. Es la forma en que hoy se venden
   pan, frutas, verduras y granel.
4. **Monto libre / venta rápida** — igual que el anterior pero sin nombre (ej. "Varios
   $1.200"). Más rápido, pero deja menos rastro de qué se vendió.

> **Implicancia técnica**: las opciones 3 y 4 generan líneas **sin `producto_id`**. El
> modelo ya lo soporta: `venta_items.producto_id` es `NULL`able y `nombre` es un snapshot
> de texto. **No requiere tablas nuevas.** Ojo: esas líneas no tienen `costo_unitario`, así
> que no aportan a la ganancia calculada (se puede pedir el costo opcionalmente más
> adelante).

**Venta por peso**: se pospone para cuando exista catálogo con precio por kilo. Hoy el
granel se resuelve con "producto común" (se escribe el total ya calculado), que es
exactamente como se trabaja actualmente.

### Flujo real observado en Eleventa (capturas del usuario, ago-2026)

El usuario instaló Eleventa y documentó **exactamente qué usa a diario**. Esto manda sobre
cualquier suposición de diseño.

**Su flujo de cobro completo son 2 teclas**: escanear (o `ENTER` para agregar) → `F12`
(Cobrar) → `F2` (Cobrar **sin** imprimir). **El POS debe igualar o superar esa velocidad**;
si obliga a usar el mouse, será más lento que Eleventa y fracasará.

**Funciones que SÍ usa** (las únicas que la Etapa 1 debe replicar):

| Tecla | Función | Dónde vive en nuestro POS |
|-------|---------|---------------------------|
| *(escanear)* + `ENTER` | Agregar producto por código | Input siempre enfocado en `pos.php` |
| `Ctrl+P` | **Producto Común** (nombre + precio libres) | Modal rápido; línea sin `producto_id` |
| `F10` | Buscar producto por nombre | Modal de búsqueda |
| `F7` | **Entrada** de efectivo a la caja | En la pantalla de venta (no escondido) |
| `F8` | **Salida** de efectivo ("sacar efectivo de la caja") | Ídem |
| `DEL` | Borrar el **artículo seleccionado** | Sobre la línea marcada del carrito |
| `F6` | Dejar la venta **pendiente** (ticket en espera) | Ver "Ventas en espera" |
| *(botón)* | **Eliminar** la venta completa en curso | Distinto de `DEL` (que borra una línea) |
| `F12` | **Cobrar** | Abre el diálogo de cobro |
| `F2` *(en cobro)* | **Cobrar sin imprimir** ← su opción por defecto | Acción **primaria** del diálogo |
| `ESC` *(en cobro)* | Cancelar | — |
| *(botón)* | **Ventas del día y devoluciones** | Historial con folio, hora, total, detalle |

**Funciones que NO usa** (fuera de alcance de la Etapa 1): `INS` Varios, `F11` Mayoreo,
`F9` Verificador, `F5` Cambiar, Reimprimir Último Ticket, Asignar cliente.

**Detalles de UI observados que hay que replicar:**
- **Panel de la última venta** siempre visible abajo: `Total` / `Pagó Con` / `Cambio`.
  Sirve cuando el cliente pregunta por el vuelto después de cerrada la venta.
- **Pestañas de tickets** arriba, para ver los pendientes de un vistazo.
- **Cálculo de vuelto en vivo** al escribir "Pagó Con" (ej. paga $6.000 de $5.350 → `$650`).
- **Historial del día** con búsqueda **por folio o por nombre del ticket**, filtro por
  fecha y cajero, detalle de la venta, y acciones *Devolver artículo* / *Cancelar venta*
  (las acciones son Etapa 2; la pantalla de consulta es Etapa 1).
- Columna **"Existencia: Ilim"** en todos sus productos → **confirma que no usa control de
  stock**. Refuerza la decisión de 4.0 (inventario apagado).
- Cliente por defecto: *"Público en general"* (sin obligar a elegir cliente).

### ⚠️ Conflicto de teclas con el navegador — **resuelto: kiosco + tecla alterna**

`F12` abre las DevTools y `F1` la ayuda en Chrome/Edge: son atajos **del navegador** que
una página web **no puede interceptar**. Justo las teclas más usadas. **Decisión del
usuario: A + B combinadas.**

- **A — Modo kiosco/app**: acceso directo en el escritorio que abre el POS con Chrome en
  modo aplicación/kiosco (sin barra de direcciones, atajos del navegador desactivados).
  Se siente como un programa nativo y **libera `F12`/`F1`**. Se configura una vez por
  terminal; hay que documentarlo en la guía de instalación del POS.
- **B — Tecla alternativa siempre disponible**: además de `F12`, el `+` del teclado
  numérico también cobra (atajo habitual en POS). Red de seguridad si alguien abre el POS
  en un navegador normal.

Las demás teclas (`F6`, `F7`, `F8`, `F10`, `DEL`, `Ctrl+P`, `ESC`) sí se pueden capturar
sin problema en cualquier navegador. `F5` (recargar) también se puede bloquear para no
perder el carrito por error.

**Acción principal del cobro: "Cobrar sin imprimir"** (`F2`), tal como trabaja hoy. Es el
botón grande y destacado: cobra y devuelve de inmediato la pantalla lista para el siguiente
cliente. Ver la boleta queda como acción secundaria (para PDF/WhatsApp cuando alguien la
pida).

### Ventas en espera (varios tickets abiertos a la vez) — **imprescindible**

Réplica mejorada del flujo de tickets de Eleventa, **que el usuario usa a diario**: un
cliente se demora eligiendo, se **deja su ticket en espera** y se atiende al siguiente sin
perder el carrito. Sin esto el POS es inusable en hora peak.

- **"Dejar en espera"** guarda el carrito completo (`ventas_espera`) y limpia la pantalla.
  Un contador visible muestra cuántos hay (*"2 en espera"*).
- **Retomar**: se elige de la lista y el carrito vuelve tal cual estaba.
- **Etiqueta libre** además del número correlativo: en un almacén de barrio *"Sra. María"*
  o *"el del pan"* identifica mejor que *"ticket 64"*. El número se asigna solo si no se
  escribe etiqueta.
- Un ticket en espera **no es una venta**: no tiene folio, no descuenta stock, no aparece
  en reportes ni en el corte. Solo se vuelve `ventas` cuando se cobra.
- **No se puede cerrar la caja con tickets en espera**: el cierre avisa (*"tienes 2 ventas
  sin terminar"*) y obliga a cobrarlas o descartarlas. Esto cumple el requisito original
  de que *ninguna venta pendiente se pierda silenciosamente*. Descartar un ticket queda
  registrado en auditoría.
- **Dónde vive**: en la **Etapa 1** se guarda en el **servidor** (simple y robusto:
  sobrevive al cierre del navegador, al corte de luz y hasta al cambio de terminal). En la
  **Etapa 3** se agrega además copia local, para poder aparcar y retomar **sin internet**.

**Folio (número de venta)**: **sí**, correlativo **por negocio** (`ventas.folio`). No es
para el cliente — al cliente se le entrega el comprobante de la máquina Klap — sino de uso
interno: buscar una venta, anularla/devolverla (Etapa 2), resolver reclamos y dar
trazabilidad a la auditoría ("anuló la venta 47"). Es barato ahora e imposible de agregar
retroactivamente.

**Caja obligatoria**: **sí**. No se puede vender sin un turno abierto. Garantiza que toda
venta pertenezca a un turno y que el arqueo siempre cuadre. Si no hay caja abierta, `pos.php`
lleva primero a abrirla.

## 12.1 Estrategia de trabajo seguro (cómo no romper lo que ya funciona)

El sistema actual **está en producción y el negocio depende de él**. Todo el desarrollo
del POS se hace bajo estas reglas, sin excepción.

### Superficie de riesgo real

La gran mayoría del POS son **archivos y tablas nuevas**, que por definición no pueden
romper lo existente (nadie los llama todavía). Lo que **sí toca código en producción** es
poco y está acotado:

| Cambio sobre lo existente | Riesgo | Mitigación |
|---|---|---|
| `ALTER TABLE` en `productos` / `negocios` (columnas nuevas) | **Bajo** — agregar una columna no altera las existentes | `migrar.php` aditivo; respaldo previo |
| Ítem "Ventas" en el menú (`lib/ui.php`) | **Bajo** | Cambio de 3 líneas, revertible subiendo el archivo anterior |
| Módulo `ventas` en `lib/permisos.php` | **Bajo** | Una fila en la matriz; quitarla oculta todo el POS al instante |
| **Importación de Excel** (`lib/productos.php`) | **⚠️ ALTO** — hoy `reemplazar_catalogo()` **borra y reinserta** el catálogo | Ver abajo |

### Reglas de trabajo

1. **Rama de Git dedicada** (`pos`). `main` queda intacta y desplegable en todo momento.
   Si surge un arreglo urgente en producción, se hace en `main` sin arrastrar el POS a
   medio construir. Si el POS se descarta, se borra la rama y no pasó nada.
2. **Local primero**: nada se sube a cPanel sin haber funcionado en XAMPP con datos
   reales copiados.
3. **Base de datos solo aditiva**: `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE ADD
   COLUMN`. **Jamás** `DROP`, `DELETE` ni `TRUNCATE` sobre tablas existentes. Las tablas
   nuevas conviven sin tocar las viejas.
4. **El POS nace invisible**: se despliega **sin** dar el permiso `ventas` al rol
   `vendedor`. Solo el admin lo ve mientras se prueba. Habilitarlo (o volver a ocultarlo)
   es **una línea** en `PERMISOS`.
5. **Respaldo de la BD antes de cada despliegue** (cPanel → copia de la base). Sin
   respaldo no se sube nada.
6. **Despliegue por etapas pequeñas**, cada una probada y reversible por separado. No hay
   un "gran día del cambio".
7. **Lista explícita de archivos** a subir en cada entrega (ya es la práctica del
   proyecto), para saber exactamente qué revertir si algo falla.

### Tratamiento especial de la importación de Excel

Es el **único cambio realmente peligroso** (puede borrar el catálogo):

- Se aborda **al final de la Etapa 1**, no al principio.
- **No se modifica `reemplazar_catalogo()`**: se escribe una función nueva al lado
  (upsert) y se cambia cuál invoca la página. Volver atrás = cambiar una línea.
- Se prueba en local con el Excel real, comparando conteos antes/después.
- Se conservan las salvaguardas actuales (archivo vacío no borra nada; "Vaciar catálogo"
  exige escribir `ELIMINAR`).

### Cutover: el POS no manda hasta que se gane la confianza

**Eleventa NO se apaga** hasta que el POS cuadre varios días seguidos. Se corren **en
paralelo**: el negocio sigue operando con Eleventa mientras el POS se prueba con ventas
reales y se comparan los cierres. Si el POS falla, el negocio no se detiene.

### Plan de reversa

| Si falla… | Cómo se vuelve atrás | Tiempo |
|---|---|---|
| Una página nueva del POS | Borrar ese archivo del servidor | ~1 min |
| Menú / permisos | Subir la versión anterior del archivo (está en Git) | ~2 min |
| Tablas nuevas | Ignorarlas (son inertes) o borrarlas | ~5 min |
| Importación de Excel | Restaurar la BD desde el respaldo previo | ~10 min |
| **Todo el POS** | Quitar archivos nuevos + restaurar respaldo → **vuelve el sistema actual tal cual** | ~20 min |

## 13. Fuera de alcance / decisiones pendientes

- **Revisar `gastos` por recargas de Klap mal clasificadas** (ver 7.7): si el usuario
  registró recargas de saldo Klap como gasto operacional, el balance histórico está
  distorsionado y hay que depurarlas. **Preguntar antes de tocar nada.**
- **Datos de Klap por confirmar con el usuario**: si su convenio usa efectivamente el
  modelo SLC prepagado, y cuál es el porcentaje de comisión por servicio (está en
  *Portal Comercio → Saldo Comercio (SLC) → Detalle comisiones*). El diseño no depende de
  esos números, pero permitirían estimar la comisión automáticamente en vez de ingresarla.
- **Integración automática con el proveedor de recaudación**: no se conoce API pública de
  Klap; el registro de pagos de cuentas es **manual** en el POS (el cliente paga en la
  máquina y la vendedora lo anota). Si algún proveedor ofreciera exportación/API, se
  podría automatizar **sin cambiar el modelo de datos** (las operaciones ya llevan `uuid`
  y `referencia`).
- **Comisión por porcentaje automático**: se podría agregar a `convenios` una tabla hija
  de tasas por servicio para calcular la comisión sola. Hoy se ingresa el monto conocido.

- **Impresora térmica y cajón de dinero**: fuera por ahora (boleta en pantalla/PDF).
  Se puede sumar después con un pequeño ayudante local sin rehacer el módulo.
- **Boleta electrónica SII (DTE)**: no contemplado en este diseño; sería un módulo
  aparte si algún día se necesita facturación tributaria.
- **Balanza integrada** (peso automático): por ahora el peso se ingresa a mano en la
  venta de granel. Integración con balanza queda para más adelante.
- **Multi-código de barra por producto** (`producto_codigos`): opcional, etapa
  posterior.
```
