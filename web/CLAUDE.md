# CLAUDE.md — Plataforma web (admin.minimark.cl)

Guía para Claude Code cuando trabaje **dentro de la carpeta `web/`**. Este es un
proyecto separado en su intención (el dashboard web), aunque vive en el mismo
repositorio que la app de escritorio de Python. **Lee también `web/docs/CONTRATO.md`**:
ahí está el "puente" que conecta la web con el cliente Python; ninguno de los dos
lados puede romperlo sin coordinar con el otro.

> **Todo `web/` sigue la arquitectura en capas** (Router → Controlador →
> Modelo → Vista, en `app/`): POS, Fiados, Gastos, Sistema, Usuarios,
> Facturas, Negocios, Ingresos y Herramientas migraron pantalla por
> pantalla entre 2026-11-08 y 2026-11-09 (bitácora completa en
> `web/docs/bitacora-migracion.md`). Lo único fuera de este patrón son los
> scripts de infraestructura sin sesión: `api/facturas.php`, `migrar.php` y
> `procesar_cortes.php`.
>
> **Un solo punto de entrada (2026-11-09, Fase 19).** Ya NO hay un archivo
> físico por pantalla en la raíz de `web/` (se borraron los ~45 "archivos
> puente" que hubo durante la migración). `index.php` es el único archivo
> real que queda: el `.htaccess` manda ahí cualquier dirección que no sea
> un archivo real que ya exista, e `index.php` usa un Router
> (`app/Core/Router.php`, con la tabla completa en `app/routes.php`) para
> decidir qué Controlador atiende cada dirección. **Las direcciones NO
> cambiaron** — `admin.minimark.cl/herramienta_caja.php` sigue siendo esa
> misma URL, solo que ya no hay un `herramienta_caja.php` físico: lo
> resuelve el Router. Ver «Arquitectura del POS (capas)» más abajo para el
> patrón de Controlador/Modelo/Vista, y la sección «El Router (punto de
> entrada único)» para cómo agregar o tocar una ruta.

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

Detalle completo (archivos a subir, migraciones, verificación) en `web/docs/DESPLIEGUE.md`.

## Arquitectura de archivos

```
web/
├── index.php            PUNTO DE ENTRADA ÚNICO de todo el panel (Fase 19). El único
│                        archivo real que atiende pantallas en la raíz — crea el Router
│                        y lo despacha según app/routes.php (ver «El Router» más abajo)
├── config.php           ← credenciales MySQL + setup_key. NO se sube a git (gitignored)
├── config.ejemplo.php   plantilla de config.php
├── .htaccess            HTTPS forzado + seguridad + reescribe todo lo que no sea un
│                        archivo real hacia index.php
│
├── lib/
│   ├── db.php           obtener_pdo() (singleton PDO), cargar_config(), responder_json()
│   ├── auth.php         login/sesión + control de acceso por negocio
│   ├── permisos.php     matriz RBAC rol→módulos + exigir_permiso()
│   ├── ui.php           layout compartido (sidebar, footer) + helpers de formato + íconos SVG
│   ├── eleventa.php     parser del correo de corte de Eleventa + registrar_corte()
│   ├── productos.php    carga del catálogo Excel de Eleventa + estado + búsqueda de productos
│   ├── gastos.php       helpers del módulo Gastos (categorías, totales, generar fijos del mes)
│   ├── pos.php          TODA la lógica de negocio del POS: caja, ventas, promociones,
│   │                    recaudación de convenios, inventario y compras — sigue siendo la
│   │                    única fuente de verdad del SQL del POS; los Modelos de app/ son
│   │                    envoltorios sobre estas funciones, no una reescritura (ver abajo)
│   ├── auditoria.php    registrar_auditoria() + cambios_json() (antes/después)
│   └── esquema.sql      definición de todas las tablas MySQL
│
├── api/
│   └── facturas.php     ENDPOINT de sincronización (POST desde el cliente Python). Ver
│                        docs/CONTRATO.md. Fuera del Router a propósito (ver más abajo)
│
├── app/
│   ├── bootstrap.php      arranque: autoload, errores, sesión
│   ├── routes.php         TABLA DE RUTAS completa: qué dirección va a qué Controlador
│   │                      (ver «El Router» más abajo)
│   ├── Core/              Config, Database, Request, Router, Session, View, helpers.php
│   ├── Controllers/       un Controlador por pantalla migrada, o por grupo de pantallas
│   │                      relacionadas (POS + Fiados + Gastos + Sistema + Usuarios +
│   │                      Facturas + Negocios + Ingresos + Herramientas — esta última con
│   │                      5: Herramientas, Productos, Etiquetas, Ofertas, Catalogo)
│   ├── Models/            envoltorios sobre lib/pos.php (CajaSesion, Venta, Promocion, Convenio,
│   │                      Inventario, Compra) + PrecioFV, Cliente, Home, Usuario, Factura,
│   │                      Negocio y Producto (SQL propio, salvo Producto que además envuelve
│   │                      lib/productos.php) + Gasto y Corte (mixtos: envuelven
│   │                      lib/gastos.php y lib/eleventa.php respectivamente para lo que
│   │                      ya existía ahí, SQL propio para el resto — ver «Arquitectura
│   │                      del POS» más abajo)
│   └── views/             una vista PHP por pantalla (caja/, pos/, inventario/, compras/,
│                          promociones/, servicios/, precios_fv/imprimir.php, fiados/,
│                          gastos/, auth/, home/, usuarios/, facturas/, negocios/, ingresos/,
│                          herramientas/, productos/, etiquetas/, ofertas/, catalogo/).
│                          ver_pdf.php no tiene vista (responde el PDF directo)
├── public/
│   └── index.php          diagnóstico del esqueleto (autoload+BD+sesión), un entorno
│                          aislado del panel real. No atiende ninguna pantalla
│
├── sw.js                service worker: la caja abre y vende sin internet
├── manifest.webmanifest hace la caja instalable como aplicación
│
├── uploads/
│   └── precios_fv/        fotos de Frutas y Verduras (WebP, nombre aleatorio). Servido
│                          directo pero sin poder ejecutar nada (uploads/.htaccess)
│
├── documentos/           todo lo que no es código: facturas sincronizadas + sus respaldos
│                        (Fase 21 de la migración). Una sola regla en su .htaccess
│                        (Require all denied) protege las dos subcarpetas de adentro
│   ├── facturas/          PDFs sincronizados, en {slug del negocio}/{año}/{mes}/{uuid}.pdf
│   │                      — el año/mes son los de la FECHA DE LA FACTURA, no de cuándo
│   │                      se sincronizó (api/facturas.php arma la ruta; gitignored salvo
│   │                      .gitkeep)
│   └── respaldos/         zips de respaldo de documentos/facturas/ (scripts/respaldo_pdfs.php,
│                          gitignored salvo .gitkeep)
├── logs/                log de errores de PHP (app/bootstrap.php y lib/db.php escriben acá:
│                        logs/app.log, gitignored). Bloqueado por .htaccess
│
├── docs/                 toda la documentación que no es código (nada de esto se ejecuta ni
│   │                     se sirve por la web — FilesMatch "\.md$" en .htaccess las bloquea
│   │                     igual). Solo 6 documentos a propósito (ver «Documentación» abajo):
│   ├── bitacora-migracion.md       registro fase por fase de la migración a capas — el
│   │                               "cómo y por qué" se llegó a la arquitectura actual
│   ├── CONTRATO.md                 formato exacto de sincronización con la app de Python (ver también
│   │                               la raíz del repo, CLAUDE.md, y lib/auth.php/pos.php que la referencian)
│   ├── DESPLIEGUE.md                guía de despliegue manual por cPanel (única fuente de
│   │                               verdad operativa; MIGRACION_POS.md la referencia, no la repite)
│   ├── POS_DISENO.md                diseño y decisiones de negocio del módulo Ventas/POS — leer
│   │                               antes de tocar nada ahí (la arquitectura técnica vigente
│   │                               está en CLAUDE.md, no acá, para no tener dos fuentes)
│   ├── INSTALAR_POS.md             instalación del POS en un PC (modo kiosco incluido)
│   └── MIGRACION_POS.md            guía para migrar un negocio desde Eleventa al POS propio
│
├── scripts/              utilidades de consola (guardadas con PHP_SAPI !== 'cli')
│   ├── smoke.php                   prueba de humo: recorre las rutas clave y compara
│   └── respaldo_pdfs.php           respalda documentos/facturas/ a documentos/respaldos/ (cron diario)
│
├── procesar_cortes.php  worker del cron: lee la casilla IMAP + procesa pendientes (CLI o
│                        ?clave=setup_key). Fuera del Router a propósito: no es una
│                        pantalla de sesión, no renderiza nada (solo texto plano) — mismo
│                        criterio que api/facturas.php o migrar.php. Sigue llamando
│                        lib/eleventa.php directo, igual que antes
│
└── migrar.php            único utilitario que queda en el servidor — aplica cambios de
                          esquema a una BD existente. A diferencia de los demás (todos ya
                          borrados, del repo y del servidor — 2026-11-08: setup.php,
                          crear_admin.php, crear_admin_form.php, reset_facturas.php), SÍ
                          conviene dejarlo: es idempotente (solo aplica lo que falta) y se
                          vuelve a necesitar cada vez que el esquema cambia. Fuera del
                          Router, igual que procesar_cortes.php
```

**Dónde quedó cada pantalla** (todas corren a través de `index.php` + el Router — ver
«El Router» abajo — ninguna tiene ya un archivo físico propio):

| Dirección (URL) | Controlador → método |
|---|---|
| `/` | redirige a `home.php` o `login.php` según haya sesión |
| `login.php` / `logout.php` | `AuthController::login` / `logout` |
| `home.php` | `HomeController::index` |
| `ver_pdf.php` | `VerPdfController::index` |
| `usuarios.php` | `UsuariosController::index` |
| `panel_facturas.php` / `factura.php` | `FacturasController::panel` / `detalle` |
| `negocios.php` / `negocio_form.php` / `negocio_tokens.php` / `negocio_panel.php` | `NegociosController::index` / `formulario` / `tokens` / `panel` |
| `fiados.php` / `cliente_form.php` / `cliente.php` / `estado_cuenta_imprimir.php` | `FiadosController::listado` / `formulario` / `ficha` / `estadoCuenta` |
| `gastos.php` / `gasto_form.php` / `categorias_gasto.php` / `gastos_fijos.php` | `GastosController::panel` / `formulario` / `categorias` / `fijos` |
| `ingresos.php` / `corte.php` / `corte_pegar.php` | `IngresosController::panel` / `detalle` / `pegar` |
| `herramientas.php` / `herramienta_caja.php` | `HerramientasController::tablero` / `caja` |
| `herramienta_productos.php` / `producto_form.php` / `historial_productos.php` | `ProductosController::panel` / `formulario` / `historial` |
| `herramienta_etiquetas.php` / `etiquetas_imprimir.php` / `productos_buscar.php` | `EtiquetasController::formulario` / `imprimir` / `buscarJson` |
| `herramienta_ofertas.php` / `ofertas_imprimir.php` | `OfertasController::formulario` / `imprimir` |
| `herramienta_catalogo.php` / `catalogo_buscar.php` | `CatalogoController::index` / `buscarJson` |
| `pos.php` / `pos_api.php` | `PosController::index` / `PosApiController::index` |
| `caja.php` | `CajaController` — único caso especial: la ruta es una función que elige `abrir`/`movimiento`/`cerrar`/`index` según el `accion` del POST, igual que antes lo elegía el archivo puente |
| `inventario.php` / `compras.php` / `promociones.php` / `servicios.php` | `InventarioController` / `ComprasController` / `PromocionesController` / `ServiciosController::index` |
| `precios_fv.php` / `precios_fv_imprimir.php` | `PreciosFvController::index` / `imprimir` |

Lista exacta y siempre actualizada: `app/routes.php`.

## Arquitectura del POS (capas)

**Todo** `web/` sigue el patrón Router → Controlador → Modelo → Vista
descrito en esta sección y en «El Router (punto de entrada único)» más
abajo, salvo los scripts de infraestructura sin sesión
(`api/facturas.php`, `migrar.php`, `procesar_cortes.php` — ver el banner
del principio de este archivo). El nombre de la sección quedó de cuando
solo era el POS; el patrón que describe aplica igual a cualquier
pantalla. El cómo y por qué se llegó acá, fase por fase, está en
`web/docs/bitacora-migracion.md`.

**Una visita a `pos.php` (o cualquiera de las otras):**
el navegador pide `pos.php` (misma dirección de siempre) → el `.htaccess` ve
que no hay un archivo real con ese nombre y manda todo a `index.php` →
`index.php` arma el Router, carga `app/routes.php` (ahí está registrado que
`/pos.php` va a `PosController::index`) y lo despacha → `index()` lee la
sesión/permiso, llama a los Modelos, elige la Vista →
`View::render('pos/index', [...])` → HTML con `h()`/`e()` en cada salida.
Ver «El Router (punto de entrada único)» más abajo para el detalle completo.

- **Ya no hay un archivo físico por pantalla** (hasta el 2026-11-09 cada
  pantalla tenía su "archivo puente" de 10-20 líneas en la raíz de `web/`,
  hoy borrados — ver Fase 19 en el plan). Si algo se ve raro en una
  pantalla, el primer lugar para mirar es su entrada en `app/routes.php`
  (¿está registrada? ¿apunta al método correcto?) y después el Controlador.
  `caja.php` es la única ruta "especial": en vez de `[Controlador, 'metodo']`
  registra una función que hace el mismo `match()` de un `accion` del
  `$_POST` que antes hacía el archivo puente, porque `CajaController` separa
  `abrir()`/`movimiento()`/`cerrar()` en vez de una sola función.
- **Los Modelos son envoltorios sobre `lib/pos.php`, no una reescritura**
  (decisión tomada en el corte de Caja, Fase 6): `App\Models\Venta::registrar()`
  por dentro llama a `\registrar_venta()` de `lib/pos.php`. Esto fue intencional
  para no mover 1.500 líneas de golpe; significa que **el SQL real del POS sigue
  viviendo en `lib/pos.php`** — para entender o cambiar una regla de negocio del
  POS (cómo se calcula el vuelto, cómo se aplica una promoción, cómo se liquida
  un convenio), hay que mirar ahí, no en `app/Models/`. **Excepciones:**
  `App\Models\PrecioFV` (Frutas y Verduras) y `App\Models\Cliente` (Fiados)
  tienen su SQL propio — no envuelven nada de `lib/pos.php` porque no existía
  código viejo de POS para esas pantallas (Frutas y Verduras venía de su
  propio módulo, reescrito desde cero a pedido del dueño; Fiados nunca tuvo
  equivalente en el POS). `App\Models\Gasto` es **mixto**: envuelve
  `lib/gastos.php` para lo que ya existía ahí (categorías, totales/desglose
  del período, generación de gastos fijos — `home.php` y `negocio_panel.php`
  usan esas mismas funciones, uno directo y el otro a través del Modelo, así
  que `lib/gastos.php` NO se tocó), y usa SQL propio para el CRUD de
  gastos/categorías/plantillas que antes vivía suelto dentro de cada
  pantalla. `App\Models\Home`, `App\Models\Usuario`, `App\Models\Factura` y
  `App\Models\Negocio` también son SQL propio — no hay nada en `lib/` que
  envolver para el tablero de inicio, la gestión de usuarios, las facturas
  (`Factura` nació chico, solo con `paraPdf()` para `ver_pdf.php`, y se
  completó al migrar el módulo Facturas — ver Fase 15 en el plan) ni los
  negocios. `Negocio` reutiliza `Home::ventasPorDia()`/`comprasPorNegocio()`
  y `Gasto::totalPeriodo()`/`porCategoria()` en vez de duplicar esas
  consultas para `negocio_panel.php`. `App\Models\Corte` (Ingresos) es
  **mixto**, mismo criterio que `Gasto`: envuelve `lib/eleventa.php` solo
  para `procesar_correo_corte()` (el parser del correo de Eleventa — NO se
  toca, lo sigue usando también `procesar_cortes.php`, que quedó fuera de
  esta migración), y SQL propio para todo lo demás (el dashboard, el
  detalle de un corte, el registro manual).
- **CSRF** (`campo_csrf()` en cada formulario, `Session::csrfValido()` en cada
  POST) existe **solo en las pantallas migradas que tienen formularios que
  escriben en la base de datos** (POS + Fiados + Gastos + Usuarios +
  Negocios + Ingresos + Herramientas, salvo `negocio_panel.php` que es de
  solo lectura) — es el hallazgo H3 de la auditoría, cerrado pantalla por
  pantalla durante la migración original y extendido módulo por módulo
  después. La mayoría lo valida vía `BaseController::verificarCsrf()`;
  `pos_api.php` y `precios_fv.php` no extienden `BaseController` (responden
  JSON/multipart, no redirigen) y llaman `Session::csrfValido()` directo.
  **Las pantallas de solo lectura no llevan CSRF**
  (`login.php`/`logout.php`/`ver_pdf.php`/`panel_facturas.php`/`factura.php`/
  `negocio_panel.php`/`corte.php`/`herramienta_catalogo.php`: nada que
  proteger, no hay formularios con estado — login no tenía CSRF en el
  código viejo tampoco, por decisión consciente: un login forzado por CSRF
  no le sirve a un atacante sin la contraseña). **Tampoco lo llevan
  `etiquetas_imprimir.php`/`ofertas_imprimir.php`**: reciben un POST pero no
  persisten nada en la base de datos, solo arman una página para imprimir o
  guardar como PDF — mismo criterio que `precios_fv_imprimir.php`.
  `AuthController` no extiende `BaseController`;
  `VerPdfController`/`FacturasController` lo extienden solo por
  `exigirPermiso()`; `NegociosController::panel()` usa
  `BaseController::exigirSesion()` (como `HomeController`) porque cualquier
  rol con acceso a ese negocio puede entrar, no solo admin.
- **`app/views/pos/index.php` y `app/views/pos_api` no existen como tal** —
  `pos_api.php` no tiene Vista (responde JSON); `precios_fv.php` tampoco
  (responde JSON), salvo `precios_fv_imprimir.php` que sí (`precios_fv/imprimir.php`).
  El resto de las vistas usan `cabecera_dashboard()`/`pie_dashboard()` de
  `lib/ui.php`, igual que cualquier página del panel viejo; `pos.php` NO
  (pantalla "de trabajo" en modo kiosco, sin sidebar — ver más abajo). El
  popup de Frutas y Verduras vive adentro de `app/views/pos/index.php`, no en
  una vista propia: es parte de la pantalla de Venta, no una pantalla aparte.
- **`public/index.php` ya no es una vista previa de nada**: durante la migración
  (Fases 4-8) sirvió para probar cada pantalla nueva en paralelo bajo
  `/public/...` sin tocar lo que atendía producción. Hoy es un diagnóstico
  aislado (confirma que el autoload/BD/sesión arrancan bien) — no tiene
  relación con `index.php` de la raíz, que es el punto de entrada real
  desde la Fase 19.

**Para agregar una acción nueva a una pantalla ya migrada**: agregar el método
al Modelo (envolviendo una función de `lib/pos.php`, `lib/gastos.php`,
`lib/eleventa.php` o `lib/productos.php` si ya existe ahí, o SQL propio si
no), el método o rama `accion` al Controlador, y el formulario con
`campo_csrf()` a la Vista — **la ruta ya existe** si la pantalla ya existía,
no hace falta tocar `app/routes.php`. **La migración a capas terminó**
(2026-11-09): todo `web/` sigue este patrón salvo los scripts de
infraestructura sin sesión (`api/facturas.php`, `migrar.php`,
`procesar_cortes.php`). Bitácora completa en `web/docs/bitacora-migracion.md`.

## El Router (punto de entrada único)

Desde la Fase 19 (2026-11-09) ya no hay un archivo real por pantalla en la
raíz de `web/`. En su lugar:

1. **`.htaccess`** (al final del archivo) reescribe cualquier dirección que
   NO sea un archivo o carpeta real existente hacia `index.php`, sin
   cambiar la URL que ve el navegador (`RewriteCond %{REQUEST_FILENAME}
   !-f` / `!-d`, sin flag `[R]`). Los archivos reales (CSS, imágenes,
   `uploads/`, `api/facturas.php`, `migrar.php`, `procesar_cortes.php`,
   `config.php`) se siguen sirviendo directo, nunca pasan por acá.
2. **`index.php`** (la raíz) crea un `App\Core\Router`, incluye
   `app/routes.php` (que registra ahí todas las direcciones) y despacha
   según `Request::metodo()` + `Request::ruta()`. Si ninguna ruta hace
   match, responde 404 de verdad.
3. **`App\Core\Request::ruta()`** calcula la dirección a matchear
   descontando sola la carpeta donde vive `index.php` (vía
   `dirname($_SERVER['SCRIPT_NAME'])`): da lo mismo si el sitio vive en la
   raíz del dominio (producción) o en una subcarpeta como
   `http://localhost/minimark/` (XAMPP local) — no hay que configurar nada
   al desplegar.
4. **`App\Core\Router`** solo entiende rutas estáticas (`/gastos.php`) y con
   parámetros (`/cliente/{id}`, sin usar hoy pero soportado); escapa los
   tramos literales con `preg_quote` para que, por ejemplo, el `.` de
   `.php` no matchee cualquier carácter.
5. **`app/routes.php`** es la tabla completa, agrupada por módulo. Cada
   línea es `$ambos('/direccion.php', [Controller::class, 'metodo']);` —
   `$ambos()` registra la misma ruta en GET y POST porque los archivos
   puente de antes respondían a cualquier método (cada Controlador decide
   adentro qué hacer según `Request::metodo()`, eso no cambió). La única
   ruta que no es `[Controlador, 'metodo']` es `/caja.php`, que registra una
   función (ver arriba).

**Para agregar una pantalla nueva** (no una acción a una ya existente, ver
el párrafo de arriba): crear el Controlador/Modelo/Vista como siempre, y
agregar su línea en `app/routes.php` con `$ambos()`. No hace falta tocar el
`.htaccess` ni `index.php`.

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
  vía `eliminada_en` (no se borra físicamente). Ver docs/CONTRATO.md para los campos.
- **`detalle_factura`** — líneas de producto de una factura (relación con
  `ON DELETE CASCADE`). Se reemplaza completo en cada sync.
- **`sync_log`** — bitácora de cada operación de sincronización (auditoría).

**Módulo Fiados** (web-nativo, NO participa de la sincronización; la web es la
fuente de verdad). Primer módulo fuera del POS migrado a capas (2026-11-08):
`fiados.php`/`cliente.php`/`cliente_form.php`/`estado_cuenta_imprimir.php` son
puentes a `App\Controllers\FiadosController` (métodos `listado()`, `ficha()`,
`formulario()`, `estadoCuenta()`), con `App\Models\Cliente` (SQL propio, mismo
criterio que `PrecioFV` — ver «Arquitectura del POS» más arriba) y las vistas
en `app/views/fiados/`:
- **`clientes`** — clientes a crédito de cada negocio (`negocio_id`, `nombre`,
  `apellido`, `telefono`, `direccion`, `correo`, `activo`). `negocio_id` vive solo
  aquí; fiados/abonos llegan al negocio a través del cliente.
- **`fiados`** — cargos a crédito que aumentan la deuda (`cliente_id`, `fecha`,
  `monto`, `descripcion`).
- **`abonos`** — pagos que disminuyen la deuda (`cliente_id`, `fecha`, `monto`,
  `nota`). **Cuenta corriente**: saldo del cliente = Σ`fiados.monto` − Σ`abonos.monto`
  (calculado, no almacenado). Acceso por permiso de módulo `fiados`, acotado por
  `negocios_visibles()`.
- **Registrar vs. corregir (regla de rol)**: en `cliente.php` el **vendedor solo
  puede crear** fiados y abonos. **Editar/eliminar** fiados o abonos y **eliminar
  clientes** son **solo admin** (`$esAdmin = rol === 'admin'`): se ocultan en la UI
  y se bloquean en el backend (los enlaces GET de edición y los POST admin-only
  redirigen si no es admin). Un vendedor que se equivoca le pide al admin que corrija.
- **`estado_cuenta_imprimir.php`** — estado de cuenta del cliente en PDF (página
  "desnuda" imprimible, patrón `*_imprimir.php`): una tabla cronológica de
  movimientos con saldo acumulado + resumen (total compras / abonos / saldo). Se
  lanza desde `cliente.php` con períodos Semana / Mes actual / Historial / rango.

**Auditoría** (`lib/auditoria.php` + tabla `auditoria`): rastro simple de acciones
sensibles. `registrar_auditoria($pdo, $usuario, $modulo, $accion, $clienteId,
$descripcion, $detalle?)` guarda un *snapshot* del usuario (`usuario_nombre`, `rol`
además del `usuario_id` con FK SET NULL) para que el registro siga siendo legible si
el usuario se elimina; `cliente_id` es un entero suelto (sin FK) para que la fila
sobreviva al borrado del cliente. Nunca rompe la operación principal (si el INSERT
falla, va al `error_log` y sigue). Hoy lo usan `cliente.php`/`cliente_form.php` para
todas las acciones del módulo Fiados (crear/editar/eliminar fiado, abono y cliente).
Sin página de consulta todavía (se puede agregar una vista admin a futuro).

**Módulo Ingresos** (web-nativo; los datos llegan por el correo de corte de
Eleventa, NO por la sincronización de facturas). Séptimo módulo fuera del
POS migrado a capas (2026-11-09): `ingresos.php`/`corte.php`/
`corte_pegar.php` son puentes a `App\Controllers\IngresosController`
(métodos `panel()`, `detalle()`, `pegar()`), con `App\Models\Corte` y las
vistas en `app/views/ingresos/`. `procesar_cortes.php` (el worker de
cron/IMAP, más abajo) quedó fuera, a propósito — ver su entrada en el árbol
de archivos más arriba.
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
`window.print()`. Último módulo migrado a capas (Fase 18, 2026-11-09): 5
Controladores (`HerramientasController`, `ProductosController`,
`EtiquetasController`, `OfertasController`, `CatalogoController`) + un único
Modelo, `App\Models\Producto` (envuelve `lib/productos.php` para el parseo
del Excel/estado/búsquedas, SQL propio para el CRUD de un producto y el
historial de auditoría). `HerramientasController::tablero()/caja()` y
`OfertasController` no tienen Modelo: no tocan la base de datos (el
contador de caja y la cola de ofertas calculan todo en el navegador).

**Módulo Gastos** (web-nativo; gastos operacionales del negocio: agua, luz, gas,
sueldos, arriendo, etc.). **Solo admin** (información financiera del dueño, igual
que Ingresos). Acotado por `negocios_visibles()`. Segundo módulo fuera del POS
migrado a capas (2026-11-09): `gastos.php`/`gasto_form.php`/
`categorias_gasto.php`/`gastos_fijos.php` son puentes a
`App\Controllers\GastosController` (métodos `panel()`, `formulario()`,
`categorias()`, `fijos()`), con `App\Models\Gasto` y las vistas en
`app/views/gastos/`. `lib/gastos.php` sigue existiendo y NO se tocó: todo lo
que ya lo usaba (`home.php`, `negocio_panel.php`) sigue llegando a esas
mismas funciones, ahora a través de `App\Models\Gasto` (que las envuelve) en
vez de llamarlas directo, desde que esas dos pantallas también se migraron
(Fases 13 y 16 — ver «Arquitectura del POS» más arriba, sección de Modelos).
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
  mes" en `gastos.php` llama `App\Models\Gasto::generarFijosDelMes()`
  (envoltorio de `registrar_gastos_fijos()`), que inserta un `gasto` por
  plantilla activa que aún no tenga uno este mes (idempotente: no duplica).
  **Nota de UX conservada del código viejo**: esta acción NO redirige — pinta
  el mensaje de resultado en la misma respuesta (200), a diferencia de las
  demás acciones del Controlador que sí redirigen tras guardar.

El **Home** muestra "Gastos del mes" (reemplazó a "Por cobrar") y suma los gastos en
la grilla "Estado por negocio". El dashboard de Gastos compara ventas (de `cortes`)
vs. gastos del período (balance).

**Módulo Frutas y Verduras** (web-nativo; lista de precios **compartida** entre
todos los negocios — la compra la hace una sola persona para ambos). **Se mudó
adentro de la pantalla de Venta** (2026-11-08): ya no es su propio ítem de
menú — es el botón "Frutas y verduras" en `pos.php`, junto a Caja/Salir, que
abre un popup (`App\Controllers\PreciosFvController` vía `precios_fv.php`,
JSON). Por eso gatilla con el permiso `'ventas'` (no uno propio: el módulo
`'precios'` de antes se retiró). Editan y consultan admin y vendedoras, igual
que antes. A diferencia del resto de los Modelos del POS, `App\Models\PrecioFV`
**no envuelve `lib/pos.php`**: se reescribió desde cero (decisión del dueño,
no existía código viejo que mereciera conservarse).
- **`precios_fv`** — `nombre`, `precio DECIMAL(14,2)`, `unidad` (kilo/unidad/
  bandeja…), `imagen` (ruta relativa en `uploads/precios_fv/`, opcional),
  `observacion`, `activo`, `actualizado_por` (FK→usuarios), `actualizado_en
  TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP` (fecha vigente automática,
  mostrada junto al nombre de quién hizo el último cambio). **Sin
  `negocio_id`**: es una sola lista para todos (como `categorias_gasto`).
- **Fotos**: subida opcional al crear/editar. Nunca se confía en lo que diga
  el navegador — mismo criterio que el PDF de facturas (Fase 8): se valida el
  tipo real con `getimagesize()`, se vuelve a generar con GD (lo que haya
  escondido el archivo no sobrevive), se reduce si pesa más de 1000px de
  ancho, se guarda como WebP con nombre aleatorio. Tope 5 MB. Al reemplazar o
  quitar una foto, el archivo viejo se borra del disco.
- **Imprimir**: `precios_fv_imprimir.php` arma una hoja A4 con la lista
  completa (para pegar en el local), no etiquetas individuales — esas ya
  existen en Herramientas → Etiquetas, son cosas distintas.

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

**Módulo Ventas / POS** (web-nativo; reemplaza a Eleventa). **El diseño completo,
las decisiones y el plan por etapas están en `web/docs/POS_DISENO.md`: leerlo antes de
tocar nada del POS.** Guías operativas: `docs/INSTALAR_POS.md` (instalación en un PC,
incluido el modo kiosco) y `docs/MIGRACION_POS.md` (el cambio desde Eleventa).

- **`pos.php`** es una pantalla "de trabajo": documento propio, sin el layout del
  dashboard, pensada para operarse **solo con el teclado** (el flujo real del
  usuario en Eleventa era `escanear → F12 → F2`). Atajos: `Ctrl+P` producto común,
  `F10` buscar, `F7`/`F8` efectivo, `F9` pago de cuenta, `Supr` borrar línea,
  `F6` pendiente, `F12` (o `+` del numérico) cobrar. `F12` y `F1` los reserva el
  navegador: por eso la caja se abre en **modo kiosco**.
- **`ventas` / `venta_items` / `venta_pagos`** — una venta con sus líneas y sus
  pagos. Varias filas en `venta_pagos` = **pago mixto**. `uuid` es la clave de
  idempotencia (mismo patrón que `facturas.uuid_local`) y el `folio` correlativo
  **lo asigna el servidor**, no la caja.
- **`ventas_espera`** — tickets aparcados (F6). No son ventas: no tienen folio ni
  aparecen en reportes. Bloquean el cierre de caja.
- **`caja_sesiones` / `caja_movimientos`** — el turno y el efectivo que entra o
  sale. **Un turno abierto por usuario**: dos cajas simultáneas son dos personas.
- **Al cerrar caja se genera un `corte`** reutilizando `registrar_corte()` de
  `lib/eleventa.php`, así el módulo Ingresos y los reportes siguen igual pero con
  datos propios. `cortes` ganó `recaudado_efectivo` y `diferencia` (los cortes que
  llegan por correo las dejan NULL).
- **`promociones`** — ofertas que el POS aplica **solo** al vender: `pack`
  ("4 x $1.000"), `nxm`, `precio` y `porcentaje`, a un producto o a un
  departamento. El navegador las calcula para mostrarlas al instante, pero **el
  servidor las recalcula al cobrar** e ignora lo que mande la caja.
- **Anular** solo se puede en una caja **todavía abierta**: cerrada, su corte ya
  está en Ingresos y anular reescribiría historia (además de ser la vía clásica de
  robo hormiga). Anular revierte el fiado y devuelve el stock.

**Recaudación de convenios** (`convenios`, `servicio_operaciones`) — **crítico para
que el balance sea real** (ver docs/POS_DISENO.md 7.7): la plata que el cliente paga por
su cuenta de luz **NO es venta** (es de la empresa de servicios) y recargar saldo
**NO es un gasto** (es plata propia cambiando de bolsillo). El único ingreso real
es la **comisión**. El efectivo sí entra al cajón, así que el arqueo lo suma: sin
eso mostraría un sobrante todos los días. Soporta convenios **prepago** (Mercado
Pago, con saldo que se descuenta — el negocio dejó Klap, ver `servicios.php`) y
**postpago** (deuda que se liquida). El nombre del convenio es libre (lo crea el
usuario en Servicios): no hay ninguna integración técnica real con ningún
proveedor, el sistema es genérico desde su diseño.

**Catálogo compartido** — `productos` **no tiene `negocio_id`**: es un solo catálogo
para los dos negocios (igual que `precios_fv`). Agregar un producto o cambiar un
precio se refleja en ambos. **El stock NO se comparte**: vive en `producto_stock`,
una fila por producto y negocio. La carga de Excel hace **upsert** (jamás borra) y
basta subirla una vez.

**Inventario opcional** (`negocios.control_stock`, `inventario_movimientos`) — nace
**apagado** en los dos negocios y el POS entonces ni menciona el stock. Encendido,
las ventas descuentan y las compras suman, con el kardex explicando cada cambio.
**Nunca se bloquea una venta por falta de stock**: si el producto está en la mano se
vende, y el saldo negativo se marca para revisar.

**Sin internet** (Etapa 3) — la caja guarda el catálogo en el PC (IndexedDB), así
que escanear es instantáneo y funciona sin conexión. Al cobrar sin internet la venta
va a una **cola local** y sube sola al reconectar; el `uuid` impide duplicados. El
carrito se guarda en cada cambio, así que sobrevive a un corte de luz. El **PIN**
(`usuarios.pin_hash`) desbloquea la caja localmente: es un cerrojo de terminal, **no
seguridad fuerte** — está anotado así en `huella_pin()`.

## Roles y permisos (en `lib/permisos.php` + `lib/auth.php`)

- **Modelo RBAC simple definido en código**: la matriz `PERMISOS` mapea cada rol a
  los **módulos** que puede usar (`facturas`, `fiados`, `ingresos`, `gastos`,
  `productos`, `ventas`, `herramientas`, `negocios`, `usuarios`). Roles
  actuales: **`admin`** (todos los módulos) y **`vendedor`** (`ventas`, `facturas`,
  `fiados`, `productos`, `herramientas`).
  **`ventas` se habilitó para vendedoras el 2026-11-08** (antes era solo admin,
  mientras el POS se construía y probaba sin que las vendedoras vieran nada —
  ver `docs/MIGRACION_POS.md` paso 5. Ya se probó en producción, así que se
  habilitó). Da acceso a `pos.php` y `caja.php`: las vendedoras ya pueden
  vender y abrir/cerrar su propio turno.
  **`productos`** (crear/editar catálogo y promociones) lo tienen ambos por decisión
  del usuario: es más ágil y todo cambio queda auditado y consultable. `usuarios.rol` es `VARCHAR(20)` (agregar roles no requiere
  `ALTER`). Los módulos `ingresos` y `gastos` son solo admin (información financiera
  del dueño). El módulo `precios` (Frutas y Verduras) ya no existe: esa pantalla
  vive dentro de `ventas` (ver «Arquitectura del POS»).
- **Agregar un rol** = una fila en `PERMISOS` + etiqueta en `ROLES`. **Agregar un
  módulo** = su clave en la matriz + `exigir_permiso('modulo')` en la página + ítem
  en el menú de `lib/ui.php`. Todo el control vive en un solo lugar.
- Cada página protegida llama **`exigir_permiso('modulo')`** (en `permisos.php`,
  directo en el código viejo o vía `BaseController::exigirPermiso()` en las
  pantallas migradas — por dentro es la misma función): `exigir_login()` +
  chequea la matriz; si no tiene el permiso redirige a `home.php`.
  `exigir_admin()` queda para chequeos puntuales "solo admin".
- **Alcance por negocio** se mantiene: `negocios_visibles($usuario)` (admin = todos;
  cualquier otro rol = asignados en `usuario_negocio`) y `puede_ver_negocio()`.
  Toda consulta de facturas/fiados filtra por los negocios visibles, y
  `ver_pdf.php`/`factura.php`/`cliente.php` validan `puede_ver_negocio()`. **Nunca**
  sirvas datos de un negocio que el usuario no puede ver.
- `usuarios.php` solo admin (`exigir_permiso('usuarios')`); incluye salvaguarda de
  **no dejar el sistema sin ningún administrador** (al cambiar rol o eliminar).
  Migrado a capas (2026-11-09): `App\Controllers\UsuariosController` +
  `App\Models\Usuario`, SQL propio (sin equivalente previo en `lib/`).

## Convenciones importantes

- **Sesión de 30 días**: la caja de un almacén no puede pedir contraseña cada
  mañana, y menos sin internet. Por eso el cerrojo del terminal es el **PIN**, no
  el cierre de sesión.
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
- **Archivos de un solo uso**: ya no queda ninguno en el repo ni en el servidor
  salvo `migrar.php` (pensado para reusarse, es idempotente). `setup.php`,
  `crear_admin.php`, `crear_admin_form.php` y `reset_facturas.php` ya se
  borraron (2026-11-08). **Ojo**: sin `crear_admin_form.php` ya no hay forma de
  crear/resetear un admin sin tener sesión iniciada — si algún día hace falta
  (ej. se pierde el acceso a todos los admins), se recupera el archivo del
  historial de git.
- **El usuario no es desarrollador**: explicá decisiones en términos prácticos.
  Cuando un cambio requiera tocar la BD del servidor o subir archivos, dale pasos
  concretos de cPanel, no jerga.

## Qué NO hacer aquí

- No agregar frameworks, Composer, npm ni build steps sin acordarlo (el hosting es
  compartido y el despliegue es manual; hay que mantenerlo simple).
- No cambiar el **contrato de sincronización** (`api/facturas.php`, campos,
  `uuid_local`, soft-delete) sin actualizar también el cliente Python y
  `web/docs/CONTRATO.md`. Romper el contrato deja de sincronizar las facturas.
- No tocar la app de escritorio de Python desde acá; eso vive en `src/` y tiene su
  propio chat/contexto. Acá solo el lado web del contrato.
