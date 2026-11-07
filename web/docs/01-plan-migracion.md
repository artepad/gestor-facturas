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

## Fase 7 en adelante

Base de datos (índices, borrado lógico donde falte), despliegue y cierre — según el
estándar general, una vez terminado el POS.

---

## Lo que NO cambia en ningún momento

- Las URLs del POS (`pos.php`, `caja.php`, etc.) — mismas direcciones.
- El comportamiento ya probado: promociones, vuelto, aviso de caja abierta, PIN,
  venta sin internet, idempotencia por `uuid`.
- El contrato con la app de Python (`CONTRATO.md`) — no se toca sin coordinar.
- `main` sigue siendo lo único en producción hasta que el dueño apruebe publicar.

---

**Esperando tu aprobación para empezar por la Fase 3.**
