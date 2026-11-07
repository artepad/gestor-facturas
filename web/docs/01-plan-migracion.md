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

En este orden, cada uno con su prueba:

1. H1 — Bloqueo de intentos de login.
2. H2 — Validar formato UUID en la subida de PDFs.
3. H4 — Fijar `display_errors` + página de error genérica.
4. H6 — Las dos consultas sin preparar (bajo esfuerzo, se hace de paso).
5. H3 — CSRF en los formularios (el que más archivos toca; se hace al final de esta
   fase porque conviene hacerlo a la vez que cada pantalla se migra en la Fase 6, no
   dos veces).
6. H5 — Mover los utilitarios de un solo uso fuera de la carpeta pública.
7. H7 — Respaldo automático de `almacen_pdf/` (esto es un cambio de cron en el
   servidor, no de código; se coordina aparte).

## Fase 6 · Migración del POS, pantalla por pantalla

Orden sugerido (de menor a mayor riesgo):

1. `lib/pos.php` → se separa en Modelos (`Venta`, `CajaSesion`, `Promocion`,
   `Convenio`, `Stock`, `Compra`) — es la base de todo lo demás.
2. `caja.php` (abrir/cerrar turno, arqueo).
3. `pos.php` + `pos_api.php` (la pantalla de venta — la más usada, se migra con más
   cuidado y más pruebas).
4. `inventario.php`, `compras.php`, `promociones.php` (módulos opcionales, menor
   riesgo porque hoy inventario está apagado).
5. `servicios.php` — se aprovecha este corte para integrar Mercado Pago en vez de
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
