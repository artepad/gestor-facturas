# CLAUDE.md — Plataforma web (admin.minimark.cl)

Guía para Claude Code cuando trabaje **dentro de la carpeta `web/`**. Este es un
proyecto separado en su intención (el dashboard web), aunque vive en el mismo
repositorio que la app de escritorio de Python. **Lee también `web/CONTRATO.md`**:
ahí está el "puente" que conecta la web con el cliente Python; ninguno de los dos
lados puede romperlo sin coordinar con el otro.

## Contexto

`admin.minimark.cl` es el **dashboard web** que centraliza las facturas de todos
los negocios (almacenes) en un solo lugar. Cada PC de cada negocio corre la app
de escritorio de Python (el escáner local que clasifica facturas) y **sincroniza**
una copia de cada factura hacia esta web. La web es un **espejo de consulta**: la
fuente de verdad sigue siendo la BD local de cada PC. Acá no se escanea ni se
clasifica nada con IA; acá se consulta, se filtra, se ve el PDF y se administran
negocios y usuarios.

El idioma de todo (código, comentarios, UI, commits) es **español**. Mantenerlo.

## Stack

- **PHP 8.4** (`ea-php84` en HostGator), **sin frameworks**. PHP plano + PDO.
- **MySQL** (utf8mb4, InnoDB). Acceso siempre vía **prepared statements** (PDO con
  `ATTR_EMULATE_PREPARES => false`).
- Front: HTML server-rendered + un solo `assets/estilo.css`. JS mínimo e inline
  (toggle del sidebar, acordeón de Administración). Sin build, sin npm, sin SPA.
- Hosting: **HostGator (cPanel)**, subdominio `admin.minimark.cl`. Despliegue
  **manual**: subir archivos por el Administrador de archivos de cPanel.

## Cómo se despliega (importante)

No hay CI/CD. El flujo real es: editás los archivos en `web/`, los commiteás, y
**el usuario sube manualmente los archivos cambiados** al servidor por cPanel.
Por eso, cuando termines un cambio, **dile al usuario exactamente qué archivos
subir** (ruta relativa dentro de `web/`). No asumas que un cambio está "en
producción" hasta que el usuario lo confirme.

Detalle completo de instalación inicial en `web/README_SERVIDOR.md`.

## Arquitectura de archivos

```
web/
├── index.php            raíz: si hay sesión → home.php, si no → login.php
├── config.php           ← credenciales MySQL + setup_key. NO se sube a git (gitignored)
├── config.ejemplo.php   plantilla de config.php
├── .htaccess            HTTPS forzado + seguridad
│
├── lib/
│   ├── db.php           obtener_pdo() (singleton PDO), cargar_config(), responder_json()
│   ├── auth.php         login/sesión + control de acceso por negocio
│   ├── permisos.php     matriz RBAC rol→módulos + exigir_permiso()
│   ├── ui.php           layout compartido (sidebar, footer) + helpers de formato + íconos SVG
│   ├── eleventa.php     parser del correo de corte de Eleventa + registrar_corte()
│   ├── productos.php    carga del catálogo Excel de Eleventa + estado + búsqueda de productos
│   ├── gastos.php       helpers del módulo Gastos (categorías, totales, generar fijos del mes)
│   └── esquema.sql      definición de todas las tablas MySQL
│
├── api/
│   └── facturas.php     ENDPOINT de sincronización (POST desde el cliente Python). Ver CONTRATO.md
│
├── home.php             tablero de inicio (tarjetas resumen + accesos rápidos)
├── negocio_panel.php    panel de análisis de un negocio (clic en "Estado por negocio")
├── panel_facturas.php   listado de facturas + filtros (la página principal de consulta)
├── factura.php          detalle de una factura (cabecera + productos + botón Ver PDF)
├── ver_pdf.php          sirve el PDF de una factura con control de acceso por negocio
│
├── fiados.php           listado de clientes con su saldo (módulo Fiados)
├── cliente_form.php     crear/editar un cliente de fiados
├── cliente.php          ficha del cliente: saldo + registrar fiados/abonos + historial
│
├── ingresos.php         dashboard del módulo Ingresos (cortes de caja de Eleventa)
├── corte.php            detalle de un corte (resumen, movimientos, departamentos, correo original)
├── corte_pegar.php      registrar un corte pegando el correo a mano (respaldo del cron)
├── procesar_cortes.php  worker del cron: lee la casilla IMAP + procesa pendientes (CLI o ?clave=setup_key)
│
├── gastos.php           dashboard del módulo Gastos (resumen del período + desglose + tabla)
├── gasto_form.php       crear/editar/eliminar un gasto
├── categorias_gasto.php CRUD de categorías de gasto (editables)
├── gastos_fijos.php     plantillas de gasto fijo mensual (recurrentes)
│
├── precios.php          Frutas y Verduras: lista de precios compartida (consulta + buscador)
├── precio_form.php      crear/editar/eliminar un producto de la lista de precios
│
├── herramientas.php           tablero del módulo Herramientas (tarjetas)
├── herramienta_caja.php       contador de caja (billetes/monedas, monedas por peso)
├── herramienta_etiquetas.php  gestor de etiquetas de precio (+ buscador desde el catálogo)
├── etiquetas_imprimir.php     hoja A4 imprimible de etiquetas de precio (página desnuda)
├── herramienta_ofertas.php    creador de etiquetas de oferta (4 tipos)
├── ofertas_imprimir.php       hoja A4 imprimible de etiquetas de oferta (página desnuda)
├── herramienta_productos.php  Base de Datos de Productos: carga el Excel de Eleventa por negocio
├── productos_buscar.php       endpoint JSON de búsqueda del catálogo (lo usa el gestor de etiquetas)
├── herramienta_catalogo.php   Consultar Catálogo: buscador + escáner de cámara de productos
├── catalogo_buscar.php        endpoint JSON con todos los campos del producto (lo usa Consultar Catálogo)
│
├── login.php            formulario de ingreso
├── logout.php           cerrar sesión
│
├── negocios.php         lista de negocios (tarjetas)
├── negocio_form.php     crear/editar perfil de negocio (nombre, rut, teléfono, dirección, correo)
├── negocio_tokens.php   gestión de máquinas/tokens de un negocio
├── usuarios.php         CRUD de usuarios + asignación de negocios (solo admin)
│
├── almacen_pdf/         PDFs sincronizados, organizados en subcarpetas por negocio_id. Bloqueado por .htaccess
│
└── utilitarios de un solo uso (BORRAR del servidor después de usar):
    ├── setup.php             crea tablas + primer negocio + token (correr 1 vez)
    ├── crear_admin.php       crea usuarios por URL
    ├── crear_admin_form.php  crea usuarios por formulario (evita problemas de caracteres en URL)
    ├── migrar.php            aplica cambios de esquema a una BD existente
    └── reset_facturas.php    borra facturas para pruebas
```

## Modelo de datos (MySQL)

Definición canónica en `lib/esquema.sql`. Tablas:

- **`negocios`** — perfil de cada almacén: `slug` (único, identificador corto),
  `nombre`, `rut`, `telefono`, `direccion`, `correo`, `activo`. Se desactiva sin
  borrar (`activo=0`).
- **`usuarios`** — `email` (único), `password_hash` (bcrypt vía `password_hash()`),
  `nombre`, `rol` (`admin` | `sucursal`).
- **`usuario_negocio`** — tabla puente N:N. Qué negocios ve cada usuario `sucursal`.
  El `admin` ve todos sin necesitar filas aquí.
- **`maquinas`** — cada PC que sincroniza. `token_hash` = **sha256 del token**
  (el token en claro NUNCA se guarda), `negocio_id`, `ultima_sync`. Un negocio
  puede tener varias máquinas, pero normalmente escanea solo el PC principal.
- **`facturas`** — el espejo de cada factura. `uuid_local` (CHAR(36) **UNIQUE**)
  es la clave de idempotencia: es el mismo UUID que generó el PC. **Soft-delete**
  vía `eliminada_en` (no se borra físicamente). Ver CONTRATO.md para los campos.
- **`detalle_factura`** — líneas de producto de una factura (relación con
  `ON DELETE CASCADE`). Se reemplaza completo en cada sync.
- **`sync_log`** — bitácora de cada operación de sincronización (auditoría).

**Módulo Fiados** (web-nativo, NO participa de la sincronización; la web es la
fuente de verdad):
- **`clientes`** — clientes a crédito de cada negocio (`negocio_id`, `nombre`,
  `apellido`, `telefono`, `direccion`, `correo`, `activo`). `negocio_id` vive solo
  aquí; fiados/abonos llegan al negocio a través del cliente.
- **`fiados`** — cargos a crédito que aumentan la deuda (`cliente_id`, `fecha`,
  `monto`, `descripcion`).
- **`abonos`** — pagos que disminuyen la deuda (`cliente_id`, `fecha`, `monto`,
  `nota`). **Cuenta corriente**: saldo del cliente = Σ`fiados.monto` − Σ`abonos.monto`
  (calculado, no almacenado). Acceso por permiso de módulo `fiados`, acotado por
  `negocios_visibles()`.

**Módulo Ingresos** (web-nativo; los datos llegan por el correo de corte de
Eleventa, NO por la sincronización de facturas):
- **`correos_corte`** — cada correo crudo recibido (`cuerpo` MEDIUMTEXT,
  `message_id` UNIQUE para idempotencia, `origen` imap|manual, `estado`
  pendiente|procesado|error). Se guarda SIEMPRE el crudo: si Eleventa cambia el
  formato, se ajusta `lib/eleventa.php` y se reprocesa sin perder nada.
- **`cajeros`** — cajeros de Eleventa por negocio (UNIQUE negocio+nombre), se
  auto-crean al aparecer en un corte; `usuario_id` opcional los vincula a un
  usuario web.
- **`cortes`** — un cierre de turno: caja, cajero, rango del turno, ventas
  totales/efectivo/tarjeta/crédito/vales/transferencia, fondo, abonos,
  entradas/salidas y esperado. UNIQUE (negocio, cerrado_en, cajero) hace el
  reproceso idempotente (mismo patrón upsert que `api/facturas.php`).
- **`corte_movimientos`** — entradas/salidas de caja con hora y descripción
  (ej. pagos de pan a proveedores). Se reemplazan completos en cada reproceso.
- **`corte_departamentos`** — ventas por departamento del corte.

**Flujo de captura**: Eleventa de cada negocio manda su corte a un alias
`corte-{slug}@minimark.cl` (forwarder de cPanel) que entrega en la casilla real
`cortes@...` (credenciales en el bloque `cortes_imap` de `config.php`). El cron
de cPanel corre `procesar_cortes.php` (solo CLI o `?clave=setup_key`): baja los
correos no leídos por IMAP, identifica el negocio por el destinatario
(`negocio_por_destinatario`, header Delivered-To → `negocios.slug`) y procesa
todo lo pendiente con `parsear_corte_eleventa()`. `corte_pegar.php` permite
registrar un corte pegando el correo a mano (mismo parser, `origen='manual'`,
útil en local donde no hay IMAP). Nota: `config.php` detecta el ambiente por
`HTTP_HOST`; en CLI (cron) no hay host, así que usa la config de producción —
correcto en el servidor.

**Módulo Herramientas** (utilidades operativas, web-nativo). Tablero de tarjetas
(`herramientas.php`) gateado por el permiso `herramientas` (lo tienen `admin` y
`vendedor`). Incluye el contador de caja, el gestor de etiquetas de precio, el
creador de etiquetas de oferta y la Base de Datos de Productos. Las páginas
`*_imprimir.php` son "desnudas" (sin layout) para imprimir limpio vía
`window.print()`.

**Módulo Gastos** (web-nativo; gastos operacionales del negocio: agua, luz, gas,
sueldos, arriendo, etc.). **Solo admin** (información financiera del dueño, igual
que Ingresos). Acotado por `negocios_visibles()`. Helpers en `lib/gastos.php`.
- **`categorias_gasto`** — catálogo editable de tipos de gasto (`nombre` UNIQUE,
  `activo`, `orden`). **Compartido entre negocios** (el dueño es uno). Se siembra con
  categorías base (Agua, Luz, Gas, Sueldos, Arriendo, Internet/Teléfono, Mantención,
  Otros) vía `INSERT IGNORE`. CRUD en `categorias_gasto.php`; una categoría con
  gastos/plantillas asociadas no se borra (se desactiva).
- **`gastos`** — cada gasto real (`negocio_id`, `categoria_id` FK RESTRICT, `fecha`,
  `monto`, `descripcion`, `gasto_fijo_id` opcional → marca que nació de una plantilla,
  `creado_por`). CRUD en `gasto_form.php`.
- **`gastos_fijos`** — plantillas de gasto mensual recurrente (`negocio_id`,
  `categoria_id`, `descripcion`, `monto_estimado`, `dia_mes`, `activo`). CRUD en
  `gastos_fijos.php`. **Recurrencia sin cron**: el botón "Generar gastos fijos del
  mes" en `gastos.php` llama `registrar_gastos_fijos()`, que inserta un `gasto` por
  plantilla activa que aún no tenga uno este mes (idempotente: no duplica).

El **Home** muestra "Gastos del mes" (reemplazó a "Por cobrar") y suma los gastos en
la grilla "Estado por negocio". El dashboard de Gastos compara ventas (de `cortes`)
vs. gastos del período (balance).

**Módulo Frutas y Verduras** (web-nativo; lista de precios **compartida** entre
todos los negocios — la compra la hace una sola persona para ambos). **Editan admin
y vendedores** (`'precios'` en `PERMISOS` para los dos roles); todas las vendedoras
consultan. Pensado para el celular: `precios.php` muestra tarjetas grandes (nombre,
precio + unidad, fecha de actualización, observación) con buscador por nombre;
`precio_form.php` crea/edita/elimina (nombre, precio en texto chileno con
`parsear_monto`, `unidad` de un set fijo, observación, `activo`).
- **`precios_fv`** — `nombre`, `precio DECIMAL(14,2)`, `unidad` (kilo/unidad/
  bandeja…), `observacion`, `activo`, `actualizado_por` (FK→usuarios),
  `actualizado_en TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP` (fecha vigente
  automática). **Sin `negocio_id`**: es una sola lista para todos (como
  `categorias_gasto`). Identidad visual: verde (`modulo-verde`).

**Base de Datos de Productos** (catálogo por negocio que alimenta el gestor de
etiquetas):
- **`productos`** — catálogo de cada negocio (`negocio_id`, `codigo` único por
  negocio, `nombre`, `precio_costo/venta/mayoreo`, `departamento`, `tipo_venta`,
  `carga_id`). Se **reemplaza completo** en cada carga de Excel.
- **`producto_cargas`** — una fila por subida de Excel (`archivo_nombre`,
  `total_productos`, `total_omitidos`, `cargado_por`, `cargado_en`). Da la "última
  actualización" (`MAX(cargado_en)`) y el historial; no se borra al reemplazar.

**Flujo**: el usuario exporta el catálogo desde Eleventa a un `.xlsx` y lo sube en
`herramienta_productos.php` (selector de negocio acotado por `negocios_visibles`).
`lib/productos.php` lo parsea con **`ZipArchive`+`SimpleXML` nativos** (un `.xlsx`
es un zip de XML; **no se usa Composer ni PhpSpreadsheet**): mapea columnas por el
texto del encabezado (resiste reordenamientos), parsea los precios como texto
chileno (`$2.000`→`2000`) y descarta filas sin código/nombre. `reemplazar_catalogo()`
hace un **reemplazo transaccional** (registra la carga, `DELETE` del catálogo,
`INSERT` por lotes; `rollBack` si algo falla). **Salvaguardas anti-borrado**: un
archivo vacío/equivocado nunca borra el catálogo (falla antes), y "Vaciar catálogo"
exige escribir `ELIMINAR`. `estado_actualizacion()` calcula el aviso de antigüedad
(≤6 días ok, 7–14 naranja, >14 rojo) que se muestra en el administrador y en el
gestor de etiquetas. `buscar_productos()` (vía `productos_buscar.php`, JSON)
resuelve el autocompletar: por código de barras (prefijo) o por nombre (`LIKE`).

**Consultar Catálogo** (`herramienta_catalogo.php`, solo lectura): buscador
móvil-primero del catálogo. Búsqueda en vivo (debounce) contra `catalogo_buscar.php`
(`consultar_catalogo()` devuelve **todos** los campos; `departamentos_de_negocio()`
puebla el filtro de departamento), resultados como tarjetas. **Escáner de código
de barras híbrido**: usa el `BarcodeDetector` nativo del navegador cuando existe
(Android/Chrome) y **carga `assets/zxing.min.js` de forma diferida** como respaldo
(iPhone/Safari) — un único camino de cámara (`getUserMedia` con cámara trasera)
con dos decodificadores. Requiere **HTTPS** para la cámara (ya lo es en producción;
para probar en celular hay que abrir el dominio, no una IP local). `zxing.min.js`
es la librería `@zxing/library` vendorizada (un asset estático, sin Composer/npm).
El botón "Crear etiqueta" de cada resultado abre `herramienta_etiquetas.php?nombre=&precio=`
con la primera fila precargada.

## Roles y permisos (en `lib/permisos.php` + `lib/auth.php`)

- **Modelo RBAC simple definido en código**: la matriz `PERMISOS` mapea cada rol a
  los **módulos** que puede usar (`facturas`, `fiados`, `ingresos`, `gastos`,
  `precios`, `herramientas`, `negocios`, `usuarios`). Roles actuales: **`admin`**
  (todos los módulos) y **`vendedor`** (`facturas`, `fiados`, `precios`,
  `herramientas`). `usuarios.rol` es `VARCHAR(20)` (agregar roles no requiere
  `ALTER`). Los módulos `ingresos` y `gastos` son solo admin (información financiera
  del dueño); `precios` (Frutas y Verduras) lo usan admin y vendedores.
- **Agregar un rol** = una fila en `PERMISOS` + etiqueta en `ROLES`. **Agregar un
  módulo** = su clave en la matriz + `exigir_permiso('modulo')` en la página + ítem
  en el menú de `lib/ui.php`. Todo el control vive en un solo lugar.
- Cada página protegida llama **`exigir_permiso('modulo')`** (en `permisos.php`):
  `exigir_login()` + chequea la matriz; si no tiene el permiso redirige a `home.php`.
  `exigir_admin()` queda para chequeos puntuales "solo admin".
- **Alcance por negocio** se mantiene: `negocios_visibles($usuario)` (admin = todos;
  cualquier otro rol = asignados en `usuario_negocio`) y `puede_ver_negocio()`.
  Toda consulta de facturas/fiados filtra por los negocios visibles, y
  `ver_pdf.php`/`factura.php`/`cliente.php` validan `puede_ver_negocio()`. **Nunca**
  sirvas datos de un negocio que el usuario no puede ver.
- `usuarios.php` solo admin (`exigir_permiso('usuarios')`); incluye salvaguarda de
  **no dejar el sistema sin ningún administrador** (al cambiar rol o eliminar).

## Convenciones importantes

- **Seguridad primero**: todo input va por prepared statements. Todo output a HTML
  pasa por `htmlspecialchars()` (hay un helper `h()` en algunas páginas). Sesiones
  con `httponly` + `samesite=Lax` + `session_regenerate_id` al login.
- **Layout compartido**: toda página usa `cabecera_dashboard($usuario, $activo)` y
  `pie_dashboard()` de `lib/ui.php`. El `$activo` (string: `home`/`facturas`/
  `negocios`/`usuarios`) marca el ítem activo en el sidebar. No dupliques el HTML
  del layout; si necesitás cambiarlo, hacelo en `ui.php`.
- **Íconos**: SVG inline vía `icono($nombre)` en `ui.php` (usan `currentColor`).
  Para agregar uno nuevo, añadí su `path` al arreglo `$svg`.
- **Tema visual**: un solo `assets/estilo.css`, con variables CSS en `:root`. La
  paleta imita la app de escritorio (franja verde arriba, header oscuro, franja
  azul abajo). Mantener esa coherencia visual.
- **Formato chileno**: `clp($v)` formatea pesos (`119990` → `$119.990`).
  `fecha_dmy($iso)` pasa `YYYY-MM-DD` → `DD-MM-YYYY` para mostrar. En la BD las
  fechas se guardan como `DATE` (YYYY-MM-DD) y los montos como `DECIMAL(14,2)`.
- **Archivos de un solo uso**: `setup.php`, `crear_admin*.php`, `migrar.php`,
  `reset_facturas.php` son utilitarios peligrosos protegidos por `setup_key`. El
  usuario debe **borrarlos del servidor** después de usarlos. No dependas de ellos
  en runtime.
- **El usuario no es desarrollador**: explicá decisiones en términos prácticos.
  Cuando un cambio requiera tocar la BD del servidor o subir archivos, dale pasos
  concretos de cPanel, no jerga.

## Qué NO hacer aquí

- No agregar frameworks, Composer, npm ni build steps sin acordarlo (el hosting es
  compartido y el despliegue es manual; hay que mantenerlo simple).
- No cambiar el **contrato de sincronización** (`api/facturas.php`, campos,
  `uuid_local`, soft-delete) sin actualizar también el cliente Python y
  `web/CONTRATO.md`. Romper el contrato deja de sincronizar las facturas.
- No tocar la app de escritorio de Python desde acá; eso vive en `src/` y tiene su
  propio chat/contexto. Acá solo el lado web del contrato.
