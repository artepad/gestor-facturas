# Plan de migración · POS a la arquitectura estándar

Basado en la auditoría (`00-auditoria.md`) y en el estándar de
`prompt-migracion-arquitectura.md`. Orden pensado para no romper nada: el POS sigue
funcionando igual en cada paso, probado antes de avanzar al siguiente.

**Regla general**: cada fase es un commit (o pocos), se prueba, y se informa antes de
seguir a la próxima. Nada se publica a `main` ni al servidor hasta que todo esto esté
terminado y probado por el dueño.

## Fase 3 · Red de seguridad (antes de tocar nada de lógica)

Una lista de pruebas manuales — abrir caja, vender con producto común y con
promoción, cobrar mixto, anular, cerrar caja, pago de cuenta con vuelto — con el
resultado esperado de cada una **tal como funciona hoy**. Sirve para comparar
después de cada corte y detectar si algo se rompió.

## Fase 4 · Esqueleto conviviendo con el código actual

Crear `app/`, `public/`, `bootstrap.php` y el Core mínimo (Config, Database, Request,
Router, Session, View, helpers) **sin mover ni borrar nada todavía**. El POS actual
sigue funcionando exactamente igual mientras se arma la base nueva al lado.

## Fase 5 · Arreglos rápidos y transversales (los hallazgos de la auditoría)

**Estado: 5 de 7 terminados y probados (2026-11-07).**

1. ✅ H1 — Bloqueo de intentos de login (5 fallos / 15 min, por correo y por IP).
2. ✅ H2 — Formato de `uuid_local` validado antes de usarlo como nombre de archivo.
3. ✅ H4 — `display_errors` fijo + página de error genérica (sin detalle técnico en
   producción).
4. ✅ H6 — Las dos consultas sin preparar, ya con prepared statements.
5. ⏳ H3 — CSRF en los formularios. **Pendiente a propósito**: se hace a la vez que
   cada pantalla se migra en la Fase 6, no dos veces.
6. ⏳ H5 — Mover los utilitarios de un solo uso fuera de la carpeta pública.
   **Pendiente a propósito**: hoy siguen siendo la única forma de administrar el
   servidor (`migrar.php`, etc.); se mueven en la Fase 9, cuando el dominio pase a
   apuntar a `public/`.
7. ✅ H7 — Respaldo automático de `almacen_pdf/` (`scripts/respaldo_pdfs.php`,
   conserva los últimos 14). **Falta un paso del dueño, no de código**: agregar el
   cron job en cPanel (mismo lugar donde ya está `procesar_cortes.php`).

## Fase 6 · Migración del POS, pantalla por pantalla

**Decisión tomada en el corte 1** (ver más abajo): los Modelos nuevos son
envoltorios sobre las funciones de `lib/pos.php`, no una reescritura de su
lógica. Mover 1.500 líneas de golpe era justo el riesgo grande que este plan
quiere evitar; el SQL se traslada recién cuando cada pantalla se retira de
verdad (paso **f** de la ficha de corte). Por eso el orden ya no separa
"`lib/pos.php`" como un paso aparte — se va extrayendo pantalla por pantalla.

Orden (de menor a mayor riesgo):

1. ✅ **`caja.php`** (abrir/cerrar turno, arqueo) — corte 1, terminado y probado
   (2026-11-07). `App\Models\CajaSesion`, `App\Controllers\CajaController`,
   vista en `/public/caja/`. CSRF agregado en sus 3 formularios (H3, para esta
   pantalla). `caja.php` sigue intacto y es el que atiende producción.
2. `pos.php` + `pos_api.php` (la pantalla de venta), dividido en dos sub-cortes
   por su tamaño (1.700 líneas de JS ya afinado + 320 de API):
   - ✅ **2a — la pantalla** (2026-11-07). `App\Models\Venta` (parcial) y
     `Promocion`, `App\Controllers\PosController`, vista en `/public/pos/`.
     `pos_api.php` **sigue sin tocar**: la vista nueva le sigue hablando en su
     ubicación de siempre — nada del motor de cobro cambió.
   - ✅ **2b — `pos_api.php`** (2026-11-07). `App\Controllers\PosApiController`
     + Modelos `Convenio` y el resto de `Venta`/`Promocion`. Las 13 acciones
     probadas una por una contra el original (varias cruzadas: una crea con
     el código viejo, la otra la reconoce como duplicada — prueba que
     comparten la misma base). La vista de `/public/pos/` ya le habla a este
     motor nuevo, con CSRF agregado (H3 cerrado para esta pantalla). Probado
     también con clics reales en el navegador: cobro completo de punta a
     punta. `web/pos.php` + `web/pos_api.php` siguen intactos y son los que
     de verdad atienden producción.
3. ✅ **`inventario.php` + `compras.php` + `promociones.php`** (2026-11-07).
   `App\Models\Inventario`, `Compra` y el CRUD de `Promocion` (envoltorios
   sobre `lib/pos.php`, mismo criterio que el resto); controladores y vistas
   en `/public/inventario/`, `/public/compras/`, `/public/promociones/`.
   CSRF agregado en los 6 formularios de las 3 pantallas (H3 cerrado para
   ellas). Probado de punta a punta con el mismo producto real: encender
   inventario → ajuste → mínimo → conteo → compra (verificado que el stock
   queda exactamente igual que haciendo las mismas operaciones a mano) →
   proveedor → promoción (crear/editar/eliminar, con el texto de la oferta
   igual al original). Probado también que un vendedor sin ser admin no
   puede encender/apagar el inventario ni forjando el POST directamente
   (el permiso se valida en el servidor, no solo se oculta el botón). Todos
   los datos de prueba se crearon y se borraron, confirmado con conteos
   antes/después; `web/inventario.php`, `web/compras.php` y
   `web/promociones.php` siguen intactos y son los que de verdad atienden
   admin.minimark.cl.
4. ✅ **`servicios.php`** (2026-11-07). `App\Models\Convenio` ampliado
   (conSaldo, crear, operaciones, registrarOperacion, ajustarSaldo);
   controlador y vista en `/public/servicios/`. CSRF agregado en sus 3
   formularios (H3 cerrado para esta pantalla).

   **Hallazgo sobre "integrar Mercado Pago"**: revisando todo el código no
   hay ninguna integración técnica con Klap (sin API, sin credenciales) —
   "Klap" es solo el nombre que el dueño escribió al crear el convenio; el
   sistema de convenios ya es genérico para cualquier proveedor desde que se
   diseñó. Lo único literalmente escrito en el código era el nombre por
   defecto que se autocrea si un negocio no tiene ningún convenio
   (`lib/pos.php::convenio_por_defecto`) — se cambió de "Klap" a "Mercado
   Pago". El cambio real (crear el convenio "Mercado Pago" y desactivar
   "Klap") es una acción del dueño desde la pantalla de Servicios, no un
   cambio de código; se le explicaron los pasos.

   Probado con el convenio REAL de El Maitén (que ya tiene pagos de cuentas
   reales de agosto 2026): el saldo que muestra la pantalla nueva es
   IDÉNTICO, carácter por carácter, al que muestra `servicios.php` — sin
   escribir nada, solo lectura. Aparte, con un convenio de prueba
   desechable: crear, registrar liquidación/comisión, cuadrar con el portal
   y el cálculo del saldo, todo verificado contra la fórmula original.
   Probado que un vendedor no puede entrar (es panel solo-admin, como
   Ingresos). Todos los datos de prueba se borraron, confirmado con
   conteos antes/después — el convenio real "Klap" y sus 2 recaudaciones de
   agosto quedaron intactos. `web/servicios.php` sigue intacto y es el que
   de verdad atiende admin.minimark.cl.

Con esto termina la Fase 6: las 7 pantallas del POS ya tienen su versión en
la arquitectura nueva, conviviendo con el sitio viejo sin tocarlo.

## Fase 7 · Base de datos

**Estado: terminada (2026-11-07).** Auditoría del esquema completo contra el estándar
(`prompt-migracion-arquitectura.md` 3.5) — este proyecto partió bien construido desde
el principio, así que la lista de pendientes fue corta:

1. ✅ **Columnas de auditoría faltantes.** 8 tablas que se editan después de creadas
   no dejaban rastro de cuándo: `clientes`, `fiados`, `abonos`, `convenios`,
   `caja_sesiones`, `cortes`, `compras`, `ventas`. Se les agregó `actualizado_en`
   (puro aditivo — ningún código ni pantalla cambia, MySQL la llena solo en cada
   `UPDATE`). Aplicado en `lib/esquema.sql` (esquema canónico) y `migrar.php`
   (idempotente, mismo patrón que el resto). Probado con una fila desechable:
   `creado_en` y `actualizado_en` iguales al crear, `actualizado_en` cambia al
   editar — confirmado y la fila borrada. Corrido dos veces seguidas en local para
   comprobar que no duplica nada; smoke test 32/32 después.
2. ✅ **Intentos de login, índices, llaves foráneas, InnoDB + utf8mb4** — ya
   cumplidos (la tabla `intentos_login` se agregó en la Fase 5; el resto ya estaba
   bien desde como se diseñó este proyecto).

**Revisado y descartado a propósito** (para no agregar trabajo o riesgo que el
estándar sugiere en general pero que acá no aplica o no conviene todavía):

- **`sesiones_recordadas`** (cookie "recuérdame" segura): este proyecto no usa ese
  patrón — la sesión dura 30 días directamente (decisión ya tomada: "la caja de un
  almacén no puede pedir contraseña cada mañana") y el cerrojo real es el PIN, no
  un sistema de sesión recordada aparte. No hay nada que migrar.
- **`recuperaciones_clave`** (reset de contraseña por correo, autoservicio): no
  existe hoy — el admin resetea contraseñas a mano desde `crear_admin_form.php`.
  Agregarlo sería una función nueva, no una reestructuración; queda en la lista de
  mejoras para después de terminar la migración (como se acordó).
- **Borrado lógico ampliado** (papelera recuperable de 30 días para clientes,
  fiados, promociones, proveedores...): hoy solo `facturas` lo tiene. Extenderlo es
  una mejora real pero grande (cambia cada pantalla de listado + una papelera
  nueva) — es del tipo de cosas que se decidió dejar para cuando termine la
  migración, no ahora.
- **Fechas en UTC**: el negocio opera en una sola zona horaria (Chile) sin
  necesidad real de otra. Convertir el almacenamiento a UTC tocaría cada fecha del
  sistema por un beneficio que hoy no existe — no conviene.
- **Collation explícita** (`utf8mb4_unicode_ci`): ya está en utf8mb4 en todas las
  tablas; fijar la collation a mano sería puro cosmético, sin beneficio práctico.

## Fase 8 · Archivos y subidas

**Estado: terminada (2026-11-07).** Inventario de toda subida de archivo del
proyecto: solo hay 2 — el PDF que sincroniza la app de Python (`api/facturas.php`)
y el Excel del catálogo (`herramienta_productos.php`).

1. ✅ **PDF de facturas** (`api/facturas.php`). El servidor no validaba que el
   archivo fuera realmente un PDF — solo confiaba en lo que mandaba el cliente.
   Se agregó: validación de la cabecera real del archivo (`%PDF-`, no la
   extensión ni el nombre) y un tope de 25 MB, **antes** de escribirlo a disco.
   Si el archivo no pasa, **no se cae toda la sincronización**: la factura se
   guarda igual, solo sin PDF — mismo criterio que ya existía para cuando la
   subida fallaba. El cliente Python no necesita ningún cambio (siempre manda
   PDFs reales); documentado en `CONTRATO.md`.
   Probado con una máquina y 2 facturas de prueba desechables: un PDF real
   quedó guardado y enlazado correctamente; uno falso (texto disfrazado de
   .pdf) NO se guardó en disco pero la factura igual se registró con sus
   datos — confirmado que nada se cae. Todo el rastro de prueba (facturas,
   `sync_log`, la máquina y el archivo) se borró al terminar, confirmado con
   conteos antes/después.
2. ✅ **Excel del catálogo** (`herramienta_productos.php`): revisado, ya estaba
   bien hecho desde antes — valida que de verdad se subió el archivo,
   extensión `.xlsx`, tope de 8 MB, y nunca queda guardado en disco (se lee y
   se descarta en el mismo request). Nada que cambiar.
3. ✅ **Carpetas de archivos privados**: `almacen_pdf/` y `respaldos_pdf/` ya
   tienen `.htaccess` con acceso totalmente denegado por fuera (`ver_pdf.php`
   es la única puerta, con control de acceso por negocio) — y en la
   arquitectura nueva (Fase 4) ya viven fuera de `public/`, así que cuando el
   dominio apunte ahí (Fase 9) quedan inaccesibles por estructura de
   carpetas, no solo por `.htaccess`.
4. ✅ **Nombres aleatorios**: los PDF ya se nombran por `uuid_local` (no el
   nombre original) desde la Fase 5 (hallazgo H2).

**Revisado y descartado a propósito:**

- **Limpieza de huérfanos**: cuando una factura se borra (siempre soft-delete,
  nunca físico) su PDF queda en disco indefinidamente. Purgarlo después de un
  tiempo es una decisión de **retención de datos del negocio** (cuánto tiempo
  guardar comprobantes eliminados), no un arreglo técnico — se deja para
  cuando el dueño decida una política concreta, no se asume ahora.

## Fase 9 · Despliegue

**Estado: terminada — subido y verificado en producción (2026-11-08).**

**Hallazgo importante antes de empezar**: el estándar de migración da por
sentado que, al llegar a esta fase, *todo* el sitio ya está en la
arquitectura nueva y por eso el despliegue consiste en apuntar el dominio a
`public/`. Acá no es así — se migró **a propósito solo el POS** (así se
acordó desde el principio: "vamos a migrar nuestro sistema POS"); el resto
del panel (facturas, fiados, ingresos, gastos, usuarios…) sigue siendo el
código de siempre. Cambiar el dominio a `public/` habría roto todo lo que no
es POS, porque esa carpeta solo sabe servir esas 7 pantallas.

**Por eso el despliegue acá fue distinto del estándar genérico**: en vez de
cambiar qué carpeta ve el navegador, cada pantalla vieja del POS se
reemplazó por un **archivo puente** — mismo nombre, misma dirección de
siempre (`pos.php`, `caja.php`, `inventario.php`, `compras.php`,
`promociones.php`, `servicios.php`, `pos_api.php`), pero su contenido ahora
es una llamada directa al Controlador correspondiente en vez de la lógica
vieja. Nadie nota el cambio: la dirección en el navegador no cambia, el
menú lateral es el mismo de siempre, y si algo saliera mal en una sola
pantalla se puede revertir solo esa (con `git checkout` sobre ese archivo),
sin afectar a las demás ni al resto del panel.

Como consecuencia, se pudo retirar todo el andamiaje transicional que existía
desde la Fase 6 para que el sitio viejo y el esqueleto nuevo convivieran bajo
distintas direcciones (`BaseController::baseSitioAntiguo()`,
`Request::base()` usado para armar links, las variables `$baseAntiguo`/
`$baseNuevo` en cada Vista): ya no hace falta, porque viejo y nuevo vuelven a
vivir en la misma dirección. Las 5 pantallas que tienen menú lateral
(`caja`, `inventario`, `compras`, `promociones`, `servicios`) pasaron de un
encabezado propio de "vista previa" a usar `cabecera_dashboard()`/
`pie_dashboard()` igual que el resto del panel — comparado byte a byte contra
lo que mostraba la pantalla vieja (mismo usuario, misma sesión real): la
única diferencia fue el campo CSRF agregado en cada formulario, nada más.
`pos.php` sigue en modo kiosco (sin menú lateral), igual que siempre.

**Rutas `/public/...` de la Fase 4 retiradas**: ya cumplieron su función de
vista previa durante la migración; `public/index.php` quedó solo con la ruta
de diagnóstico (`/`, confirma autoload+BD+sesión), útil si en el futuro se
migra otra pantalla del panel.

**Probado en local** (`http://localhost/minimark`, sin ningún `/public/`):
- Smoke test 32/32 en las direcciones reales.
- Las 5 pantallas con menú lateral muestran el sidebar completo, sin rastro
  del cartel de "vista previa", sin errores de PHP.
- `pos.php` mantiene el modo kiosco (cerrojo, sin sidebar) y su JavaScript ya
  llama a `pos_api.php` con ruta relativa normal.
- **Comparación byte a byte** de `caja.php` contra la versión anterior (con
  la sesión real de un negocio): idéntica salvo los 2 campos CSRF nuevos que
  corresponden a los formularios visibles en ese momento.
- Ningún link roto: se revisó que ninguna pantalla migrada conserve una
  referencia a las rutas transicionales (`/public/`, `baseAntiguo`,
  `baseNuevo`).
- El despacho por `accion` del puente de `caja.php` probado de punta a punta
  con un movimiento de caja real de prueba ($1, motivo marcado) — confirmó
  que no solo se muestra la pantalla, sino que el Controlador correcto
  procesa cada acción — y se borró al terminar (conteos antes/después).
- `pos_api.php` respondiendo JSON correctamente a través del puente.

**Subida real a producción (2026-11-08)**: como nunca se había subido nada de
esta migración al servidor (ni siquiera el POS), la subida real no fueron
solo los archivos de este corte sino **los 115 archivos que `web/` tiene
hoy** (todo lo acumulado desde la Fase 4: el POS completo + el endurecimiento
de seguridad de las Fases 5, 7 y 8). Se armó una carpeta limpia (export de
git, sin `config.php` ni datos locales) y el dueño la subió con FileZilla a
`/home1/migue492/admin.minimark.cl`, con respaldo de la base de datos hecho
antes por phpMyAdmin. Después corrió `migrar.php` en el servidor — aplicó,
entre otras cosas, la unificación del catálogo compartido entre negocios
(bajó de 6.158 a 3.079 productos al juntar los duplicados por negocio; sin
pérdida de stock porque el inventario nunca estuvo encendido en producción).
Verificado por el dueño después de subir: catálogo, Caja y Venta funcionando
con datos reales.

## Fase 10 · Cierre

**Estado: terminada (2026-11-08).** Revisión final contra la lista de
seguridad del estándar, pendientes explícitos, lecciones aprendidas y guía de
mantenimiento — todo en `docs/03-cierre.md`. `web/CLAUDE.md` actualizado para
describir la arquitectura real (mixta: POS en capas, resto del panel plano) en
vez de seguir diciendo "PHP plano" sin matices.

**Con esto se cierra la migración de arquitectura del POS.** Quedan
pendientes de bajo riesgo anotados en `03-cierre.md` (ninguno bloquea nada) y
la decisión, aparte y futura, de si alguna vez se migra el resto del panel.

## Fase 11 · Fiados (primer módulo fuera del POS)

**Estado: terminada en local (2026-11-08), pendiente de subir a producción.**

La decisión futura de la Fase 10 se tomó: se migra el resto del panel
módulo por módulo, no todo de una. Fiados fue el primero por ser chico y
autocontenido (4 pantallas: `fiados.php`, `cliente.php`, `cliente_form.php`,
`estado_cuenta_imprimir.php` — los archivos `corte*.php` que sonaban
parecidos en realidad son del módulo Ingresos, no de Fiados).

Mismo patrón que el POS: `App\Controllers\FiadosController` (métodos
`listado()`, `ficha()`, `formulario()`, `estadoCuenta()`), `App\Models\Cliente`
con SQL propio (sin equivalente previo en `lib/pos.php` — mismo criterio que
`PrecioFV`), vistas en `app/views/fiados/`, archivos puente con el mismo
nombre de siempre. Se agregó CSRF a los formularios (el código viejo no lo
tenía en este módulo). Probado con `scripts/smoke.php` (32/32) y un script de
pruebas funcionales dedicado (CRUD de cliente/fiado/abono, edición con
precarga, estado de cuenta con y sin rango, rechazo de CSRF, permisos de
vendedor vs. admin, verificación de que no quedan huérfanos en `fiados`/
`abonos` tras eliminar), más verificación visual en el navegador.

No se tocó `lib/pos.php`: el POS sigue escribiendo directo en `fiados`/
`abonos`/`clientes` al cobrar una venta a crédito (`registrar_venta`) y
leyendo el saldo para el selector "Fiado" — eso es responsabilidad del
Modelo `Venta`/`PosApiController`, no de `FiadosController`.

## Fase 12 · Gastos (segundo módulo fuera del POS)

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Segundo módulo del plan de migración módulo-por-módulo (después de Fiados).
Cuatro pantallas: `gastos.php` (panel del período + generar gastos fijos del
mes), `gasto_form.php` (CRUD de un gasto), `categorias_gasto.php` (CRUD de
categorías), `gastos_fijos.php` (CRUD de plantillas de gasto mensual).

Mismo patrón que Fiados: `App\Controllers\GastosController` (métodos
`panel()`, `formulario()`, `categorias()`, `fijos()`), vistas en
`app/views/gastos/`. `App\Models\Gasto` es **mixto** — a diferencia de
`Cliente`/`PrecioFV` (SQL 100% propio): envuelve `lib/gastos.php` para lo
que ya existía ahí (categorías, totales/desglose del período, generación
idempotente de gastos fijos), porque esas funciones las comparten `home.php`
y `negocio_panel.php` y **no se podían tocar**; usa SQL propio solo para el
CRUD de gastos/categorías/plantillas, que antes vivía suelto en cada
pantalla sin una función reutilizable.

Se agregó CSRF a los formularios (el código viejo no lo tenía). Se conservó
una particularidad del comportamiento original: "Generar gastos fijos del
mes" no redirige, pinta el resultado en la misma respuesta — se mantuvo así
a propósito, no es un descuido.

Probado con `scripts/smoke.php` (32/32, sin cambios en la lista de rutas) y
un script de pruebas funcionales dedicado: CRUD completo de categoría/gasto
fijo/gasto, generación de fijos con verificación de idempotencia (segunda
generación no duplica), CSRF, permisos (el rol vendedor no tiene el módulo
`gastos` en `lib/permisos.php`, así que se verificó que las 4 pantallas y la
acción `generar_fijos` redirigen igual para ese rol), y limpieza sin dejar
rastro en la BD. Verificación visual en el navegador.

**Corrección a una nota anterior**: durante las pruebas se vio un gasto creado
con la fecha "de hoy" aparecer fechado al día siguiente, y en su momento se
anotó acá como un problema de `date.timezone` en el `php.ini` local
(`Europe/Berlin` en vez de Chile). Investigado mejor en la Fase 13: **no es
un problema de la app** — `app/bootstrap.php` ya fija
`date_default_timezone_set('America/Santiago')` para toda pantalla que pase
por ahí (todo lo migrado a capas, `gastos.php` incluido). El desfase lo causó
el propio script de pruebas, que calculaba "hoy" con un `php -r` suelto por
consola (sin cargar `bootstrap.php`, así que usaba el `Europe/Berlin` del
`php.ini` de XAMPP) y mandaba esa fecha como dato del formulario. Nada que
corregir en el proyecto; sí vale recordarlo para el próximo script de
pruebas por consola: si necesita "la fecha de hoy", que la pida al
Controlador/Modelo real, no que la calcule aparte.

## Fase 13 · Sistema (tercer módulo fuera del POS)

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Tercer módulo del plan módulo-por-módulo. Cuatro pantallas sin relación
funcional entre sí (login/logout, el tablero de inicio, y servir el PDF de
una factura), así que en vez de un Controlador único (como Fiados/Gastos,
que sí son un solo dominio) se crearon tres, cada uno del tamaño de lo que
resuelve:
- `App\Controllers\AuthController` (`login()`/`logout()`) — login.php,
  logout.php. Es el único Controlador que NO extiende `BaseController`: la
  lógica es al revés (si HAY sesión, redirige a home.php en vez de
  exigirla). Sin CSRF a propósito, igual que el código viejo — un login
  forzado por CSRF no le sirve a un atacante sin la contraseña.
- `App\Controllers\HomeController` (`index()`) — home.php, el tablero de
  mando. Usa un método nuevo en `BaseController`, `exigirSesion()`, para los
  casos (como este) donde alcanza con estar logueado, sin exigir el permiso
  de un módulo puntual.
- `App\Controllers\VerPdfController` (`index()`) — ver_pdf.php. Extiende
  `BaseController` solo por `exigirPermiso('facturas')`; no usa
  `verificarCsrf()` porque es una pantalla de solo lectura (GET, sin
  formularios). Responde el PDF directo, sin Vista — mismo criterio que
  `pos_api.php` respondiendo JSON directo.

Modelos nuevos, ambos SQL propio (sin equivalente en ningún `lib/*.php`):
`App\Models\Home` (consultas por negocio: ventas, compras, facturas por
revisar, correos de corte con error, clientes con deuda) y
`App\Models\Factura` (hoy solo `paraPdf()`, lo mínimo que necesita
`ver_pdf.php` — el módulo Facturas completo, con `panel_facturas.php` y
`factura.php`, sigue sin migrar). `App\Models\Gasto` ganó un método más,
`porNegocio()` (envoltorio de `gastos_por_negocio()`), que `Home` reutiliza
para no duplicar esa consulta.

**Limpieza de código muerto encontrada de paso**: `home.php` calculaba
`$deudaNeg`/`$deudaTotal` (deuda por negocio) pero nunca los mostraba en
ninguna parte de la pantalla — el bloque "Requiere atención" usa una
consulta distinta (`$fiadosNeg`, vía `Home::fiadosConDeudaPorNegocio()`).
Se confirmó con una búsqueda en todo el proyecto que esas variables no se
usaban en ningún otro lado y se dejaron fuera de la migración (consistente
con el pedido explícito de "evitar código duplicado o funcionalidades
innecesarias" del encargo original de esta migración). No cambia nada
visible para el usuario.

Probado con `scripts/smoke.php` (32/32, login incluido — es la base de
todo el resto de la suite) y un script de pruebas funcionales dedicado:
formulario de login sin sesión, credenciales inválidas, login correcto,
redirección si ya hay sesión, cookie "recordar mi correo" (guardarla,
precargarla, olvidarla), logout, acceso bloqueado después de logout,
`ver_pdf.php` sin id / con id inexistente / con una factura real (se
verificó la firma `%PDF` de la respuesta) y el filtro de acceso por
negocio. Verificación visual en el navegador (pantalla de login y tablero
de inicio completo, con sus alertas de "Requiere atención").

## Fase 14 · Usuarios (cuarto módulo fuera del POS)

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Cuarto módulo del plan módulo-por-módulo. Una sola pantalla (`usuarios.php`):
listado, crear/editar (con PIN opcional de la caja y asignación de
negocios), eliminar. `App\Controllers\UsuariosController` (un solo método
`index()`, mismo patrón que `GastosController::fijos()`: lista y formulario
en la misma pantalla, según haya o no `?editar=N`) + `App\Models\Usuario`
(SQL propio, sin equivalente previo en ningún `lib/*.php`).

Se preservaron intactas las dos salvaguardas del código viejo (no se
tocaron, solo se migraron tal cual): no dejar el sistema sin ningún
administrador (al degradar de rol o al eliminar) y no poder eliminarse a
uno mismo. Se agregó CSRF a los dos formularios (crear/editar y eliminar).

**Nota de comportamiento conservada**: igual que `gastos.php` con
"Generar gastos fijos del mes", guardar o eliminar un usuario **no
redirige** — la pantalla responde 200 con el mensaje ("Usuario guardado."/
"Usuario eliminado.") pintado en la misma respuesta. Es el comportamiento
del código viejo (nunca tuvo un `header('Location:...')` en esta pantalla),
no una regresión de la migración.

Probado con `scripts/smoke.php` (32/32) y un script de pruebas funcionales
dedicado: CSRF, crear usuario con PIN y negocio asignado, contraseña corta,
correo duplicado, editar sin tocar la contraseña (se verificó que el hash
no cambia), promover a admin (pierde sus negocios asignados — un admin ve
todos), degradar un admin que NO es el último, intento de auto-eliminación,
permisos (el rol vendedor no tiene el módulo `usuarios`), y una prueba
aislada adicional que confirmó, contra el único admin real de la base de
datos local, que degradarlo se rechaza y su rol queda intacto. Verificación
visual en el navegador (listado y formulario de creación).

## Fase 15 · Facturas (quinto módulo fuera del POS)

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Quinto módulo del plan módulo-por-módulo. Dos pantallas, ambas de solo
lectura — sin un solo formulario que guarde algo, así que no hace falta
CSRF: `panel_facturas.php` (listado + filtros: negocio, proveedor exacto,
texto libre, rango de fechas) y `factura.php` (cabecera + líneas de
`detalle_factura` + botón Ver PDF). `App\Controllers\FacturasController`
(`panel()`/`detalle()`) + `App\Models\Factura`, que ya existía desde la
Fase 13 (Sistema) con un solo método, `paraPdf()`, para `ver_pdf.php` — acá
se completó con `listado()`, `proveedoresDe()`, `porId()` y `detalleDe()`.
`ver_pdf.php` sigue usando `Factura::paraPdf()` sin cambios.

Nada de lib/*.php que envolver: estas consultas vivían sueltas en las dos
pantallas, sin una función reutilizable en ningún lado (ni siquiera las usa
`negocio_panel.php`, que tiene las suyas propias para facturas).

Probado con `scripts/smoke.php` (32/32) y un script de pruebas funcionales
dedicado: el listado sin filtros contra el conteo real de la BD, filtro de
texto, filtro de proveedor exacto (combo) contra el conteo real, rango de
fechas amplio y rango sin resultados, el detalle de una factura real
(proveedor y número correctos, el bloque de líneas de producto aparece
solo si existen), factura inexistente y sin `id` (ambos redirigen al
listado), una factura con soft-delete (`eliminada_en` no nula: no se puede
ver, redirige igual que una inexistente) y el control de acceso por
negocio para el rol vendedor. Verificación visual en el navegador (listado
y detalle con datos reales).

## Fase 16 · Negocios (sexto módulo fuera del POS)

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Sexto módulo del plan módulo-por-módulo. Cuatro pantallas:
`negocios.php` (listado con conteo de facturas y PCs), `negocio_form.php`
(crear/editar perfil, con generación de slug único), `negocio_tokens.php`
(generar/revocar tokens de sincronización de cada PC) y `negocio_panel.php`
(análisis: ventas, compras, gastos, fiados y balance de un negocio, con
gráficos). `App\Controllers\NegociosController` (`index()`, `formulario()`,
`tokens()`, `panel()`) + `App\Models\Negocio`, SQL propio.

**Particularidad de permisos**: a diferencia de las otras tres pantallas
(solo admin, `exigir_permiso('negocios')`), `negocio_panel.php` es para
**cualquier rol** que pueda ver ese negocio — es la pantalla a la que llega
cualquier usuario al hacer clic en una tarjeta de "Estado por negocio" del
Home. Usa `BaseController::exigirSesion()` (el mismo método agregado en la
Fase 13 para `home.php`) en vez de `exigirPermiso()`.

**Sin duplicar consultas**: en vez de reescribir las métricas de
`negocio_panel.php` desde cero, `Negocio` reutiliza `Home::ventasPorDia()` y
`Home::comprasPorNegocio()` (pasándoles un array de un solo id) y
`Gasto::totalPeriodo()`/`Gasto::porCategoria()` — las únicas consultas
nuevas son las que no existían en ningún otro Modelo (resumen de ventas por
medio de pago, ventas por departamento, productos más comprados, deuda
vigente de un negocio).

Se agregó CSRF a los tres formularios con escritura (crear/editar negocio,
generar/eliminar token); `negocio_panel.php` es de solo lectura, sin CSRF.
Se conserva que generar un token no redirige: se muestra en texto plano una
sola vez, en la misma respuesta — exactamente el comportamiento original
(crítico no cambiarlo: es la única vez que el token completo es visible).

Probado con `scripts/smoke.php` (32/32) y un script de pruebas funcionales
dedicado: listado contra el conteo real de la BD, CSRF, crear negocio y
verificar el slug generado, slugs únicos al repetir el nombre (sufijo
`-2`), nombre vacío rechazado, editar (incluida la casilla "activo"
desmarcada) sin que el slug cambie, el panel de análisis con un negocio
real y con un id inexistente/ausente, generación de token verificando que
el hash guardado en la BD coincide con `sha256()` del token mostrado y que
el token en claro no vuelve a aparecer al recargar, aislamiento entre
negocios (no se puede eliminar una máquina de otro negocio desde su propia
pantalla de tokens), y permisos por rol (vendedor sin acceso a las tres
pantallas de admin, pero con acceso a `negocio_panel.php` de sus propios
negocios y bloqueado en los ajenos). Verificación visual en el navegador
(listado, panel de análisis y tokens, los tres con datos reales).

## Fase 17 · Ingresos (séptimo módulo fuera del POS)

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Séptimo módulo del plan módulo-por-módulo. Tres de las cuatro pantallas se
migraron: `ingresos.php` (dashboard: filtros, resumen con comparativa
contra el período anterior, evolución diaria, rendimiento por cajero, tabla
de cortes, y el aviso plegable de correos sin procesar con su botón
"Descartar"), `corte.php` (detalle completo de un corte: dinero en caja,
ventas por medio de pago, movimientos, departamentos y el correo original)
y `corte_pegar.php` (registrar un corte pegando el texto del correo a
mano). `App\Controllers\IngresosController` (`panel()`, `detalle()`,
`pegar()`) + `App\Models\Corte`.

**Decisión de alcance — `procesar_cortes.php` queda fuera**: es el worker
que corre por cron cada 10 minutos (o a mano con `?clave=setup_key`) leyendo
la casilla IMAP de cortes. No tiene sesión, no usa `exigir_permiso()`, no
renderiza una pantalla del panel (solo texto plano de diagnóstico) — es más
parecido a `api/facturas.php` (el endpoint de sincronización) o a
`migrar.php` que a una pantalla que un usuario abre con el navegador. Por
eso no se le aplicó el patrón Router→Controlador→Modelo→Vista; sigue
llamando `lib/eleventa.php` directo, exactamente como antes. Esto es
consistente con el resto del proyecto: ninguno de esos scripts de
infraestructura se ha migrado, solo las pantallas de sesión.

`App\Models\Corte` sigue el mismo criterio que `Gasto`: envuelve
`lib/eleventa.php` únicamente para `procesar_correo_corte()` (el parser
completo del correo de Eleventa, que NO se tocó — lo sigue usando también
`procesar_cortes.php`), y usa SQL propio para todo lo demás (las consultas
del dashboard, el detalle de un corte y el alta/actualización del correo
pegado a mano, que antes vivían sueltas en cada pantalla).

Se agregó CSRF a los dos formularios con escritura (`corte_pegar.php` y el
botón "Descartar" de `ingresos.php`); `corte.php` es de solo lectura.

Probado con `scripts/smoke.php` (32/32) y un script de pruebas funcionales
dedicado que registró un corte real pegando un correo de Eleventa de
ejemplo (con cajero, departamento y un movimiento de salida) y verificó
contra la base de datos que los montos (ventas totales, salidas, número de
ventas) quedaron exactamente como el correo los traía; probó la
deduplicación por hash del contenido (pegar el mismo correo dos veces no
crea una segunda fila en `correos_corte`); CSRF; validaciones (negocio
inválido, cuerpo vacío); que el corte recién creado aparece en el listado
filtrado por fecha y por cajero; el detalle de ese corte (incluido que
indica "pegado a mano"); un corte inexistente; descartar un correo con
error; y permisos (el rol vendedor no tiene el módulo `ingresos`).
Verificación visual en el navegador (dashboard y detalle con datos reales).

## Fase 18 · Productos/Herramientas (octavo y último módulo fuera del POS)

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

El módulo más grande, dejado para el final a propósito. 12 pantallas/endpoints
en total, divididos en 5 Controladores (uno por "herramienta", siguiendo el
mismo criterio que Ingresos: varias pantallas relacionadas comparten
Controlador):

- `App\Controllers\HerramientasController` (`tablero()`, `caja()`) —
  `herramientas.php` (tablero de tarjetas) y `herramienta_caja.php`
  (contador de billetes/monedas). Ninguna de las dos toca la base de datos:
  el contador calcula todo en el navegador. Sin Modelo.
- `App\Controllers\ProductosController` (`panel()`, `formulario()`,
  `historial()`) — `herramienta_productos.php` (cargar el Excel de
  Eleventa, buscar/editar, zona de peligro "vaciar catálogo"),
  `producto_form.php` (crear/editar/eliminar un producto) y
  `historial_productos.php` (auditoría, solo admin). `App\Models\Producto`
  envuelve `lib/productos.php` (parseo del `.xlsx` con ZipArchive+SimpleXML,
  reemplazo transaccional del catálogo, estado de actualización, búsquedas —
  NO se tocó, son funciones puras sin equivalente OOP previo) y usa SQL
  propio para el CRUD de un producto puntual y el historial de auditoría
  (que lee la tabla `auditoria` filtrada por `modulo = 'productos'`, la
  misma que ya usan Inventario/Compras/Promociones del POS — no es un
  módulo exclusivo del catálogo, eso viene de antes de esta migración).
- `App\Controllers\EtiquetasController` (`formulario()`, `imprimir()`,
  `buscarJson()`) — `herramienta_etiquetas.php`, `etiquetas_imprimir.php`
  (hoja A4 "desnuda") y `productos_buscar.php` (JSON del autocompletar).
- `App\Controllers\OfertasController` (`formulario()`, `imprimir()`) —
  `herramienta_ofertas.php` y `ofertas_imprimir.php`. La cola de hasta 4
  ofertas se arma en el navegador (JS) y se manda como JSON al generar; no
  hay Modelo, no toca la base de datos.
- `App\Controllers\CatalogoController` (`index()`, `buscarJson()`) —
  `herramienta_catalogo.php` (Consultar Catálogo, solo lectura, con escáner
  de código de barras híbrido) y `catalogo_buscar.php` (JSON con todos los
  campos del producto, distinto del endpoint liviano de Etiquetas).

**CSRF** se agregó a los dos formularios que escriben en la base de datos:
cargar Excel / vaciar catálogo (`herramienta_productos.php`) y
crear/editar/eliminar (`producto_form.php`). Los formularios de
`etiquetas_imprimir.php` y `ofertas_imprimir.php` NO llevan CSRF a
propósito: no persisten nada, solo arman una página para imprimir o
guardar como PDF — mismo criterio que `precios_fv_imprimir.php`.
`productos_buscar.php` y `catalogo_buscar.php` imitan el comportamiento
exacto del código viejo: la falta de sesión/permiso redirige (como
cualquier pantalla), y solo el negocio inválido responde JSON 403.

Probado con `scripts/smoke.php` (32/32) y un script de pruebas funcionales
dedicado: generó un `.xlsx` de prueba válido (mismo formato que exporta
Eleventa) y verificó que la carga no borra productos existentes, que queda
registrada en `producto_cargas`, y que el buscador del panel y los dos
endpoints JSON (`productos_buscar.php`, `catalogo_buscar.php`) encuentran
los productos cargados; CSRF (rechaza vaciar/cargar/crear/editar/eliminar
sin token); creación, edición (con el detalle de qué campo cambió
auditado) y eliminación de un producto vía `producto_form.php`, incluido
que un código duplicado se rechaza y que guardar sin cambios reales no
duplica la auditoría; generación de etiquetas de precio (descarta filas
vacías, formatea CLP) y de ofertas (las 4 válidas se imprimen, un tipo
inválido se descarta, los espacios libres se rellenan); filtros del
historial (texto, acción, persona); y permisos (vendedor entra a
Herramientas/Productos pero no al historial, que es solo admin).
Verificación visual en el navegador de las 6 pantallas, incluido probar el
autocompletar del Gestor de Etiquetas y el buscador de Consultar Catálogo
con datos reales (escribiendo "coca" y viendo los resultados en vivo).

Con esto terminan los 8 módulos fuera del POS. Todo `web/` sigue la
arquitectura en capas salvo los scripts de infraestructura sin sesión
(`api/facturas.php`, `migrar.php`, `procesar_cortes.php`).

## Fase 19 · Punto de entrada único (Router)

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Con los 8 módulos migrados, la raíz de `web/` tenía ~45 "archivos puente"
de 10-15 líneas, uno por pantalla — necesarios para que cada dirección
(`herramienta_caja.php`, `gastos.php`, etc.) siguiera existiendo como
archivo real, pero sin lógica adentro más allá de instanciar un
Controlador. Esta fase los reemplaza por un único punto de entrada,
pedida explícitamente por el dueño al ver la carpeta en el editor
("código suelto" en la raíz).

**Qué cambió:**

1. `app/Core/Router.php` — se corrigió `agregar()` para escapar los tramos
   literales de cada ruta con `preg_quote` (antes el regex se armaba sin
   escapar, así que el `.` de `.php` matcheaba cualquier carácter; no era
   explotable porque no había rutas "vecinas" peligrosas, pero ahora que el
   Router despacha TODO el sitio convenía cerrarlo bien).
2. `app/Core/Request.php` — `ruta()` ahora calcula sola el prefijo de
   carpeta donde vive `index.php` (`dirname($_SERVER['SCRIPT_NAME'])`) y lo
   descuenta antes de matchear. Funciona igual en local
   (`http://localhost/minimark/`) y en producción (dominio directo a
   `web/`) sin configurar nada a mano. Se retiraron `fijarBase()`/`base()`
   (quedaban sin usar — nadie los llamaba — y describían un diseño viejo:
   el dominio apuntando a `public/`, que nunca se usó).
3. `app/routes.php` (nuevo) — la tabla completa de rutas: una línea por
   dirección, agrupadas por módulo, registrando GET y POST con el mismo
   destino vía el helper `$ambos()` (los archivos puente de antes
   respondían a cualquier método HTTP, así que para no cambiar el
   comportamiento se registran ambos siempre). La única ruta que no es
   `[Controlador, 'metodo']` es `/caja.php`: registra una función que hace
   el mismo `match()` por `accion` del POST que antes hacía el archivo
   puente (`CajaController` separa `abrir()`/`movimiento()`/`cerrar()` en
   vez de un solo método).
4. `index.php` (la raíz) — pasó de ser un simple redirect ("si hay sesión →
   home.php, si no → login.php") a ser el único archivo real: crea el
   Router, incluye `app/routes.php`, despacha según método+ruta (HEAD se
   trata como GET) y responde 404 si nada hace match. La redirección vieja
   de la raíz ("/") ahora es la primera ruta registrada en `app/routes.php`.
5. `.htaccess` — una regla nueva al final: cualquier dirección que NO sea
   un archivo o carpeta real existente (`RewriteCond %{REQUEST_FILENAME}
   !-f`/`!-d`) se manda a `index.php`, SIN el flag `[R]` — así la URL que
   ve el navegador no cambia. Los archivos reales (CSS, `uploads/`,
   `api/facturas.php`, `migrar.php`, `procesar_cortes.php`, `config.php`)
   siguen sirviéndose directo, nunca pasan por el Router.
6. Se borraron los ~45 archivos puente de la raíz (`git rm`) — ninguna
   pantalla quedó con un archivo físico propio. En la raíz de `web/`
   siguieron existiendo los archivos que nunca fueron pantallas
   (`config.php`, `.htaccess`, `sw.js`, `manifest.webmanifest`,
   `migrar.php`, `procesar_cortes.php`, `CLAUDE.md`, varios `.md`) — esos
   se ordenaron después, en la Fase 20.

**Ninguna URL cambió.** `admin.minimark.cl/herramienta_caja.php` sigue
siendo exactamente esa dirección; lo único que cambió es que ya no hay un
`herramienta_caja.php` físico — lo resuelve el Router. Tampoco cambió el
comportamiento de ninguna pantalla (CSRF, permisos, uploads, JSON, etc.
idénticos).

**Probado:**
- `scripts/smoke.php` (32/32) corriendo a través del Router.
- Se volvieron a correr **todos** los scripts de pruebas funcionales de
  cada módulo migrado (Sistema, Usuarios, Facturas, Negocios, Ingresos,
  Fiados, Gastos, Productos/Herramientas) sin cambiarles una línea: todos
  siguen pasando igual, confirmando que uploads multipart (el Excel de
  productos), JSON, y los formularios con CSRF funcionan idénticos a
  través del nuevo punto de entrada.
- Un script de pruebas nuevo dedicado al Router: `caja.php` con sus 3
  acciones especiales (`abrir`/`movimiento`/`cerrar`, cada una redirige
  por CSRF inválido sin token, y una acción desconocida cae al `index()`
  por defecto, igual que el `match()` original); un archivo estático
  (`assets/estilo.css`) se sigue sirviendo directo sin pasar por PHP;
  `config.php` sigue bloqueado; una ruta realmente inexistente da 404
  limpio (no 500); la raíz `/` redirige según sesión; `pos_api.php`
  responde JSON válido.
- Verificación visual en el navegador: Home, Herramientas, Caja (con un
  turno abierto de verdad) y la pantalla de Venta en modo kiosco (sin
  sidebar, con su propio CSS/JS, candado por PIN) — todas renderizan
  idéntico a como lo hacían con los archivos puente.

## Fase 20 · Orden de la raíz de web/

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Con los archivos puente fuera (Fase 19), quedaban sueltos en la raíz de
`web/` varios `.md` y un log de errores — pedido explícito del dueño al
revisar la carpeta en el editor. Ninguno de estos archivos se ejecuta ni
cambia el comportamiento del sitio, así que el reordenamiento es solo
organización:

- **6 documentos `.md` se movieron a `docs/`**: `CONTRATO.md`,
  `DESPLIEGUE.md`, `INSTALAR_POS.md`, `MIGRACION_POS.md`, `POS_DISENO.md`,
  `README_SERVIDOR.md`. Ya estaban bloqueados para acceso web directo
  (`FilesMatch "\.md$"` en `.htaccess`, sin relación con dónde viven en el
  disco) y ningún `.php` los `require`/`include` — son solo prosa que un
  humano lee. Se actualizaron las referencias "de verdad" (los punteros en
  `CLAUDE.md` de la raíz del repo, en `web/CLAUDE.md`, y los comentarios
  sueltos en `lib/pos.php`, `lib/auth.php`, `lib/productos.php`,
  `migrar.php`, `app/Models/Convenio.php`, `app/Models/Factura.php` y
  `app/views/pos/index.php` que decían "ver POS_DISENO.md" etc.) para que
  sigan apuntando al lugar correcto. Los que ya vivían dentro de `docs/`
  (`00-auditoria.md`, `01-plan-migracion.md`, `03-cierre.md`) mencionaban
  estos archivos sin prefijo de carpeta — como ahora son vecinos de
  verdad, esas menciones ya eran correctas y no se tocaron.
- **El log de errores se movió a una carpeta propia, `logs/`**: antes era
  `web/logs_app.log`, sin ninguna regla de `.htaccess` que lo bloqueara (un
  descuido menor — un error con detalle técnico habría quedado accesible
  si alguien adivinaba el nombre). Ahora es `web/logs/app.log`, con su
  propio `.htaccess` (`Require all denied`, mismo criterio que
  `almacen_pdf/` y `respaldos_pdf/`). Se actualizó la ruta en los dos
  lugares que la fijan (`app/bootstrap.php` y el bloque de compatibilidad
  de `lib/db.php`, que corre primero en cualquier request y si no se
  actualizaba también habría pisado el cambio) y la entrada del
  `.gitignore` del repo.
- **Lo que se evaluó mover y se decidió dejar en la raíz**, con la razón
  de cada uno:
  - `index.php` / `.htaccess` — son el punto de entrada y la configuración
    del servidor; tienen que vivir en la raíz del sitio.
  - `config.php` / `config.ejemplo.php` — `lib/db.php` resuelve la ruta a
    mano (`__DIR__ . '/../config.php'`); además `config.php` está
    gitignored con esa ruta exacta y la instalación documentada en
    `docs/README_SERVIDOR.md` asume que vive ahí.
  - `sw.js` / `manifest.webmanifest` — el "scope" de un service worker (lo
    que le permite a `pos.php` funcionar sin internet e instalarse como
    app) depende de que viva en la misma carpeta que las páginas que
    controla; moverlo rompe eso sin configurar un header extra.
  - `migrar.php` — su propio comentario documenta la URL real que un
    admin visita a mano (`https://admin.minimark.cl/migrar.php`); moverlo
    cambia esa dirección.
  - `procesar_cortes.php` — lo invoca un cron ya configurado en cPanel
    (fuera de este repo) apuntando a su ruta exacta en el servidor;
    moverlo rompería ese cron hasta reconfigurarlo a mano, algo que no se
    puede verificar desde acá.
  - `CLAUDE.md` — convención ya establecida (el `CLAUDE.md` de la raíz del
    repo dice explícitamente que la documentación de este proyecto vive en
    `web/CLAUDE.md`).

**Raíz de `web/` después de esta fase** (9 archivos, cada uno con una razón
para estar ahí): `.htaccess`, `CLAUDE.md`, `config.ejemplo.php`,
`config.php`, `index.php`, `manifest.webmanifest`, `migrar.php`,
`procesar_cortes.php`, `sw.js`.

Probado: `scripts/smoke.php` (32/32) y lint de todos los `.php` tocados
(solo cambiaron comentarios y dos líneas de `ini_set('error_log', ...)`,
nada de lógica). Documentado acá y en `web/CLAUDE.md`.

## Fase 21 · documentos/ — facturas por negocio/año/mes

**Estado: terminada en local (2026-11-09), pendiente de subir a producción.**

Pedido del dueño: centralizar los PDF de facturas y sus respaldos en una
sola carpeta, organizados para que vaya a escalar cuando se sumen más
negocios. Se discutió la propuesta antes de tocar nada (4 decisiones:
sin datos reales en el servidor todavía → se podía aplicar directo sin
una migración "en caliente"; año/mes según la fecha de la factura, no de
cuándo se sincroniza; carpeta de negocio por `slug` en vez de `id`; una
sola carpeta madre para facturas + respaldos).

**Estructura nueva**: `documentos/facturas/{slug}/{año}/{mes}/{uuid}.pdf`
(antes: `almacen_pdf/{negocio_id}/{uuid}.pdf`) y `documentos/respaldos/`
(antes: `respaldos_pdf/`, carpeta separada en la raíz). Una sola regla
`Require all denied` en `documentos/.htaccess` protege las dos
subcarpetas.

**Por qué el `slug` es seguro para usar como carpeta**: se confirmó
revisando `App\Models\Negocio::actualizar()` que el slug **nunca se toca
al editar** un negocio (solo se genera una vez, al crearlo, con sufijo
`-2`/`-3` si hay colisión) — así que es tan estable como el `id`, pero
legible si alguna vez hay que entrar a mirar los archivos a mano por
cPanel.

**Qué se tocó:**
- `config.ejemplo.php` / `config.php` — `carpeta_pdf` pasa a apuntar a
  `documentos/facturas` (el código que la usa, `api/facturas.php` y
  `VerPdfController`, no cambió: ya leían el valor desde `config.php`, no
  tenían la ruta vieja escrita a mano).
- `api/facturas.php` — arma la carpeta con el `slug` del negocio (ahora
  se trae con un JOIN en la consulta que ya resolvía la máquina por su
  token) y el año/mes de `datos.fecha`. Se adelantó la consulta que
  detecta si la factura ya existía (antes vivía más abajo, dentro de la
  transacción) para poder comparar la `ruta_pdf` vieja contra la nueva:
  si una edición cambia la fecha a otro mes, el PDF se guarda en la
  carpeta nueva y el archivo viejo se borra (evita huérfanos). El cliente
  Python no necesita ningún cambio — sigue mandando el mismo payload de
  siempre.
- `scripts/respaldo_pdfs.php` — la ruta de respaldo pasa de
  `respaldos_pdf/` (hardcodeada) a `documentos/respaldos/` (sigue
  hardcodeada: nunca fue configurable y no hacía falta agregarle esa
  flexibilidad para este cambio).
- `migrar.php` — nuevo paso idempotente que reubica los PDF que hayan
  quedado en `almacen_pdf/` (la carpeta vieja) a la estructura nueva y
  actualiza `ruta_pdf` en la base de datos; si una factura ya tiene la
  `ruta_pdf` en formato nuevo (3 barras en vez de 1) la salta. Al
  terminar, si `almacen_pdf/` quedó vacía (incluido su propio
  `.htaccess`, que ya no cumple ningún propósito), se borra sola.
- `docs/CONTRATO.md` — actualizada la sección que documenta dónde guarda
  el PDF el servidor (el cliente Python nunca construye esta ruta, pero
  el contrato la documenta igual como referencia).
- `docs/DESPLIEGUE.md` — el paso de permisos (chmod) ahora habla de
  `documentos/` en vez de `almacen_pdf/`. `docs/README_SERVIDOR.md` NO se
  tocó: es un documento histórico de la Fase 1, marcado como tal arriba
  del todo, reemplazado por `DESPLIEGUE.md`.
- `web/CLAUDE.md` — árbol de archivos actualizado.

**Nota aparte, no corregida en esta fase**: revisando `docs/DESPLIEGUE.md`
para este cambio se notó que su lista de "archivos a subir" (sección 1)
quedó desactualizada por la Fase 19 — menciona varios archivos puente que
ya no existen (`pos.php`, `producto_form.php`, `usuarios.php`, etc.) y no
menciona `index.php`/`app/`/`.htaccess`, que ahora son imprescindibles.
Haría falta una revisión completa de esa lista antes del próximo
despliegue real.

Probado localmente: se corrió `migrar.php` dos veces seguidas (confirma
que reubicar los 6 PDF de prueba es idempotente — la segunda vez no
mueve nada); se verificó a mano que `ver_pdf.php` sigue sirviendo el PDF
con la ruta nueva; se generó un token de máquina de prueba y se simuló
una sincronización real contra `api/facturas.php` (alta con fecha de
marzo, edición de la misma factura con fecha de abril) comprobando que:
el PDF queda en la carpeta correcta según la fecha, la edición mueve el
archivo y borra el de la carpeta vieja sin dejar huérfanos, el
`uuid_local` sigue sin duplicar la factura (upsert), y un token inválido
se sigue rechazando; se corrió `scripts/respaldo_pdfs.php` y generó el
zip en la carpeta nueva; `scripts/smoke.php` (32/32) y lint de todos los
`.php` tocados.

---

## Lo que NO cambia en ningún momento

- Las URLs del POS (`pos.php`, `caja.php`, etc.) — mismas direcciones.
- El comportamiento ya probado: promociones, vuelto, aviso de caja abierta, PIN,
  venta sin internet, idempotencia por `uuid`.
- El contrato con la app de Python (`CONTRATO.md`) — no se toca sin coordinar.
- `main` sigue siendo lo único en producción hasta que el dueño apruebe publicar.

---

**Esperando tu aprobación para empezar por la Fase 3.**
