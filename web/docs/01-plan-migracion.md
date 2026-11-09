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

**Nota aparte, no relacionada con esta migración**: durante las pruebas se
detectó que el XAMPP local tiene `date.timezone = Europe/Berlin` en vez de
la zona horaria de Chile — un gasto creado con la fecha "de hoy" quedaba al
día siguiente según el reloj de PHP. Es una configuración del entorno local
(`php.ini`), no un bug del código (viejo o nuevo: ambos llaman `date()` de
la misma forma) — queda pendiente corregir el `php.ini` local si molesta
para pruebas futuras cerca de la medianoche.

---

## Lo que NO cambia en ningún momento

- Las URLs del POS (`pos.php`, `caja.php`, etc.) — mismas direcciones.
- El comportamiento ya probado: promociones, vuelto, aviso de caja abierta, PIN,
  venta sin internet, idempotencia por `uuid`.
- El contrato con la app de Python (`CONTRATO.md`) — no se toca sin coordinar.
- `main` sigue siendo lo único en producción hasta que el dueño apruebe publicar.

---

**Esperando tu aprobación para empezar por la Fase 3.**
