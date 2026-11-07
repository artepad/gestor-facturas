# Auditoría · Módulo POS y base compartida de `web/`

**Fecha:** 2026-11-07 · **Rama:** `migracion-arquitectura` (desde `pos`) · **Alcance:** el
POS (`pos.php`, `pos_api.php`, `caja.php`, `inventario.php`, `compras.php`,
`promociones.php`, `servicios.php`, `lib/pos.php`) y el núcleo que usa sin excepción
(`lib/db.php`, `lib/auth.php`, `lib/permisos.php`, `lib/ui.php`, `lib/auditoria.php`,
el endpoint de sincronización `api/facturas.php`). Los demás módulos web (Fiados,
Ingresos, Gastos, Frutas y Verduras, Herramientas) se mencionan solo donde comparten
código con el POS; se auditan en detalle cuando les toque su turno.

## 1. Resumen

🟡 **Riesgo medio-bajo.** El código funciona bien y ya tiene las protecciones básicas
correctas (SQL preparado, salida escapada, sesión segura, contraseñas con hash,
permisos por rol, `config.php` fuera de Git). Lo que falta es lo que un PHP plano sin
capas normalmente no tiene: límite de intentos de login, CSRF explícito, página de
error genérica, y una validación que falta en la subida de PDFs. Nada de esto se ha
explotado — es una lista de "cerrar antes de crecer", no una alarma.

## 2. Ficha técnica

| | |
|---|---|
| Lenguaje | PHP 8.4, sin framework ni Composer |
| Base de datos | MySQL/MariaDB, PDO con `ATTR_EMULATE_PREPARES => false` |
| Hosting | HostGator compartido, cPanel, despliegue manual por FTP/Administrador de archivos |
| Tamaño del POS | ~6.000 líneas (10 archivos) |
| Tamaño de `web/` completo | ~14.600 líneas (76 archivos PHP) |
| Dependencias externas | Ninguna (ni Composer ni npm) — `zxing.min.js` vendorizado a mano |
| Respaldo de BD | ✅ Automático diario (cron, 16+ días de historial, sin errores) |
| Respaldo de PDFs (`almacen_pdf/`) | ❌ Manual únicamente (recién hecho hoy) |

## 3. Inventario de funcionalidades (POS)

| Pantalla/endpoint | Qué hace | Quién la usa | Riesgo | Corte sugerido |
|---|---|---|---|---|
| `pos.php` | Vender: carrito, cobro, tickets en espera, F6-F12 | admin (vendedor cuando se habilite) | medio | 3 |
| `pos_api.php` | JSON: buscar, cobrar, anular, movimientos, recaudación | igual | medio | 3 |
| `caja.php` | Abrir/cerrar turno, entradas/salidas, arqueo | igual | medio | 2 |
| `inventario.php` | Stock, ajustes, mermas, alertas (opcional, apagado) | admin | bajo | 4 |
| `compras.php` | Mercadería recibida, suma stock | admin | bajo | 4 |
| `promociones.php` | Ofertas automáticas (pack/nxm/precio/%) | admin | bajo | 4 |
| `servicios.php` | Saldo y comisiones de convenios (Klap → se migra a Mercado Pago) | admin | medio | 5 |
| `lib/pos.php` | TODA la lógica: caja, ventas, promos, stock, convenios | — | alto (crítico) | 1 |
| `api/facturas.php` | Recibe facturas del cliente Python (no es POS, pero comparte infraestructura) | máquinas con token | medio | — (fuera de este alcance, coordinar con `CONTRATO.md`) |

## 4. Hallazgos de seguridad

| # | Severidad | Dónde | Qué pasa | Cómo se explotaría | Corrección | Esfuerzo |
|---|---|---|---|---|---|---|
| H1 | **Alta** | `lib/auth.php:32-49` (`login()`) | Sin límite de intentos fallidos por correo/IP | Probar contraseñas sin freno (fuerza bruta) | Tabla `intentos_login`, bloqueo tras 5 fallos en 15 min | Bajo |
| H2 | **Media** | `api/facturas.php:80-84` | `uuid_local` (viene del cliente, autenticado por token) se usa como nombre de archivo sin validar que sea un UUID real | Con un token de máquina válido, intentar `../../otra_carpeta` en el nombre | Validar formato UUID (`preg_match`) antes de construir la ruta | Bajo |
| H3 | **Media** | Todo `web/` | Sin token CSRF en formularios POST (`caja.php`, `negocio_form.php`, `pos_api.php`, etc.) | Mitigado en gran parte por `cookie_samesite=Lax`, pero no es protección completa | Token de sesión + campo oculto en cada POST (patrón de la Fase 5) | Medio (muchos formularios) |
| H4 | **Media** | Todo `web/` | `display_errors` depende de la configuración de PHP del hosting, no la fija el código | Un error no controlado podría mostrar detalles técnicos (rutas, consultas) si cPanel lo tiene en `On` | Fijar `display_errors=0` + `error_log` propio + página 500 genérica | Bajo |
| H5 | **Media** | Utilitarios de un solo uso (`setup.php`, `crear_admin_form.php`, `migrar.php`, `reset_facturas.php`) | Quedan en el servidor después de usarse; dependen de que el dueño se acuerde de borrarlos | Si alguno queda, su `setup_key` en la URL es la única defensa | Moverlos a `scripts/` (fuera de la carpeta pública) cuando se migre a la arquitectura nueva | Medio (viene con la migración) |
| H6 | **Baja** | `cliente.php:125-126`, `categorias_gasto.php:41-42` | Dos consultas concatenan una variable `(int)` directo en el SQL en vez de parámetro preparado | No explotable (doble cast a entero), pero rompe la convención | Pasarlas a `$pdo->prepare()` | Muy bajo |
| H7 | **Media** | Operación, no código | `almacen_pdf/` (PDFs de facturas) no tiene respaldo automático, solo la base de datos | Pérdida del servidor = se pierden los PDFs escaneados | Agregar al cron un respaldo semanal del `.zip`, o replicarlo a otro lado | Bajo |

Nada de esto es explotable hoy sin credenciales válidas (sesión o token de máquina).
No encontré inyección SQL real, ni secretos en Git, ni archivos ejecutables expuestos.

## 5. Arquitectura y deuda técnica

- **Sin capas**: cada `.php` mezcla ruta, SQL, lógica y HTML. Funciona porque el
  equipo es chico y el código es disciplinado, pero crece mal (ya hay 76 archivos).
- **`lib/pos.php` concentra demasiado** (1.518 líneas, toda la lógica del POS). Es el
  primer candidato a separar en Modelos (`Venta`, `CajaSesion`, `Promocion`,
  `Convenio`, `Stock`) cuando se migre.
- **Sin pruebas automáticas** en `web/` (sí las hay en `src/`, la app de Python).
- **Patrón ya bueno y que conviene conservar**: `uuid` como clave de idempotencia
  (ventas, facturas), servidor siempre recalcula totales y promociones, PIN como
  cerrojo de terminal (no de seguridad fuerte, está documentado así a propósito).

## 6. Secretos

- `config.php` nunca estuvo en el historial de Git (confirmado). Está bien excluido
  desde el primer commit.
- El `.htaccess` bloquea `config.php`, `.sql`, `.md`, `.yaml`, archivos ocultos y
  `.git` — correcto y ya cumple el estándar nuevo en este punto.
- `almacen_pdf/.htaccess` y `lib/.htaccess` deniegan todo acceso directo — correcto.

## 7. Riesgos de la migración

- `lib/pos.php` es el corazón del negocio: cualquier cambio ahí se prueba con cuidado
  (ya hay un historial de bugs sutiles ahí — ver lección aprendida abajo).
- El POS **todavía no está en producción** (vive en la rama `pos`, no en `main`):
  eso reduce el riesgo real de esta migración a casi cero mientras no se publique.
- Las ventas, caja y promociones ya fueron probadas manualmente etapa por etapa; la
  migración debe conservar ese comportamiento exacto (ver "qué no tocar").

## 8. Qué no tocar / comportamientos sutiles a preservar

- El cerrojo de PIN usa `[hidden]{display:none!important}` a propósito — un bug real
  ya costó una sesión entera (el `display:flex` le ganaba al atributo `hidden`).
- Las promociones se recalculan en el servidor al cobrar, **nunca** se confía en lo
  que calculó el navegador.
- El aviso de "caja lleva N horas abierta" avisa, nunca bloquea la venta.
- El inventario nunca bloquea una venta por falta de stock (el saldo negativo se
  marca para revisar, pero la venta sigue).
- Los campos de dinero del POS aceptan solo números (filtro ya aplicado); no quitar
  esa validación al reescribir las vistas.

## 9. Preguntas para el dueño

1. ¿Confirmas que el cambio de Klap → Mercado Pago entra **después** de terminar la
   migración de arquitectura, como quedamos?
2. Para H1 (bloqueo de intentos de login): ¿5 intentos en 15 minutos te parece bien,
   o prefieres otro número?
3. Para H7 (respaldo de PDFs): ¿lo agrego al mismo cron que ya respalda la base de
   datos, o prefieres otro método?
