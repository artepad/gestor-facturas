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
   - ⏳ **2b — `pos_api.php`** (cobrar, anular, espera, movimiento,
     recaudación). El corte más delicado de toda la migración: cada acción se
     prueba una por una antes de seguir. Es el siguiente paso.
3. `inventario.php`, `compras.php`, `promociones.php` (módulos opcionales, menor
   riesgo porque hoy inventario está apagado).
4. `servicios.php` — se aprovecha este corte para integrar Mercado Pago en vez de
   Klap, ya que de todas formas se reescribe esa pantalla.

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
