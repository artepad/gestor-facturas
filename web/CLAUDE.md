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
│   ├── ui.php           layout compartido (sidebar, footer) + helpers de formato + íconos SVG
│   └── esquema.sql      definición de todas las tablas MySQL
│
├── api/
│   └── facturas.php     ENDPOINT de sincronización (POST desde el cliente Python). Ver CONTRATO.md
│
├── home.php             tablero de inicio (tarjetas resumen + accesos rápidos)
├── panel.php            listado de facturas + filtros (la página principal de consulta)
├── ver_pdf.php          sirve el PDF de una factura con control de acceso por negocio
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

## Reglas de acceso (en `lib/auth.php`)

- **`exigir_login()`** al inicio de cada página protegida.
- **`admin`** ve y administra todo. **`sucursal`** ve solo los negocios asignados
  en `usuario_negocio`.
- **Toda** consulta de facturas DEBE filtrar por `negocios_visibles($usuario)`.
  `panel.php` ya lo hace (incluye `f.negocio_id IN (...)` con los visibles antes
  que cualquier filtro del usuario). `ver_pdf.php` valida `puede_ver_negocio()`
  antes de servir el archivo. **Nunca** sirvas datos o PDFs de un negocio que el
  usuario no puede ver.
- Las páginas de Administración (`negocios.php`, `usuarios.php`, etc.) son **solo
  admin**: redirigen si el rol no es `admin`.

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
