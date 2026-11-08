# Cierre de la migración de arquitectura del POS

Fase 10 del plan (`01-plan-migracion.md`). La migración migró **a propósito
solo el POS** — 7 pantallas — no el resto del panel. Este documento cierra el
proyecto: revisión final, pendientes explícitos y una guía corta de
mantenimiento para seguir trabajando después.

## 1. Revisión final (tabla 3.6 del estándar)

Solo evaluada sobre las 7 pantallas migradas (`pos.php`, `pos_api.php`,
`caja.php`, `inventario.php`, `compras.php`, `promociones.php`,
`servicios.php`); varios ítems (contraseñas, fuerza bruta, errores sin
detalle) ya eran del sitio completo desde la Fase 5 y se heredan sin cambios.

| # | Riesgo | Estado | Nota |
|---|---|---|---|
| 1 | Acceso al código/credenciales | ✅ | `config.php` fuera de Git. `web/` sigue siendo el docroot (no `public/`, ver más abajo) — mitigado con `.htaccess` que deniega archivos ocultos y carpetas de datos |
| 2 | Inyección SQL | ✅ | Prepared statements en Modelos y en `lib/pos.php` |
| 3 | XSS | ✅ | `h()`/`e()` en cada salida de las 7 pantallas |
| 4 | CSRF | ✅ (solo POS) | H3 cerrado en las 7 pantallas. El resto del panel no lo tiene — no estaba en el alcance acordado |
| 5 | Robo de sesión | ✅ | Ya existía sitewide (httponly, samesite, regenerate al login) |
| 6 | Contraseñas | ✅ | Ya existía sitewide (`password_hash`/`password_verify`) |
| 7 | Fuerza bruta | ✅ | H1 (Fase 5), sitewide — `intentos_login`, bloqueo 5 fallos/15 min |
| 8 | "Mantener sesión" | N/A | El proyecto no usa ese patrón: sesión de 30 días + PIN local (decisión de diseño documentada en POS_DISENO.md) |
| 9 | Recuperación de contraseña | N/A | No hay autoservicio; el admin resetea a mano con `crear_admin_form.php` |
| 10 | Subidas de archivos | ✅ | Fase 8: el PDF de facturas valida cabecera real + tope de tamaño; el Excel del catálogo ya estaba bien |
| 11 | Datos manipulados por el cliente | ✅ | El servidor siempre recalcula totales, promociones y precios |
| 12 | Spam en formularios públicos | N/A | No hay formularios sin sesión |
| 13 | Autorización | ✅ | `exigirPermiso()` en cada Controlador |
| 14 | Redirecciones | ✅ | Todas a rutas internas fijas |
| 15 | Fuga de información | ✅ | H4 (Fase 5), sitewide — errores a log, nunca en pantalla en producción |
| 16 | Cabeceras HTTP | ⏳ **[R] pendiente** | No se agregaron `X-Content-Type-Options`/`X-Frame-Options`/`Referrer-Policy`. Solo se tocó `.htaccess` para el tipo MIME del manifest |
| 17 | CSP y HSTS | ⏳ **[R] pendiente** | No implementado — el estándar lo marca opcional |
| 18 | Archivos sensibles expuestos | ✅ | `.htaccess` deniega ocultos; `almacen_pdf/`, `respaldos_pdf/` bloqueados |
| 19 | Instalador web temporal | ✅ parcial / ⏳ resto | `setup.php` y `crear_admin.php` ya se borraron (2026-11-08). Quedan `crear_admin_form.php` y `reset_facturas.php` en el servidor — borrarlos cuando el dueño lo decida |
| 20 | Datos personales | ✅ | IP siempre hasheada (`ip_hash`), nunca en claro |
| 21 | Archivos privados | ✅ | PDFs fuera de acceso directo, servidos solo por `ver_pdf.php` con control de negocio |
| 22 | Respaldos | ⏳ **pendiente del dueño** | El script de respaldo de PDFs existe (`scripts/respaldo_pdfs.php`) pero falta agregar el cron en cPanel; el respaldo de la base de datos es manual (phpMyAdmin), no automático |
| 23 | Dependencias | N/A | Sin dependencias (sin Composer, a propósito) |

## 2. Por qué `web/` sigue siendo el docroot (no `public/`)

El estándar genérico asume que al llegar al despliegue *todo* el sitio ya está
en la arquitectura nueva, y por eso el despliegue consiste en apuntar el
dominio a `public/`. Acá no aplica: solo se migró el POS. Cambiar el docroot
habría roto el resto del panel (nunca se movió a `public/`). En su lugar, cada
pantalla del POS quedó como un archivo puente en la raíz de `web/` que llama
directo a su Controlador — mismas direcciones de siempre, cero cambio de
docroot. El detalle completo está en la Fase 9 de `01-plan-migracion.md`.

**Consecuencia práctica**: `app/`, `lib/`, `docs/`, `scripts/` siguen siendo
técnicamente accesibles por el docroot actual (protegidos por sus propios
`.htaccess` de "denegar todo"), a diferencia de la disposición dividida ideal
del estándar (todo fuera de `public_html/`). Si en el futuro se migra el resto
del panel y se decide pasar el dominio a `public/`, ahí sí se puede mover
`app/`, `lib/`, `docs/`, `scripts/` fuera de la carpeta pública de verdad.

## 3. Lecciones para la próxima migración (si se migra el resto del panel)

1. **Preguntar el alcance real antes de nombrar la última fase "despliegue
   genérico"**: el estándar da por sentado una migración completa del sitio.
   Cuando el alcance es parcial (como acá, solo POS), la Fase 9 necesita un
   enfoque distinto (archivos puente) en vez del cambio de docroot — vale la
   pena decidirlo explícitamente ni bien se define el alcance, no al llegar a
   la Fase 9.
2. **El patrón de archivo puente funcionó bien** para una migración parcial:
   cero cambio de URL, reversible pantalla por pantalla, sin tocar nada del
   resto del sitio. Si se migra una pantalla más del panel, conviene seguir el
   mismo patrón en vez de reabrir la pregunta del docroot.
3. **Nada se había subido a producción hasta el final**: funcionó (se probó
   todo en local primero, con datos reales de solo lectura cuando hacía
   falta), pero significó que la Fase 9 terminó siendo una subida de 115
   archivos de una vez, no incremental. Para la próxima, conviene evaluar si
   conviene subir a un entorno de prueba real en el hosting antes del cierre,
   en vez de acumular todo para el final.
4. **El envoltorio de Modelos sobre `lib/pos.php`** (en vez de reescribir el
   SQL) redujo mucho el riesgo de cada corte, a costa de que la capa de
   Modelo no es "pura" todavía (el SQL real sigue en `lib/`). Es una decisión
   consciente, no un olvido — déjala así hasta que una pantalla se reescriba
   de verdad, no "por prolijidad".

## 4. Guía corta de mantenimiento

**Si algo se ve mal en una de las 7 pantallas del POS**: el archivo puente
(`caja.php`, etc.) casi nunca es el problema — mirar el Controlador en
`app/Controllers/`. Si el dato está mal calculado, el problema probablemente
está en `lib/pos.php` (ahí vive el SQL real), no en el Modelo.

**Si necesitas revertir una pantalla del POS a como estaba antes de la
migración**: cada archivo puente se puede restaurar solo, sin afectar a las
demás — `git log -- web/caja.php` (o el archivo que corresponda) para
encontrar el commit anterior a la Fase 9 y restaurarlo con
`git show <commit>:web/caja.php > web/caja.php`.

**Si quieres agregar una función nueva a una pantalla del POS ya migrada**: ver
«Arquitectura del POS (capas)» en `web/CLAUDE.md` — resumen: la función real va
en `lib/pos.php`, el Modelo la envuelve, el Controlador la expone, la Vista
agrega el formulario con `campo_csrf()`.

**Si quieres migrar otra pantalla del panel** (facturas, fiados, ingresos,
gastos, etc.) **a esta misma arquitectura**: es una decisión nueva, no algo que
quedó a medias. Seguir el mismo patrón de corte por corte de la Fase 6 en
`01-plan-migracion.md`, empezando por su propia ficha de corte (plantilla 6.2
del estándar).

**Pendientes que quedaron anotados y no se resolvieron en este cierre** (todos
de bajo riesgo, no bloquean nada):
- Cabeceras de seguridad HTTP (`X-Content-Type-Options`, etc.) — ítem 16 de la
  tabla.
- Borrar del servidor los utilitarios de un solo uso que quedan
  (`crear_admin_form.php`, `reset_facturas.php`) — decisión y acción del
  dueño. (`setup.php` y `crear_admin.php` ya se borraron, del repo y del
  servidor, 2026-11-08.)
- Agregar el cron de `scripts/respaldo_pdfs.php` en cPanel — decisión y acción
  del dueño.
- Limpieza de PDFs huérfanos de facturas eliminadas — pendiente de que el
  dueño defina una política de retención (ver Fase 8).
- Borrado lógico ampliado a más tablas (hoy solo `facturas` lo tiene) y fechas
  en UTC — evaluados y descartados a propósito por ahora (ver Fase 7); quedan
  como mejoras futuras, no como deuda urgente.
