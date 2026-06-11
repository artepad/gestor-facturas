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
│   └── esquema.sql      definición de todas las tablas MySQL
│
├── api/
│   └── facturas.php     ENDPOINT de sincronización (POST desde el cliente Python). Ver CONTRATO.md
│
├── home.php             tablero de inicio (tarjetas resumen + accesos rápidos)
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

## Roles y permisos (en `lib/permisos.php` + `lib/auth.php`)

- **Modelo RBAC simple definido en código**: la matriz `PERMISOS` mapea cada rol a
  los **módulos** que puede usar (`facturas`, `fiados`, `ingresos`, `negocios`,
  `usuarios`). Roles actuales: **`admin`** (todos los módulos) y **`vendedor`**
  (`facturas`, `fiados`). `usuarios.rol` es `VARCHAR(20)` (agregar roles no
  requiere `ALTER`). El módulo `ingresos` es solo admin (diferencias de caja y
  rendimiento por vendedor son información del dueño).
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
