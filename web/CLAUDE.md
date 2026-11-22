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
> scripts de infraestructura sin sesión: `api/facturas.php` y `migrar.php`.
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
│   ├── productos.php    carga del catálogo Excel de Eleventa + estado + búsqueda de productos
│   ├── gastos.php       helpers del módulo Gastos (categorías, totales)
│   ├── pos.php          TODA la lógica de negocio del POS: caja, ventas, promociones,
│   │                    inventario y compras — sigue siendo la
│   │                    única fuente de verdad del SQL del POS; los Modelos de app/ son
│   │                    envoltorios sobre estas funciones, no una reescritura (ver abajo).
│   │                    También tiene registrar_corte() (venía de lib/eleventa.php,
│   │                    retirado 2026-11-14 — ver módulo Ingresos más abajo)
│   ├── auditoria.php    registrar_auditoria() + cambios_json() (antes/después)
│   ├── factura_ia.php   lectura de cabecera de una factura con IA (Claude), llamada
│   │                    directo por curl/streams desde "Agregar factura" — puerto de
│   │                    src/classifier.py+validacion.py del lado Python (ver «Facturas:
│   │                    subida directa con IA» más abajo)
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
│   │                      5: Herramientas, Productos, Etiquetas, Ofertas, Catalogo) +
│   │                      FacturaIaController (JSON, "Agregar factura": ver más abajo)
│   ├── Models/            envoltorios sobre lib/pos.php (CajaSesion, Venta, Promocion,
│   │                      Inventario, Compra) + PrecioFV, Cliente, Home, Usuario, Factura,
│   │                      Negocio y Producto (SQL propio, salvo Producto que además envuelve
│   │                      lib/productos.php) + Gasto (envuelve lib/gastos.php para lo que
│   │                      ya existía ahí, SQL propio para el resto) + Corte (SQL propio;
│   │                      ya no envuelve nada de lib/ — ver «Arquitectura del POS» abajo)
│   └── views/             una vista PHP por pantalla (caja/, pos/, inventario/, compras/,
│                          promociones/, precios_fv/imprimir.php, fiados/,
│                          gastos/, auth/, home/, usuarios/, facturas/, negocios/, ingresos/,
│                          herramientas/, productos/, etiquetas/, ofertas/, catalogo/).
│                          ver_pdf.php no tiene vista (responde el PDF directo)
│   └── partials/           fragmentos sin Controlador propio, incluidos con require
│                          directo desde más de una vista (ver "Contador de Caja" en
│                          «Arquitectura del POS» más abajo — el único que hay hoy)
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
│   ├── facturas/          PDFs sincronizados (+ los subidos por "Agregar factura"), en
│   │                      {slug del negocio}/{año}/{mes}/{uuid}.pdf — el año/mes son los
│   │                      de la FECHA DE LA FACTURA, no de cuándo se sincronizó/subió
│   │                      (api/facturas.php y FacturaIaController arman la ruta igual;
│   │                      gitignored salvo .gitkeep)
│   │   └── _tmp/           PDFs recién subidos a "Agregar factura", mientras la vendedora
│   │                      revisa el pop-up antes de confirmar — FacturaIaController los
│   │                      mueve a su carpeta final al guardar, o los borra solo si pasan
│   │                      24h sin confirmarse (no se versiona, se crea solo)
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
└── migrar.php            único utilitario que queda en el servidor — aplica cambios de
                          esquema a una BD existente. A diferencia de los demás (todos ya
                          borrados, del repo y del servidor — 2026-11-08: setup.php,
                          crear_admin.php, crear_admin_form.php, reset_facturas.php), SÍ
                          conviene dejarlo: es idempotente (solo aplica lo que falta) y se
                          vuelve a necesitar cada vez que el esquema cambia. Fuera del
                          Router, igual que api/facturas.php. (procesar_cortes.php, el
                          worker del cron de Eleventa, se retiró 2026-11-14 — ver módulo
                          Ingresos más abajo)
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
| `factura_ia.php` | `FacturaIaController::index` (JSON: `accion=analizar`\|`guardar` — llamado desde el panel "Agregar factura" de `panel_facturas.php`, sin pantalla propia) |
| `negocios.php` / `negocio_form.php` / `negocio_tokens.php` / `negocio_panel.php` | `NegociosController::index` / `formulario` / `tokens` / `panel` |
| `fiados.php` / `cliente_form.php` / `cliente.php` / `estado_cuenta_imprimir.php` | `FiadosController::listado` / `formulario` / `ficha` / `estadoCuenta` |
| `gastos.php` / `gasto_form.php` / `categorias_gasto.php` | `GastosController::panel` / `formulario` / `categorias` |
| `ingresos.php` / `corte.php` | `IngresosController::panel` / `detalle` |
| `herramientas.php` / `herramienta_caja.php` | `HerramientasController::tablero` / `caja` |
| `productos.php` (menú propio) / `herramienta_productos.php` / `producto_form.php` / `historial_productos.php` / `herramienta_unificar_productos.php` / `producto_exportar.php` | `ProductosController::listado` / `panel` / `formulario` / `historial` / `unificar` / `exportar` |
| `herramienta_etiquetas.php` / `etiquetas_imprimir.php` / `productos_buscar.php` | `EtiquetasController::formulario` / `imprimir` / `buscarJson` |
| `herramienta_ofertas.php` / `ofertas_imprimir.php` | `OfertasController::formulario` / `imprimir` |
| `herramienta_catalogo.php` / `catalogo_buscar.php` | `CatalogoController::index` / `buscarJson` |
| `pos.php` / `pos_api.php` | `PosController::index` / `PosApiController::index` |
| `caja.php` | `CajaController` — único caso especial: la ruta es una función que elige `abrir`/`movimiento`/`cerrar`/`index` según el `accion` del POST, igual que antes lo elegía el archivo puente |
| `inventario.php` / `compras.php` / `promociones.php` | `InventarioController` / `ComprasController` / `PromocionesController` |
| `precios_fv.php` / `precios_fv_imprimir.php` | `PreciosFvController::index` / `imprimir` |

Lista exacta y siempre actualizada: `app/routes.php`.

## Arquitectura del POS (capas)

**Todo** `web/` sigue el patrón Router → Controlador → Modelo → Vista
descrito en esta sección y en «El Router (punto de entrada único)» más
abajo, salvo los scripts de infraestructura sin sesión
(`api/facturas.php`, `migrar.php` — ver el banner del principio de este
archivo). El nombre de la sección quedó de cuando
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
  POS (cómo se calcula el vuelto, cómo se aplica una promoción), hay que mirar
  ahí, no en `app/Models/`. **Excepciones:**
  `App\Models\PrecioFV` (Frutas y Verduras) y `App\Models\Cliente` (Fiados)
  tienen su SQL propio — no envuelven nada de `lib/pos.php` porque no existía
  código viejo de POS para esas pantallas (Frutas y Verduras venía de su
  propio módulo, reescrito desde cero a pedido del dueño; Fiados nunca tuvo
  equivalente en el POS). `App\Models\Gasto` es **mixto**: envuelve
  `lib/gastos.php` para lo que ya existía ahí (categorías, totales/desglose
  del período — `home.php` y `negocio_panel.php` usan esas mismas funciones,
  uno directo y el otro a través del Modelo, así que `lib/gastos.php` NO se
  tocó), y usa SQL propio para el CRUD de gastos/categorías que antes vivía
  suelto dentro de cada pantalla. `App\Models\Home`, `App\Models\Usuario`, `App\Models\Factura` y
  `App\Models\Negocio` también son SQL propio — no hay nada en `lib/` que
  envolver para el tablero de inicio, la gestión de usuarios, las facturas
  (`Factura` nació chico, solo con `paraPdf()` para `ver_pdf.php`, y se
  completó al migrar el módulo Facturas — ver Fase 15 en el plan) ni los
  negocios. `Negocio` reutiliza `Home::ventasPorDia()`/`comprasPorNegocio()`
  y `Gasto::totalPeriodo()`/`porCategoria()` en vez de duplicar esas
  consultas para `negocio_panel.php`. `App\Models\Corte` (Ingresos) es SQL
  propio puro — hasta 2026-11-14 envolvía `lib/eleventa.php` para parsear
  los correos de Eleventa, pero ese flujo se retiró por completo (ver
  módulo Ingresos más abajo); `registrar_corte()`, que antes vivía ahí,
  se movió a `lib/pos.php` porque el único que la llama hoy es el POS.
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
al Modelo (envolviendo una función de `lib/pos.php`, `lib/gastos.php`
o `lib/productos.php` si ya existe ahí, o SQL propio si
no), el método o rama `accion` al Controlador, y el formulario con
`campo_csrf()` a la Vista — **la ruta ya existe** si la pantalla ya existía,
no hace falta tocar `app/routes.php`. **La migración a capas terminó**
(2026-11-09): todo `web/` sigue este patrón salvo los scripts de
infraestructura sin sesión (`api/facturas.php`, `migrar.php`). Bitácora
completa en `web/docs/bitacora-migracion.md`.

## El Router (punto de entrada único)

Desde la Fase 19 (2026-11-09) ya no hay un archivo real por pantalla en la
raíz de `web/`. En su lugar:

1. **`.htaccess`** (al final del archivo) reescribe cualquier dirección que
   NO sea un archivo o carpeta real existente hacia `index.php`, sin
   cambiar la URL que ve el navegador (`RewriteCond %{REQUEST_FILENAME}
   !-f` / `!-d`, sin flag `[R]`). Los archivos reales (CSS, imágenes,
   `uploads/`, `api/facturas.php`, `migrar.php`, `config.php`) se siguen
   sirviendo directo, nunca pasan por acá.
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
- **`facturas`** — el espejo de cada factura sincronizada, más (desde
  2026-11-12) las subidas directo en la web. `uuid_local` (CHAR(36) **UNIQUE**)
  es la clave de idempotencia: para una sincronizada es el mismo UUID que
  generó el PC; para una subida web lo genera la propia web (`Factura::crearDesdeWeb()`),
  no viene de ningún PC. **Soft-delete** vía `eliminada_en` (no se borra
  físicamente). `origen` (`sync` | `web`) distingue el camino de llegada;
  `creada_por` (FK→usuarios, NULL en las sincronizadas) guarda qué usuario la
  subió. Ver docs/CONTRATO.md para los campos de la sincronización, y
  «Facturas: subida directa con IA» más abajo para el camino nuevo.
- **`detalle_factura`** — líneas de producto de una factura (relación con
  `ON DELETE CASCADE`). Se reemplaza completo en cada sync; las facturas
  subidas por web no llenan esta tabla todavía (ver más abajo, "queda para
  una segunda etapa").
- **`sync_log`** — bitácora de cada operación de sincronización (auditoría).

**Facturas: subida directa con IA** (botón "Agregar factura" en
`panel_facturas.php` + `factura_ia.php`, 2026-11-12). Pensado como reemplazo
futuro del programa de escritorio para capturar facturas (ver la discusión
con el dueño, que decidió avanzar así): por ahora **conviven** los dos
caminos — el programa de escritorio sigue sincronizando igual que siempre, y
la web suma esta vía adicional para subir un PDF desde cualquier lado
(celular, otro PC) sin pasar por el escaneo automático.
- **Todo pasa dentro de `panel_facturas.php`, sin navegar a otra pantalla**
  (pedido explícito del dueño: no quiere que agregar una factura signifique
  cambiar de página). El botón "Agregar factura" de la cabecera
  (`.btn-agregar-factura`) despliega un panel propio
  (`#panelAgregarFactura`, con su botón de cerrar) con el selector de negocio
  y la zona de arrastrar/elegir el PDF; al terminar de leer la IA aparece el
  mismo pop-up de verificación de siempre (`.modal-confirmar.modal-confirmar-ancho`)
  encima de la pantalla. Al guardar, `window.location.reload()` refresca el
  listado (conservando los filtros de la URL) — no hace falta una vista
  aparte. Todo esto vive en `app/views/facturas/panel.php`;
  `FacturasController::panel()` solo agrega `$csrfToken` (`Session::tokenCsrf()`,
  mismo criterio que `PosController` con `pos_api.php`) a las variables de
  siempre.
- **`FacturaIaController`** (`factura_ia.php`, POST, JSON — mismo criterio que
  `PosApiController`/`PreciosFvController`: no extiende `BaseController`, cualquier
  falla de sesión/permiso/CSRF responde JSON con su código HTTP) tiene dos
  acciones:
  - `accion=analizar` — sube el PDF a una carpeta temporal
    (`documentos/facturas/_tmp/`, con limpieza propia de archivos de más de
    24h en cada llamada) y le pide a la IA los datos de cabecera. No toca la
    tabla `facturas` todavía.
  - `accion=guardar` — recibe los datos ya revisados/corregidos por la
    vendedora en el pop-up + el token del PDF temporal: vuelve a validar todo
    en el servidor (nunca confía en lo que ya se mostró), mueve el PDF a su
    carpeta final (mismo patrón de `api/facturas.php`:
    `documentos/facturas/{slug}/{año}/{mes}/{uuid}.pdf`, año/mes de la fecha
    de la factura) e inserta la fila con `Factura::crearDesdeWeb()`.
- **`lib/factura_ia.php`** — toda la lógica de IA: arma el PDF como bloque
  `document` nativo de la API de Mensajes de Anthropic (no hace falta
  renderizar páginas a imagen en PHP, a diferencia de `src/extractor.py` en
  el programa de escritorio — Claude lee el PDF completo solo). Usa
  `file_get_contents()` con un contexto HTTP en vez de `ext-curl` (no
  depender de que el hosting tenga curl habilitado; además en este XAMPP
  local Apache no la carga aunque la CLI sí). El prompt, el esquema de la
  herramienta forzada (`registrar_factura`) y la lógica de doble lectura
  enfocada cuando el total sale sospechosamente bajo o la fecha queda en el
  futuro son un **puerto directo** de `src/classifier.py` +
  `src/validacion.py` + `src/main.py` (mismo umbral de confianza 0.75 para
  aceptar la segunda lectura) — mantener los dos lados coherentes si se
  ajusta el prompt en cualquiera de los dos idiomas.
- **Duplicados**: `Factura::buscarDuplicada()` compara folio + RUT del
  emisor dentro del negocio (misma regla que `db.buscar_duplicado` del
  programa de escritorio), pero ahora contra **todas** las facturas del
  negocio en la web, no solo lo escaneado en un PC. No bloquea: solo avisa
  en el pop-up: la vendedora decide si guarda igual (`confirmar_duplicada=1`).
- **Alcance de esta primera versión, a propósito**: solo cabecera
  (proveedor/razón social/RUT/folio/fecha/total/notas). El detalle de
  productos línea por línea queda para una segunda etapa — es la parte menos
  confiable de la IA, por eso en el programa de escritorio también es un paso
  manual aparte (`AnalizadorProductos`). Tampoco hay todavía "aprendizaje" de
  instrucciones por proveedor ni de alias de marca: la vendedora revisa cada
  dato igual, así que no hace falta de entrada.
- **Config**: clave nueva `anthropic` en `config.php` (`api_key` + `modelo`,
  mismo modelo que usa el programa de escritorio) — ver `config.ejemplo.php`.

**Editar y eliminar una factura** (botones discretos junto a "Ver PDF" en
`factura.php`, 2026-11-13, solo admin): el ícono de lápiz abre un modal
(mismo lenguaje visual que el de "Agregar factura") para corregir
proveedor/razón social/RUT/folio/fecha/total/notas; el de papelera hace un
soft-delete (`eliminada_en`) con confirmación nativa del navegador
(`confirm()`, mismo patrón que `cliente.php` en Fiados). Antes de programar
esto se revisó cómo se guardan las facturas para no romper nada:
- **Nunca se toca `ruta_pdf`, `negocio_id` ni `origen`** al editar — el PDF
  original no se mueve ni se reemplaza (`ver_pdf.php` sirve lo que diga
  `ruta_pdf` tal cual, sin recalcular la carpeta por la fecha, así que
  editar la fecha de emisión no requiere mover el archivo).
- **Una factura `origen='sync'` sigue teniendo como fuente de verdad la BD
  del PC** (ver `docs/CONTRATO.md`): si ese mismo PC la vuelve a sincronizar
  más adelante, el upsert de `api/facturas.php` sobrescribe lo editado acá.
  Es un caso raro (requiere que alguien la edite también en el programa de
  escritorio), pero el modal lo avisa igual cuando corresponde.
- **Restringido a admin**, mismo criterio que editar/eliminar en Fiados:
  `FacturasController::detalle()` revalida el rol en el backend (no solo
  oculta los botones), y cada cambio queda en `auditoria` vía
  `registrar_auditoria()`/`cambios_json()`.
- Validación del lado servidor antes de guardar: proveedor no vacío, fecha
  con formato válido y no futura (misma regla que el resto del sistema),
  total numérico mayor a 0 (reusa `parsear_monto()` de `lib/ui.php`). Si
  falla, se re-muestra el modal abierto con lo que el admin ya había escrito
  y un aviso del error — no se pierde la corrección a medio hacer.
- La pantalla también se reordenó visualmente: tres tarjetas (`.stat-card`,
  el mismo componente del Home) destacan Total/Estado/Fecha arriba, y el
  resto de los datos quedan en la grilla de siempre debajo.
- **El pop-up "Editar factura" y el pop-up de verificación de "Agregar
  factura" comparten el mismo componente visual** (`.modal-confirmar-ancho`
  + `.factura-campos` en `assets/estilo.css`) aunque viven en archivos
  distintos: uno es un `<form>` real (`detalle.php`, campos `name="..."`),
  el otro lo llena JS desde la respuesta de la IA (`panel.php`, campos
  `id="f..."`). Un ajuste de diseño en esas dos clases se ve en los dos a
  la vez — no duplicar el CSS si hay que tocar alguno de los dos (2026-11-14:
  se ensanchó el modal y Proveedor/Razón social pasaron a ocupar el ancho
  completo con `.campo-ancho`, porque antes se veían cortados).
- **El campo Total de los dos pop-ups formatea con separador de miles
  mientras se escribe** (`formatoMiles()`, duplicada a propósito en los dos
  `<script>` porque son dos archivos — es una función de 2 líneas, no vale
  la pena compartirla vía un JS externo). El valor que viaja al servidor
  es el mismo texto formateado ("1.250.000"): no hace falta limpiarlo antes
  de mandarlo porque `parsear_monto()` (`lib/ui.php`) ya sabe leer el `.`
  como separador de miles — es la misma función que usa el resto del
  sistema para montos chilenos. El de "Editar factura" además precarga el
  total ya formateado (`number_format()` en el Controlador).
- **Ficha de factura, ajustes para celular (2026-11-14)**: "Editar"/"Eliminar"
  se sacaron de la cabecera (`.cab-modulo-der`, donde solo quedan "Ver" y
  "Volver") y pasaron a una barra `.fd-acciones` al final de la página, abajo
  de "Datos de la factura" — en celular se apilan y ocupan el ancho completo,
  en escritorio quedan alineados a la derecha. El botón de PDF dice solo
  "Ver" (antes "Ver PDF"). Las tres tarjetas Total/Estado/Fecha usan un
  `@media (max-width: 600px)` que las pone en 2 columnas (Total arriba,
  ocupando el ancho completo por ser la destacada) con íconos y textos más
  chicos, para que no dominen la pantalla antes de llegar al contenido. Es
  solo reordenamiento visual — el modal de edición y el borrado funcionan
  exactamente igual que antes, con los mismos botones, solo que ahora se
  abren desde más abajo en la página.
- **Paginación del listado (2026-11-14)**: `panel_facturas.php` traía hasta
  500 facturas de un tirón sin paginar (podía volverse lento y confuso al
  crecer el historial). Ahora `Factura::listado()` acepta `$pagina`/`$porPag`
  (20 por página) y usa `LIMIT ... OFFSET ...`; `Factura::contarListado()` es
  el `COUNT(*)` equivalente para saber cuántas páginas hay.
  `FacturasController::panel()` lee `?p=N` de la URL (clamp a `[1, $paginas]`)
  y pasa `$pagina`/`$porPag`/`$total`/`$paginas` a la vista. La vista agrega
  el bloque `.paginacion` (mismo componente/clase CSS que ya usaba
  `historial_productos.php`, nada nuevo) con "← Anteriores"/"Siguientes →" y
  una función `url_pagina_factura()` que arma el link conservando todos los
  filtros activos (`$_GET` + `p`). El resumen "N factura(s)" ahora muestra el
  total real de la búsqueda (antes era `count($facturas)`, que con la
  paginación solo habría contado la página actual) y agrega "· página X de Y"
  cuando hay más de una página.

**Consistencia visual en Gastos** (`gastos.php`, 2026-11-14): mismo tratamiento
que Facturas/Fiados, reutilizando los componentes genéricos ya existentes (sin
CSS nuevo salvo el acento de tabla y la variante de botón). El panel de
Filtros es plegable (`.panel-filtros`, igual patrón que los otros dos) — ojo
que acá `$fDesde`/`$fHasta` **siempre** traen un valor por defecto (mes
actual) desde el Controlador, así que `$filtrosActivos` mira `$_GET['desde']`/
`$_GET['hasta']` crudos en vez de las variables ya normalizadas, para no
quedar siempre expandido. "+ Registrar gasto" pasó a pastilla roja
(`.btn-pastilla.btn-pastilla-rojo`, nueva variante del componente genérico
de Fiados). La tabla lleva `.tabla-gastos` (acento rojo en el encabezado,
Categoría en negrita, Monto en negrita — el color rojo del monto ya venía
de `.valor-rojo`). Las 4 tarjetas de resumen ya usaban el componente genérico
`.stat-card`/`.ingresos-stats` de antes, así que no se tocaron.
- **Paginación del listado (2026-11-14)**: mismo criterio y mismo código que
  el de Facturas (ver esa sección) — 20 gastos por página. `Gasto::listado()`
  ahora recibe `$pagina`/`$porPag` (antes era un `$limite` fijo de 300, sin
  paginar) y `Gasto::contarListado()` es el `COUNT(*)` para saber cuántas
  páginas hay, con los mismos filtros de período/categoría que ya usaba
  `listado()`. `GastosController::panel()` lee `?p=N`, y la vista agrega el
  bloque `.paginacion` + `url_pagina_gasto()` (idéntico patrón a
  `url_pagina_factura()`) y la línea "N gasto(s) · página X de Y" arriba de
  la tabla (antes no había ningún resumen de cantidad en esta pantalla).
- **Simplificación de Gastos (2026-11-14)**: a pedido del dueño se sacaron las
  4 tarjetas de resumen (Gastos del período/Promedio/Variación/Mayor por
  categoría) y la sección plegable "Por categoría" — quedó solo el badge
  "Viendo: ..." (mismo patrón que Facturas/Fiados, con `$viendo` calculado en
  `GastosController::panel()` igual que en `FiadosController::listado()`),
  Filtros y la tabla. `GastosController::panel()` dejó de calcular
  `totalGastos`/`nGastos`/`porCategoria`/`varGastos`/`promedioDiario`/`topCat`/
  `maxCat` (ya no se usan en la vista) — si se necesita ese resumen más
  adelante, está igual disponible en Home.

**Panel de Filtros plegable** (`panel_facturas.php`, 2026-11-12): el panel
"Filtros" arranca **colapsado** por defecto (pedido del dueño, para dejar la
ventana más limpia) salvo que ya haya un filtro activo en la URL (búsqueda,
negocio, proveedor o fechas) — ahí arranca expandido para que se entienda por
qué el listado está filtrado. `#panelFiltros` lleva la clase `.colapsado`
(calculada en el Controlador/Vista, `$filtrosActivos` en `panel.php`) y un
botón-cabecera (`#btnToggleFiltros`, con el ícono `chevron` que rota) la
agrega/quita en el navegador sin recargar la página. No cambia nada del
formulario de filtros en sí (mismos campos, mismo GET).

**Módulo Fiados** (web-nativo, NO participa de la sincronización; la web es la
fuente de verdad). Primer módulo fuera del POS migrado a capas (2026-11-08):
`fiados.php`/`cliente.php`/`cliente_form.php`/`estado_cuenta_imprimir.php` son
puentes a `App\Controllers\FiadosController` (métodos `listado()`, `ficha()`,
`formulario()`, `estadoCuenta()`), con `App\Models\Cliente` (SQL propio, mismo
criterio que `PrecioFV` — ver «Arquitectura del POS» más arriba) y las vistas
en `app/views/fiados/`. `fiados.php` (`listado()`) reusa el mismo tratamiento
visual que se definió primero para Facturas (2026-11-14, por consistencia
entre pantallas — ver «Panel de Filtros plegable» más abajo): el panel
"Filtros" plegable (`.panel-filtros`/`.panel-filtros-cab`, clases genéricas,
sin duplicar CSS) y la tabla con línea de acento del color del módulo +
primera columna en negrita (`.tabla-fiados` en `assets/estilo.css`, mismo
patrón que `.tabla-facturas`), el badge "Viendo: {negocio}" arriba junto al
título (igual que en Facturas) y el botón "+ Nuevo cliente" como pastilla
morada (`.btn-pastilla.btn-pastilla-morado`, una versión genérica de
`.btn-agregar-factura` pensada para reusarse en otros módulos sin tocar el
botón de Facturas). No se tocó `ficha.php` ni el resto de las vistas de
Fiados. `cliente.php` (`ficha()`) recibió el mismo tratamiento (2026-11-14):
el resumen de Saldo/Fiado/Abonado pasó de `.saldo-card` a tres `.stat-card`
dentro de `.ingresos-stats` — el mismo componente que ya usaban Home e
Ingresos, cero CSS nuevo, solo reusar `.t-rojo`/`.t-verde`/`.valor-rojo`/
`.valor-verde` que ya existían — y la tabla de movimientos (`.tabla-mov`,
exclusiva de esta vista) ganó la misma línea de acento morada + Detalle y
Monto en negrita que `.tabla-facturas`/`.tabla-fiados`. Los bloques
plegables (`.bloque`: Estado de cuenta, Datos de contacto) y el panel de
registrar fiado/abono no se tocaron — son compartidos con Caja/Gastos/
Ingresos, así que cualquier cambio ahí se hace aparte, no como "lo mismo
que Facturas". **Simplificación de `cliente.php` (2026-11-14):** a pedido
del dueño, las tres tarjetas de Saldo/Fiado/Abonado se sacaron (quedó solo
un badge compacto "Saldo: $X" en la cabecera, al lado de "Volver") y el
bloque "Datos de contacto" se movió entero a `cliente_form.php` — ya era la
pantalla de editar cliente, así que no hubo que crear una página nueva:
ahora un botón "Datos del cliente" en la cabecera de `cliente.php` lleva
ahí. El botón "Eliminar cliente" (solo admin) se fue con él, como una
sección "Zona de peligro" (`.zona-peligro`, el mismo patrón que ya usa
Productos para vaciar el catálogo) al fondo del formulario — el `<form>`
de borrado apunta con `action="cliente.php?id=N"` de vuelta a
`FiadosController::ficha()` (que ya sabía manejar `accion=eliminar_cliente`
desde antes), así que no hizo falta tocar esa lógica, solo moverle el
formulario de origen.
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

**Módulo Ingresos** (web-nativo; cortes de caja). Hoy los genera el **POS
propio** al cerrar turno (`registrar_corte()` en `lib/pos.php`, llamada desde
`generar_corte_de_sesion()`). Hasta 2026-11-14 también llegaban por correo de
Eleventa (software de punto de venta que el negocio dejó de usar — ver
`docs/MIGRACION_POS.md`), pero **ese flujo se retiró por completo** a pedido
del dueño, "para que el sistema sea completamente independiente": se
eliminaron `procesar_cortes.php` (worker de cron/IMAP), `lib/eleventa.php`
(parser del correo), la ruta `/corte_pegar.php` con
`IngresosController::pegar()` y su vista (el botón "Registrar corte"), el
aviso "correos sin procesar" de `ingresos.php`, el bloque `cortes_imap` de
`config.ejemplo.php`, y los métodos de `App\Models\Corte` que solo servían a
ese flujo (`correosPendientes`, `correoNegocioId`, `descartarCorreo`,
`correoPorMessageId`, `actualizarCorreoManual`, `crearCorreoManual`,
`correoCompletoPorId`, `errorDeCorreo`, `procesarCorreo`). `registrar_corte()`
— que **el POS reutiliza** para generar cada corte — se movió de
`lib/eleventa.php` a `lib/pos.php` antes de borrar ese archivo, sin cambiar
una línea de su comportamiento (se probó cerrando una caja real end-to-end
después del cambio). **Lo que se conservó a propósito**, por ser historial
real: la tabla `correos_corte` y los cortes que llegaron por correo en su
momento siguen intactos y se ven igual en Ingresos; `Corte::correoDe()` sigue
mostrando el texto crudo del correo original en el detalle de esos cortes
viejos (`corte.php`). Pendiente del lado del usuario (no se puede hacer
desde el código): sacar la tarea cron de `procesar_cortes.php` en cPanel.
`ingresos.php`/`corte.php` son puentes a `App\Controllers\IngresosController`
(métodos `panel()`, `detalle()`), con `App\Models\Corte` y las vistas en
`app/views/ingresos/`. `IngresosController::detalle()` (`corte.php`) tiene
una excepción al permiso `ingresos` (solo admin): una vendedora puede ver el
corte de un turno que **ella misma abrió** (`CajaSesion::corteEsPropio()`,
cruza `caja_sesiones.corte_id` con `usuario_apertura`) — así "Ver corte" en
su propio historial de `caja.php` funciona sin darle el módulo completo.
- **`correos_corte`** — cada correo crudo recibido en su momento (`cuerpo`
  MEDIUMTEXT, `message_id` UNIQUE, `origen` imap|manual, `estado`
  pendiente|procesado|error|descartado). Tabla de solo-lectura hoy: nada la
  escribe desde que se retiró Eleventa, queda como archivo histórico.
- **`cajeros`** — cajeros por negocio (UNIQUE negocio+nombre), se
  auto-crean al aparecer en un corte (antes los de Eleventa, hoy los del
  POS); `usuario_id` opcional los vincula a un usuario web.
- **`cortes`** — un cierre de turno: caja, cajero, rango del turno, ventas
  totales/efectivo/tarjeta/crédito/vales/transferencia, fondo, abonos,
  entradas/salidas y esperado. UNIQUE (negocio, cerrado_en, cajero) hace el
  reproceso idempotente (mismo patrón upsert que `api/facturas.php`).
- **`corte_movimientos`** — entradas/salidas de caja con hora y descripción
  (ej. pagos de pan a proveedores). Se reemplazan completos en cada reproceso.
- **`corte_departamentos`** — ventas por departamento del corte.
- **Consistencia visual (2026-11-14)**: mismo estándar que Facturas/Fiados/
  Gastos, **solo diseño, ningún cálculo tocado** (el dueño pidió explícitamente
  ir con cuidado en esta pantalla por ser la de las entradas de dinero por
  caja). Cabecera con badge "Viendo: ..." (`$viendo` calculado en
  `IngresosController::panel()`, mismo criterio que los otros módulos) +
  "Registrar corte" pasó a pastilla azul (`.btn-pastilla-azul`, nueva
  variante). Filtros plegable (`.panel-filtros`, mismo cuidado que Gastos:
  `$fDesde`/`$fHasta` siempre traen el mes actual por defecto, así que
  `$filtrosActivos` mira `$_GET` crudo). Se agregó la línea "N corte(s)"
  arriba de la tabla (antes no existía ningún contador ahí) — es solo
  `count($cortes)`, no toca el `$resumen` que alimenta las tarjetas. La tabla
  `.tabla-cortes` lleva acento azul en el encabezado, Cierre/Cajero en
  negrita y Ventas en negrita + azul (`td.col-ventas`, nueva clase agregada
  solo para el estilo). Las tarjetas de resumen y el aviso de "correos sin
  procesar" seguían el patrón `.ingresos-stats`/`.stat-card`/`.bloque`
  reutilizado en el resto del sitio — ambos se terminaron eliminando
  después, ver las entradas más abajo.
- **Se eliminaron "Ventas por día" y "Rendimiento por cajero" (2026-11-14)**,
  a pedido del dueño — quedó solo la tabla de cortes, sin los dos bloques
  plegables de análisis secundario que había debajo. Se quitó también el
  código que ya no se usaba: `Corte::ventasPorDia()` y `Corte::porCajero()`
  (nadie más los llamaba), y las variables `$porDia`/`$porCajero` de
  `IngresosController::panel()`. **Ojo, no confundir con `Home::ventasPorDia()`**
  — es un método distinto (en otro Modelo), que sigue usando el gráfico de
  ventas por día de Home y del panel por negocio; no se tocó.
  Columnas Efectivo/Tarjeta/Salidas de `.tabla-cortes` llevan color
  (`td.col-efectivo` verde, `td.col-tarjeta` amarillo oscuro `#8a5a00` —
  mismo tono ya usado en el resto del sitio para que sea legible en fondo
  blanco, no el amarillo puro de `--amarillo`—, `td.col-salidas` rojo). Puro
  cambio visual: los valores que muestran son exactamente los mismos.
- **Se eliminaron las 5 tarjetas de resumen (2026-11-14)** (Ventas del
  período/Efectivo/Tarjeta + transferencia/Salidas de caja/Número de
  ventas) — la pantalla ahora va directo del badge "Viendo"/Filtros al
  contador "N corte(s)" y la tabla. Se quitó también el código que solo
  alimentaba esas tarjetas y ya no se usa en ningún otro lado:
  `Corte::resumenPeriodo()`, el cálculo del período anterior
  (`$resumenAnt`/`$dias`/`$antDesde`/`$antHasta`) y `$variacion` en
  `IngresosController::panel()`. La tabla de cortes (con sus propios montos
  por fila) y todo lo demás no se tocó.

**Módulo Herramientas** (utilidades operativas, web-nativo). Tablero de tarjetas
(`herramientas.php`) gateado por el permiso `herramientas` (lo tienen `admin` y
`vendedor`). Incluye el contador de caja, el gestor de etiquetas de precio y el
creador de etiquetas de oferta (la tarjeta "Base de Datos de Productos" se sacó
de acá 2026-11-18: ese catálogo pasó a tener su propio ítem de menú, ver
«Módulo Productos» más abajo — `herramienta_productos.php` sigue existiendo,
solo que ya no se llega por Herramientas). El contador de
caja (billetes y monedas, todo calculado en el navegador) vive en
`app/views/partials/contador_caja.php` — un `require` directo, sin Modelo ni
Controlador propio, que comparten `herramienta_caja.php` (pantalla standalone)
y `caja.php` (embebido dentro de "Cerrar caja", con un botón extra "Usar este
total" que llena el campo de efectivo contado — ver «Arquitectura del POS»
abajo). Las páginas `*_imprimir.php` son "desnudas" (sin layout) para imprimir limpio vía
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
`categorias_gasto.php` son puentes a `App\Controllers\GastosController`
(métodos `panel()`, `formulario()`, `categorias()`), con `App\Models\Gasto` y
las vistas en `app/views/gastos/`. `lib/gastos.php` sigue existiendo y NO se
tocó: todo lo que ya lo usaba (`home.php`, `negocio_panel.php`) sigue
llegando a esas mismas funciones, ahora a través de `App\Models\Gasto` (que
las envuelve) en vez de llamarlas directo, desde que esas dos pantallas
también se migraron (Fases 13 y 16 — ver «Arquitectura del POS» más arriba,
sección de Modelos).
- **`categorias_gasto`** — catálogo editable de tipos de gasto (`nombre` UNIQUE,
  `activo`, `orden`). **Compartido entre negocios** (el dueño es uno). Se siembra con
  categorías base (Agua, Luz, Gas, Sueldos, Arriendo, Internet/Teléfono, Mantención,
  Otros) vía `INSERT IGNORE`. CRUD en `categorias_gasto.php`; una categoría con
  gastos asociados no se borra (se desactiva).
- **`gastos`** — cada gasto real (`negocio_id`, `categoria_id` FK RESTRICT, `fecha`,
  `monto`, `descripcion`, `creado_por`). CRUD en `gasto_form.php`.
- **"Gastos fijos" (plantillas de gasto recurrente) se eliminó por completo
  (2026-11-14)**, no se usaba. Se quitaron: la ruta `gastos_fijos.php` (`app/routes.php`),
  `GastosController::fijos()` y el bloque POST `generar_fijos` de `panel()`,
  `app/views/gastos/fijos.php`, los métodos de plantillas en `App\Models\Gasto`
  (`fijosDe`/`fijoPorId`/`crearFijo`/`actualizarFijo`/`toggleFijo`/`eliminarFijo`/
  `generarFijosDelMes`), `registrar_gastos_fijos()` de `lib/gastos.php`, y la
  tabla `gastos_fijos` + la columna `gastos.gasto_fijo_id` de `lib/esquema.sql`
  y `migrar.php` (ambos solo afectan instalaciones **nuevas** — ninguno de los
  dos hace `DROP`, así que la tabla y la columna siguen existiendo, vacías e
  inofensivas, en la base de datos ya instalada; si se quiere limpiar eso
  también hay que correr un `ALTER TABLE`/`DROP TABLE` aparte a mano). El menú
  "Más ▾" de `gastos.php`, que solo tenía "Gastos fijos" y "Categorías", quedó
  con un solo botón directo "Categorías" (ya no tiene sentido como dropdown).
- **Rediseño de `categorias_gasto.php` (2026-11-14)**: mismo estándar visual
  (acento rojo en `.tabla-categorias`, nombre de categoría en negrita, panel
  "Nueva categoría" con `.fd-panel-tit`). El botón "Activar"/"Desactivar"
  pasó de texto a ícono (`icono('activar')`, un ícono de encendido nuevo en
  `lib/ui.php`) para que los 3 controles de la columna Acciones (lápiz,
  encendido, basurero) midan siempre lo mismo. El basurero de "Eliminar", que
  antes desaparecía cuando la categoría tenía gastos asociados (hacía
  "saltar" el ancho de la columna de fila en fila), ahora se deja siempre
  visible pero atenuado y sin acción (`.btn-icono.deshabilitado`) con un
  tooltip explicando por qué no se puede borrar — ningún cambio funcional,
  solo visual. El panel "Nueva categoría" pasó de `.fd-panel`/`.fd-panel-tit`
  (acento naranjo — ese componente es de Facturas, no correspondía acá) al
  mismo patrón plegable `.panel-filtros` que Filtros: arranca colapsado para
  que la tabla quede a la vista de inmediato.

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

**Base de Datos de Productos** (catálogo **compartido** por los dos negocios —
ver también «Catálogo compartido» más abajo — que alimenta el gestor de
etiquetas):
- **`productos`** — un catálogo único para todo el sistema. `codigo` es
  **UNIQUE global** (no lleva `negocio_id`: un mismo código de barras es el
  mismo producto en cualquier negocio), `nombre`, `precio_costo/venta/mayoreo`,
  `departamento`, `tipo_venta`, `carga_id` (de qué subida de Excel vino la
  última vez). Las existencias sí son por negocio (`producto_stock`, ver
  «Catálogo compartido»).
- **`producto_cargas`** — una fila por subida de Excel (`negocio_id`: quién lo
  subió, no de quién son los productos; `archivo_nombre`, `total_productos`,
  `total_nuevos`, `total_actualizados`, `total_omitidos`, `cargado_por`,
  `cargado_en`). Da la "última actualización" (`MAX(cargado_en)`) y el
  historial; nunca se borra.

**Flujo**: el usuario exporta el catálogo desde Eleventa a un `.xlsx` y lo sube en
`herramienta_productos.php`. `lib/productos.php` lo parsea con **`ZipArchive`+`SimpleXML`
nativos** (un `.xlsx` es un zip de XML; **no se usa Composer ni PhpSpreadsheet**):
mapea columnas por el texto del encabezado (resiste reordenamientos), parsea los
precios como texto chileno (`$2.000`→`2000`) y descarta filas sin código/nombre.
`reemplazar_catalogo()` hace un **upsert transaccional** (registra la carga,
`INSERT ... ON DUPLICATE KEY UPDATE` por lotes sobre el código: agrega lo nuevo y
actualiza lo que ya existía; **nunca borra** un producto que falte en el Excel
subido — ver «Unificar catálogos» más abajo sobre el riesgo de esto cuando dos
negocios suben Excel distintos para el mismo código). **Salvaguardas anti-borrado**:
un archivo vacío/equivocado nunca borra el catálogo (falla antes), y "Vaciar
catálogo" exige escribir `ELIMINAR`. `estado_actualizacion()` calcula el aviso de
antigüedad (≤6 días ok, 7–14 naranja, >14 rojo) que se muestra en el administrador
y en el gestor de etiquetas. `buscar_productos()` (vía `productos_buscar.php`, JSON)
resuelve el autocompletar: por código de barras (prefijo) o por nombre (`LIKE`).

**Módulo Productos** (`productos.php`, ítem propio del menú desde 2026-11-18,
entre Ventas e Ingresos — antes vivía escondido dentro de Herramientas; mismo
permiso `productos` que ya tenían los dos roles, no cambió el acceso real):
`ProductosController::listado()` es la puerta de entrada — una tabla con
búsqueda, filtros, orden por columna y paginación (30 por página, mismo
patrón que Facturas/Gastos). `herramienta_productos.php` (`panel()`) sigue
existiendo tal cual para "Cargar Excel" y "Zona de peligro", pero perdió su
buscador propio (`buscarParaEditar()`/"Buscar y editar") porque esta tabla
ya lo hace mejor; se llega a `panel()`, `historial_productos.php` y
`herramienta_unificar_productos.php` con botones desde acá.
- **Columnas nuevas** (sobre lo que ya mostraba el buscador viejo): además de
  código/nombre/departamento/precio, **última modificación** + **antigüedad**
  (`actualizado_en`, con `hace_tiempo()` en `lib/ui.php` — "Hace 5 días", ya
  existía la columna cruda) y **modificado por** (`actualizado_por`, ya
  existía) + **unidades vendidas** y **última venta** (nuevas: `Producto::listado()`
  arma un `LEFT JOIN` a un resumen por producto — `SUM(venta_items.cantidad)`
  + `MAX(ventas.fecha)`, solo ventas `'completada'` — sin tocar ninguna tabla
  del POS, es de solo lectura). El filtro **"Ventas de"** deja ver el
  combinado de los dos negocios (por defecto) o acotar a uno solo, repitiendo
  el mismo resumen con `ve.negocio_id = ?` adentro del `LEFT JOIN`.
- **Filtro "Sin movimiento"** (30/90/180 días sin vender, o "Nunca
  registradas"): compara contra `MAX(ventas.fecha)` del producto (o su
  ausencia). **Ojo con esto**: el POS se habilitó para las vendedoras recién
  el 2026-11-08 (ver «Roles y permisos»), así que hoy casi no hay historia de
  ventas real — un producto "nunca registrado" significa "no se vendió por
  este sistema todavía", no "nunca se vendió en la vida" (las ventas de
  Eleventa, de antes, no están en esta base). La franja de 30 días ya es útil
  hoy; 90 y 180 se van a ir llenando solas con el tiempo sin tocar el código
  de nuevo — la vista avisa de esto en pantalla cuando se usan esas dos
  franjas. **No se implementó ningún aviso proactivo automático todavía**
  (ej. en el Home) — queda para una segunda etapa, cuando haya más historia
  real que mostrar.
- **"Marcar inactivo/Reactivar"** por fila (ícono `activar`, mismo que ya usa
  `categorias_gasto.php`): reutiliza el campo `productos.activo` que ya
  existía (lo respetan el POS/Etiquetas/Consultar Catálogo) — no se inventó
  un estado nuevo. `Producto::toggleActivo()` + `registrar_auditoria()` (acciones
  nuevas `activar_producto`/`desactivar_producto`, agregadas también al mapa
  de etiquetas y al filtro de `historial_productos.php`). **Nunca borra
  nada**: es la acción que se ofrece para un producto sin movimiento que se
  decide retirar, a propósito reversible.
- **Orden por columna**: whitelist fija en `Producto::columnaOrden()` (nunca
  el texto de la URL directo al SQL). El ícono de historial de cada fila
  reutiliza `historial_productos.php?q=CODIGO` tal cual — no hizo falta
  ninguna ruta nueva para eso.
- **Historial de cambios habilitado para vendedoras (2026-11-22)**:
  `ProductosController::historial()` pasó de exigir `usuarios` (solo admin)
  a exigir `productos` (ambos roles) — mismo permiso que ya tenía el
  listado. Es seguro porque la pantalla es **puramente de lectura** (un
  solo `<form method="get">` de filtros, sin ningún POST que edite o borre
  nada de `auditoria`): una vendedora puede ahora consultar quién cambió
  qué y cuándo, igual que un admin, pero no tiene forma de alterar el
  historial. El botón "Historial" de la cabecera y el ícono de reloj por
  fila dejaron de estar condicionados a `puede($usuario,'usuarios')`.
  "Unificar catálogos" y "Cargar Excel" siguen siendo solo admin — no
  cambiaron.
- **"Tipo de venta" fuera del formulario (2026-11-22)**: el campo no cumple
  ninguna función real en el sistema — no interviene en `lib/pos.php` ni en
  ningún cálculo de precio, es solo un dato suelto que venía del Excel de
  Eleventa y se mostraba como chip en `herramienta_catalogo.php`. Se sacó
  del formulario (`producto_form.php`) a pedido del dueño, pero **la
  columna sigue existiendo** y el Excel/Unificar catálogos la siguen
  leyendo/escribiendo igual que siempre (`lib/productos.php`) — no se tocó
  nada de esa cadena. Al editar un producto por el formulario,
  `ProductosController::formulario()` ya no lee `tipo_venta` del POST (el
  campo no existe más ahí): en su lugar preserva el valor que ya tenía el
  producto (`$original['tipo_venta']`), así una edición manual no borra lo
  que trajo el Excel.
- **"Afecto a IVA" fuera del formulario (2026-11-22)**: igual que "Tipo de
  venta", se analizó antes de sacarlo — `productos.afecto_iva` SÍ viaja más
  lejos que `tipo_venta` (`lib/pos.php::catalogo_para_pos()` lo manda al
  POS, el carrito lo copia a la línea de venta y queda guardado en
  `venta_items.afecto_iva`), pero **ninguna pantalla, cálculo ni reporte lo
  vuelve a leer** — no afecta el precio (acá, a diferencia del analizador de
  la app de escritorio, el precio de venta lo escribe la persona
  directamente, nunca se calcula a partir de un neto+IVA), no afecta el
  arqueo de caja, Ingresos ni Home. La boleta electrónica del SII —el único
  motivo real para distinguir afecto/exento— está explícitamente fuera del
  alcance del diseño actual (`docs/POS_DISENO.md`). Mismo tratamiento que
  `tipo_venta`: se sacó el checkbox de `producto_form.php`, la columna sigue
  existiendo con su default (`1`), y `ProductosController::formulario()`
  preserva el valor ya guardado del producto al editar (`$original['afecto_iva']
  ?? 1`) en vez de leerlo del formulario; uno nuevo siempre nace en `1`.
- **Validación robusta de Nuevo/Editar producto (2026-11-22)**: antes de
  tocar nada se revisó `lib/esquema.sql` y los datos reales del catálogo
  (`codigo` más largo: 20 — `nombre` más largo: 80 — `departamento` más
  largo: 20 — precio más alto: $55.000) para fijar límites parejos con lo
  que ya existe, no inventados: `ProductosController::MAX_LARGO_CODIGO` (20),
  `MAX_LARGO_NOMBRE` (150), `MAX_LARGO_DEPTO` (60), `MAX_MONTO` (99.999.999,
  tope de sentido común para CLP, muy por encima de cualquier precio real
  pero bloquea el típico error de tipeo de sobra de ceros). Las mismas
  constantes alimentan los `maxlength` del HTML (vista) y la validación real
  (controlador) para que nunca queden desincronizados.
  - **Código de barras solo números — con una excepción a propósito**: el
    catálogo real tiene 9 productos con códigos alfanuméricos heredados
    (`SRT5000`, `MARV 120 GRS`, códigos con espacios de tipeos viejos del
    Excel). En vez de romperlos, la regla es *"solo números, salvo que el
    código no haya cambiado respecto al que ya tenía el producto"* — así se
    puede seguir editando precio/nombre/departamento de esos 9 productos
    sin tocar su código, pero no se puede crear uno nuevo ni cambiar un
    código existente a algo que no sea solo dígitos. El JS del campo
    (`#inputCodigo`) filtra en vivo cualquier carácter que no sea número
    mientras se escribe — pero solo actúa sobre lo que el usuario *toca*: si
    no hace click ahí, un código legado con letras no se altera solo por
    abrir y guardar el formulario.
  - **Precios con separador de miles en vivo**: mismo patrón ya probado de
    `formatoMiles()` en `facturas/panel.php`/`detalle.php` (quita todo lo
    que no sea dígito, lo recorta a `MAX_MONTO` y le agrega los puntos),
    replicado en `producto_form.php` para `precio_venta`/`precio_costo`/
    `precio_mayoreo`. El valor que llega al servidor (ej. "15.000") lo seguía
    entendiendo `parsear_monto()` sin cambios — ninguna lógica de guardado
    se tocó, solo la validación alrededor.
  - **Errores junto al campo, no un solo cartel genérico**: `$errores` es un
    arreglo `campo => mensaje` (antes era un `$error` de texto único). La
    vista pinta el borde del campo en rojo (`.input-error`) y el mensaje
    puntual debajo (`.campo-error`) con `error_campo()`/`clase_campo()`
    (funciones de la vista, mismo patrón que `valor_historial()` en
    `historial.php`). Es la única pantalla del sistema con errores por
    campo — se decidió así a pedido explícito del dueño para este
    formulario puntual; el resto del sistema sigue con el cartel único
    arriba del formulario, no se tocó.
  - **Todo se valida en el servidor, no solo en JS**: la vista agrega
    `maxlength`/filtros en vivo como ayuda de UX, pero
    `ProductosController::formulario()` vuelve a validar todo desde cero
    (nunca confía en lo que ya filtró el navegador) — probado a propósito
    con un POST directo por `curl` saltándose el navegador (nombre de 200
    caracteres, precio negativo, costo no numérico, mayoreo sobre el tope):
    los cinco quedaron bloqueados con su mensaje, sin guardar nada.
  - **Precio de costo y mayoreo también se validan ahora** (antes solo
    `precio_venta` se revisaba): deben ser un monto válido, no negativo y no
    mayor a `MAX_MONTO` si se completan (siguen siendo opcionales).
- **Proveedores — Etapa 1: relación producto↔proveedor (2026-11-22)**: primer
  paso del diseño de Proveedores/Reposición (ver conversación del dueño sobre
  representantes de proveedores proponiendo pedidos — todavía no construido,
  esto es solo la base). "Departamento" **no se tocó**: sigue siendo la
  categoría del producto para reportes/promociones; "Proveedor" es un
  concepto nuevo y aparte (quién lo distribuye), tabla puente
  `producto_proveedor` (muchos a muchos, con `codigo_proveedor` opcional =
  el SKU propio del proveedor para ese producto). Se gestiona **desde los
  dos lados**, a pedido del dueño:
  - **Completo, desde el Proveedor** (`compras.php?proveedor=ID`, dentro del
    bloque "Proveedores" que ya existía): buscador de productos por AJAX
    (`compras_producto_buscar.php` → `Producto::buscarParaAsignar()`, con
    *debounce*, mismo patrón de UI que el combo de Departamento pero
    filtrando el catálogo completo en el servidor en vez de una lista fija
    en el cliente) para agregar, tabla de ya asignados con "Quitar".
    `ComprasController::index()` maneja las acciones `asignar_producto`/
    `quitar_producto` (`Compra::asignarProducto()` hace *upsert* — reasignar
    solo actualiza `codigo_proveedor`, nunca duplica la fila gracias a la
    llave primaria compuesta `(producto_id, proveedor_id)`).
  - **Solo lectura, desde el Producto** (`producto_form.php`): chips con los
    proveedores asignados (`Producto::proveedoresDe()`), sin poder editarlo
    ahí — el aviso bajo los chips dice dónde se gestiona.
  - Probado con `curl` directo (sin navegador, sin depender del `confirm()`
    de JS que el entorno de prueba suprime): producto o proveedor
    inexistente no inserta nada, reasignar actualiza sin duplicar, y quitar
    sí borra la fila.

**Unificar catálogos** (`herramienta_unificar_productos.php`, **solo admin**
— mismo criterio que "Vaciar catálogo" e `historial_productos.php`: es una
fusión masiva que toca el catálogo de los dos negocios a la vez, 2026-11-17):
como cada local tiene su propio Eleventa, cada uno trae su propio Excel; con
el upsert de arriba, subir el Excel de un negocio **pisaba en silencio** el
nombre/precio de cualquier código que el otro negocio ya tuviera distinto, sin
avisar. Esta pantalla compara los dos Excel ANTES de tocar el catálogo real y
deja que el admin decida cada diferencia:
- `comparar_catalogos()` (`lib/productos.php`, función pura, sin tocar la BD)
  cruza por código de barras y clasifica cada producto en **igual** (mismo
  nombre normalizado y mismo precio de venta: pasa directo), **conflicto_precio**
  (mismo código, precio de venta distinto — aunque el nombre TAMBIÉN difiera: el
  precio se prioriza sobre el nombre), **conflicto_nombre** (mismo código,
  precio igual, solo cambia el nombre: menor riesgo), **solo_a/solo_b** (código
  exclusivo de un negocio: entra tal cual) o **revisar** (código distinto pero
  nombre normalizado idéntico entre lo que quedó suelto de cada lado: puede ser
  el mismo producto mal tipeado, o dos productos distintos). La detección de
  "revisar" es por **coincidencia exacta de nombre normalizado** (sin tildes,
  minúsculas, espacios), no por similitud difusa (Levenshtein) — a propósito:
  barato y predecible, coherente con "PHP plano sin frameworks"; detecta el
  caso real (mismo nombre, código distinto) sin comparar miles de productos
  entre sí. **Conflicto de precio y de nombre se separaron en dos tablas**
  (2026-11-17, a pedido del dueño: el precio es el dato más sensible y debe
  resolverse primero) — antes eran un solo grupo `'conflicto'`.
- `crear_unificacion()` guarda la comparación en `producto_unificaciones`
  (cabecera: los dos negocios, los dos archivos, totales por grupo —
  `total_conflicto_precio`/`total_conflicto_nombre` desde la separación—,
  `estado` revisando\|aplicada\|descartada) + `producto_unificacion_items` (un
  producto por fila, con los datos "_a"/"_b" tal como venían y, para
  igual/solo_a/solo_b, ya con los "_final" resueltos: no hay nada que decidir).
  Las filas de **conflicto** (precio o nombre) se resuelven con "Usar negocio
  A", "Usar negocio B" o "Personalizar" (nombre y/o precio de venta propios —
  en un conflicto de nombre el precio ya es el mismo en los dos negocios, así
  que si no se escribe uno nuevo se deja el que ya había; costo/mayoreo/
  departamento/tipo de venta siempre se completan con los del negocio A). Las
  de **revisar** se resuelven con "Mismo, usar A/B" (un solo producto, el otro
  código se descarta) o "Son distintos" (entran los dos códigos por separado,
  como si nunca se hubieran emparejado) — `resolver_item_unificacion()` por
  ítem. **Pantalla de revisión** (`unificar_revision.php`): cada decisión de
  conflicto se muestra como dos tarjetas de color fijo por negocio (azul =
  negocio A, morado = negocio B — los mismos colores que ya usan "Solo en
  A"/"Solo en B" en el resumen) en vez de botones de texto parecidos entre sí,
  para que nunca haga falta leer la etiqueta para saber a quién pertenece cada
  opción; un conflicto de precio además muestra la diferencia en pesos y en
  %, con una alerta visual (borde e ícono naranja) cuando supera el 25% — con
  catálogos reales de dos negocios independientes esto puede ser una fracción
  grande de los productos, así que vale la pena remarcar los casos más
  llamativos. **Límite de tarjetas por pantalla** (`$CAP_CONFLICTOS = 60` en
  la vista): con catálogos reales puede haber miles de conflictos (two
  negocios con precios sistemáticamente distintos fácilmente superan 1.000);
  mostrar todos de una haría la página enorme y lenta, así que cada tabla
  muestra como máximo 60 pendientes a la vez — se resuelven, se recarga la
  página y aparecen los siguientes (el orden es por id, así que el "cupo" se
  va liberando solo). Lo ya resuelto se lista como un chip por fila si son
  pocos (≤30) o como un conteo simple si son muchos, para no alargar la
  página al infinito.
- `aplicar_unificacion()` exige que **no quede ningún ítem sin resolver**
  (`contar_pendientes_unificacion()`), y recién ahí escribe el catálogo real en
  una sola transacción (mismo upsert por código de `reemplazar_catalogo()`),
  deja `producto_unificaciones.estado='aplicada'` con los totales
  nuevos/actualizados, y un registro en `auditoria` (módulo `productos`,
  acción `unificar_catalogo`). `descartar_unificacion()` la marca
  `'descartada'` sin tocar nada del catálogo. Ninguna de las dos borra la
  comparación: queda en el historial de la pantalla para consultar después.
- La pantalla queda disponible de forma permanente (no es una herramienta de
  un solo uso): sirve para la próxima vez que haga falta auditar o fusionar
  catálogos (ej. un tercer local).

**Exportar catálogo a Excel** (`producto_exportar.php`, mismo permiso que el
panel — `herramientas` — no solo admin, 2026-11-17): descarga el catálogo
completo en un `.xlsx` con las mismas columnas que exporta Eleventa
(Codigo/Producto/P. Costo/P. Venta/P. Mayoreo/Departamento/Tipo de venta),
armado a mano con `ZipArchive` (sin Composer/PhpSpreadsheet, mismo criterio que
el parseo) vía `generar_excel_productos()`. Pensado para el caso de probar
"Unificar catálogos" en el ambiente local y después llevar el resultado a
producción **sin repetir las decisiones manuales**: se descarga acá y se sube
tal cual en el otro ambiente con "Cargar / actualizar base de datos" de
siempre (upsert por código, nunca borra) — confirmado con una prueba de
ida y vuelta (`parsear_excel_productos()` sobre el archivo recién exportado
lee exactamente los mismos productos). El botón vive en el panel principal de
Productos y también en la pantalla de una unificación ya aplicada.

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
  `caja_sesiones.tipo` (`fisica` | `express`) distingue una caja con cajón de
  efectivo de la **Caja Express** (pensada para vender desde un celular/tablet en
  horas punta): al abrirla no se pide fondo inicial, y tanto `PosApiController`
  (acciones `cobrar`/`movimiento`) como `registrar_venta()` en
  `lib/pos.php` rechazan cualquier intento de pago en efectivo o movimiento de
  caja en una sesión `express` — la vista (`pos/index.php`,
  `caja/index.php`) solo oculta esas opciones para no ofrecer algo que el
  servidor igual va a rechazar. El arqueo de una Express siempre da $0 (nunca
  tocó efectivo), así que su cierre no pide contar nada.
- **Al cerrar caja se genera un `corte`** con `registrar_corte()`, hoy en
  `lib/pos.php` (hasta 2026-11-14 vivía en `lib/eleventa.php`, retirado — ver
  módulo Ingresos), así el módulo Ingresos y los reportes siguen igual pero
  con datos propios. `cortes` ganó `recaudado_efectivo` y `diferencia` (los
  cortes históricos que llegaron por correo de Eleventa los dejan NULL).
- **`promociones`** — ofertas que el POS aplica **solo** al vender: `pack`
  ("4 x $1.000"), `nxm`, `precio` y `porcentaje`, a un producto o a un
  departamento. El navegador las calcula para mostrarlas al instante, pero **el
  servidor las recalcula al cobrar** e ignora lo que mande la caja.
- **Anular** solo se puede en una caja **todavía abierta**: cerrada, su corte ya
  está en Ingresos y anular reescribiría historia (además de ser la vía clásica de
  robo hormiga). Anular revierte el fiado y devuelve el stock.
- **"Ventas del día"** (botón del pie de `pos.php`) muestra **todo el negocio**:
  cualquier caja, cualquier vendedora, un día a la vez (selector de fecha, hoy por
  defecto) — a diferencia del resto de la pantalla, siempre acotada al turno
  actual. Panel izquierdo: folio/vendedor/artículos/total (`ventas_del_dia()` en
  `lib/pos.php`). Panel derecho, al elegir una fila: folio/vendedor/forma de
  pago, el detalle de artículos y, abajo, **Devolver artículo seleccionado** y
  **Cancelar venta** (este último reutiliza el `anular_venta()` de siempre, sin
  cambios). Ambos botones respetan la misma regla que anular: si la caja de esa
  venta ya está cerrada, quedan deshabilitados con una explicación. **Devolver
  artículo** (`devolver_articulo_venta()`) es nuevo: resta ese producto del
  total/stock y deja la venta parcial, pero **solo si la venta tuvo un único
  medio de pago** (si fue mixta o repartida, no hay forma no ambigua de decidir
  de cuál se descuenta — para esos casos se usa Cancelar venta completa) ni es
  el último artículo que le queda (eso ya es anular la venta completa). Marca el
  artículo con `venta_items.devuelto_en`/`devuelto_por` — `anular_venta()` ya
  sabe saltar los artículos que llegaran marcados así, para no devolver el mismo
  stock dos veces si a una venta ya devuelta parcialmente se la termina
  cancelando entera.

**Recaudación de convenios (retirada, 2026-11-14)** — el POS tenía un módulo
completo para cobrar cuentas de terceros (Klap y similares) desde la caja: una
pantalla "Servicios" para administrar convenios y ver el saldo, un botón "Pago
de cuenta" (F9) en `pos.php`, y el aporte de ese efectivo a `efectivo_esperado`
en el arqueo. Se eliminó por completo a pedido del dueño (no se usaba) —
`ServiciosController`, `App\Models\Convenio`, `app/views/servicios/`, la
sección "Recaudación de servicios" de `lib/pos.php`, la acción `recaudacion`
de `PosApiController`, el botón/modal de `pos.php`, el ítem de menú y las
tablas `convenios`/`servicio_operaciones` (borradas, con los datos que tenían).
**Lo único que se conservó**: la columna `cortes.recaudado_efectivo` (para no
perder el valor de los cortes viejos que sí recaudaron) y el término
correspondiente en la fórmula de `resumen_caja()` de `lib/pos.php` — ahora
siempre calcula 0, sin tocar el cálculo del arqueo que ya estaba probado.
`ingresos/detalle.php` solo muestra la fila "Pagos de cuentas" cuando ese
valor es mayor que 0 (los cortes históricos reales lo siguen mostrando; los
nuevos, no). Si el negocio vuelve a necesitar esto, hay que reconstruirlo
desde cero — no quedó ningún atajo ni bandera para reactivarlo.

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
  `gastos_registrar`, `productos`, `ventas`, `herramientas`, `negocios`, `usuarios`).
  Roles actuales: **`admin`** (todos los módulos) y **`vendedor`** (`ventas`,
  `facturas`, `fiados`, `productos`, `herramientas`, `gastos_registrar`).
  **`ventas` se habilitó para vendedoras el 2026-11-08** (antes era solo admin,
  mientras el POS se construía y probaba sin que las vendedoras vieran nada —
  ver `docs/MIGRACION_POS.md` paso 5. Ya se probó en producción, así que se
  habilitó). Da acceso a `pos.php` y `caja.php`: las vendedoras ya pueden
  vender y abrir/cerrar su propio turno.
  **`productos`** (crear/editar catálogo y promociones) lo tienen ambos por decisión
  del usuario: es más ágil y todo cambio queda auditado y consultable. `usuarios.rol` es `VARCHAR(20)` (agregar roles no requiere
  `ALTER`). El módulo `ingresos` es solo admin (información financiera del
  dueño). El módulo `precios` (Frutas y Verduras) ya no existe: esa pantalla
  vive dentro de `ventas` (ver «Arquitectura del POS»).
  **`gastos_registrar`** (2026-11-14) es un permiso aparte de `gastos`, para
  vendedoras: pueden **crear** un gasto nuevo (ej. pagarle en efectivo a un
  proveedor sin factura, como el de los huevos), pero no ven el listado ni
  pueden editar o eliminar uno existente — eso sigue siendo solo de `gastos`
  (admin). Es el único caso con dos permisos para la misma pantalla:
  `GastosController::formulario()` acepta ambos
  (`exigirPermisoAlguno(['gastos', 'gastos_registrar'])`) y adentro mira
  `puede($usuario, 'gastos')` para decidir si puede ver/editar/eliminar un
  gasto existente (`$puedeGestionar`) o solo crear uno nuevo. `panel()` y
  `categorias()` siguen exigiendo `gastos` a secas. El ítem de menú de
  Gastos (`lib/ui.php`) apunta a `gastos.php` si tiene `gastos`, o directo a
  `gasto_form.php` si solo tiene `gastos_registrar`.
- **Agregar un rol** = una fila en `PERMISOS` + etiqueta en `ROLES`. **Agregar un
  módulo** = su clave en la matriz + `exigir_permiso('modulo')` en la página + ítem
  en el menú de `lib/ui.php`. Todo el control vive en un solo lugar.
- Cada página protegida llama **`exigir_permiso('modulo')`** (en `permisos.php`,
  directo en el código viejo o vía `BaseController::exigirPermiso()` en las
  pantallas migradas — por dentro es la misma función): `exigir_login()` +
  chequea la matriz; si no tiene el permiso redirige a `home.php`.
  `exigir_admin()` queda para chequeos puntuales "solo admin". Para una
  pantalla que comparten un permiso completo y uno limitado está
  `BaseController::exigirPermisoAlguno([...])` — exige sesión y AL MENOS UNO
  de la lista; el Controlador decide adentro qué tanto puede hacer cada cual
  (ver `gastos_registrar` arriba).
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
