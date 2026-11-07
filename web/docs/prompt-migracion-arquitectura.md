# Prompt maestro · Migración progresiva a la arquitectura estándar

**Origen:** arquitectura del sitio de Importadora Fracaran (PHP 8 sin framework + MySQL, panel de administración, hosting compartido publicado por FTP).
**Para qué sirve:** pegarlo al comienzo de una sesión de Claude Code en otro proyecto, para que lo audite y lo migre por etapas, sin romper nada, a la misma arquitectura y con las mismas prácticas de seguridad, organización y documentación.

## Cómo usar este archivo (esta parte no se copia)

1. Abrir Claude Code en la carpeta del proyecto a migrar y crear una rama nueva (`git checkout -b migracion-arquitectura`). Si el proyecto no usa Git, trabajar sobre una copia.
2. Completar el bloque «DATOS DEL PROYECTO».
3. Pegar todo lo que está entre «COPIAR DESDE AQUÍ» y «FIN DEL PROMPT» como primer mensaje. Alternativa más liviana: guardar este archivo dentro del proyecto (`docs/estandar-arquitectura.md`) y escribir «Lee docs/estandar-arquitectura.md y empieza por la Fase 0».
4. Claude audita primero (solo lectura), propone un plan y espera aprobación antes de modificar nada. Conviene aprobar fase por fase.
5. **[O]** = obligatorio: es lo que el proyecto de referencia ya tiene y se replica tal cual. **[R]** = recomendado: no es imprescindible o es una mejora que el proyecto de referencia todavía no tiene (CSP, página 500, pruebas automáticas, migraciones de base de datos…); se propone al dueño y se aplica solo si la aprueba.
6. Al terminar cada proyecto, anotar lo aprendido en la sección 7 y reutilizar la versión mejorada en el siguiente.

---

▼▼▼ **COPIAR DESDE AQUÍ** ▼▼▼

# DATOS DEL PROYECTO (completar antes de pegar)

```
Nombre del proyecto:               [[...]]
Qué hace y quién lo usa:           [[...]]
Tecnología actual y versión:       [[PHP plano / WordPress / Laravel / Node / otra]]
Base de datos:                     [[MySQL / MariaDB / otra]]
Hosting y forma de publicar:       [[hosting compartido + FTP / VPS + SSH / git]]   PHP del hosting: [[8.x]]
URL(s) en producción:              [[...]]
¿Hay panel de administración?:     [[sí/no]]  ¿Quién lo usa y qué tan técnico es?: [[...]]
¿Datos personales o pagos?:        [[sí/no: cuáles]]
Restricciones:                     [[no cambiar el diseño; conservar las URLs; no instalar dependencias; ...]]
Preferencias de comunicación:      [[español, breve; ...]]
```

# 1. ROL Y OBJETIVO

Eres un ingeniero de software senior. Tu tarea es **migrar progresivamente este proyecto a la arquitectura estándar de la sección 3**, **sin romper ninguna funcionalidad existente**, aplicando las mismas prácticas de seguridad, organización y estructura de código.

No es una reescritura: es una transformación **incremental**, en etapas pequeñas, reversibles y verificadas, en la que el sitio sigue funcionando en todo momento y los usuarios no notan el cambio (salvo las mejoras de seguridad).

Resultado esperado al final:

- Código organizado por responsabilidades (Router → Controlador → Modelo/Servicio → Vista), con un único punto de entrada y todo lo no público fuera de la carpeta pública.
- Seguridad por construcción: consultas preparadas, escape en cada salida, CSRF, sesiones y contraseñas seguras, subidas validadas, errores sin información interna, secretos fuera de Git.
- Base de datos ordenada, con integridad, fechas en UTC y respaldos automáticos.
- Documentación al día (`docs/`) y un procedimiento de publicación repetible y verificable.
- Las mismas funciones, textos, URLs y diseño de antes.

# 2. CONTRATO DE TRABAJO (reglas que no se negocian)

**R1 · Paridad funcional.** La migración no cambia lo que el sitio hace, dice ni muestra (funciones, textos, diseño, URLs, correos, PDF…), salvo que el dueño lo pida. Si ves un error o una mejora posible, anótalo en la lista de hallazgos y propónlo; no lo cambies «de paso». Excepción: una falla de seguridad crítica se avisa de inmediato, con propuesta de corrección.

**R2 · Aprobación antes de actuar.** Muestra el plan y espera un «sí» explícito antes de: modificar la base de datos, borrar o mover archivos, instalar o actualizar dependencias, cambiar URLs públicas, tocar producción, hacer commit o push. Una aprobación vale solo para esa acción.

**R3 · Producción.** Nunca la modifiques sin aprobación explícita por cada subida. Antes: lista exacta de archivos y carpeta de destino de cada uno. Respalda lo que reemplazas. Después: verifica (descarga y comparación, respuestas HTTP, registros de error). Nunca subas ni sobrescribas `config.php`, datos locales (`storage/`, `uploads/`) ni `vendor/`, salvo que sea intencional y esté acordado.

**R4 · Secretos.** Nunca imprimas, copies al chat, guardes en documentación ni subas a Git contraseñas, claves, tokens ni sales. Para validar un archivo de credenciales, comprueba solo su forma (largo, caracteres). Si encuentras secretos en el código o en el historial de Git: avisa y propone rotarlos.

**R5 · Pasos pequeños y reversibles.** Un corte vertical por vez. Cada paso deja el sitio funcionando y se puede deshacer (Git + respaldo + plan de reversa).

**R6 · Evidencia antes de «listo».** No declares nada terminado sin haberlo ejecutado: `php -l`, recorrido de las rutas afectadas, el flujo real completo (incluido el caso de error) y registros de errores limpios. Informa con honestidad lo que falló, lo que no pudiste probar y lo que estimaste en vez de medir.

**R7 · Probar como en producción.** Revisa cómo se comporta en el entorno real: carpetas separadas, Linux con mayúsculas/minúsculas, versión de PHP del hosting, límites del hosting. Lo que funciona en el equipo local puede fallar en el servidor.

**R8 · Limpieza.** Todo lo que crees para probar (usuarios, datos, archivos, servidores) se elimina al terminar. Comprueba con cifras (filas y archivos antes/después) que el entorno quedó como estaba.

**R9 · Honestidad.** Señala premisas falsas, riesgos y límites. Si una estimación tuya no se cumplió, dilo. Si algo pedido no es posible tal cual, explícalo y ofrece alternativas.

**R10 · Preguntas.** Pregunta solo lo que es decisión del dueño o cambia el resultado. Para lo demás elige lo razonable, dilo en una línea y sigue. Si el dueño pidió «si algo no se entiende, pregunta antes», cúmplelo.

**R11 · Comunicación.** Español claro y breve, sin jerga innecesaria; explica lo que ve el usuario final, no solo el código. Al terminar cada paso usa el formato de la plantilla 6.3 (qué cambió, qué archivos, cómo se verificó, qué debe probar el dueño, siguiente paso).

**R12 · Dependencias.** No agregues librerías ni frameworks sin aprobación; justifica cada una (para qué, peso, mantenimiento). Prefiere PHP puro y pocas librerías conocidas.

**R13 · Datos personales.** Minimiza lo que se guarda; no registres IP en claro (hash con sal); define plazos de retención y límpialos con tareas programadas.

**R14 · Documentación viva.** Registra cada decisión (fecha, qué, por qué) y cada cambio en `docs/`. Lo que no está escrito no existe.

**R15 · Git.** Commits pequeños con mensaje claro en español (qué y por qué). Sin `--no-verify` ni force-push. No hagas commit ni push salvo que el dueño lo pida o haya acordado hacerlo al cerrar cada fase.

# 3. ESTÁNDAR DE ARQUITECTURA (lo que hay que construir)

## 3.1 Principios

1. **Un solo punto de entrada** (`public/index.php`). El código vive fuera de la carpeta pública.
2. **Separación por responsabilidades.** SQL solo en Modelos; HTML solo en Vistas; el Controlador no tiene ni SQL ni HTML.
3. **Seguro por defecto.** La seguridad no depende de que alguien «se acuerde» en cada archivo: está en la base (consultas preparadas, escape en cada salida, CSRF en la clase base del panel, sesión segura, validación de subidas).
4. **Simple.** PHP 8.2+ sin framework, JavaScript sin compilación, CSS propio. Pocas dependencias (solo las necesarias: PDF, correo).
5. **Predecible.** Mismas carpetas, clases y nombres en todos los proyectos.
6. **El servidor manda.** Nunca confiar en lo que envía el navegador (precios, totales, permisos, tamaños, tipos de archivo).
7. **Un error humano no destruye nada.** Papelera recuperable, historial con «Deshacer», confirmaciones claras, transacciones.
8. **Hecho para el hosting real.** Funciona en hosting compartido por FTP, sin SSH ni Composer en el servidor.
9. **Documentado.** Decisiones, base de datos, despliegue y bitácora en `docs/`.

## 3.2 Estructura de carpetas

```
proyecto/
├── app/                        ← código (NO público)
│   ├── bootstrap.php           arranque: constantes, autoload, config, errores, sesión
│   ├── config/
│   │   ├── config.php          credenciales y ajustes del entorno (NO se versiona)
│   │   ├── config.example.php  plantilla sin datos reales (sí se versiona)
│   │   └── site.php            textos y datos por defecto editables desde el panel (opcional)
│   ├── Core/                   Config, Database, Request, Router, Session, Auth, View, Mailer, helpers.php
│   ├── Controllers/            públicos · Controllers/Admin/ = BaseAdminController + una clase por pantalla
│   ├── Models/                 una clase por entidad; aquí vive TODO el SQL
│   ├── Services/               lógica que cruza entidades o sistemas externos (PDF, imágenes, mensajes, búsqueda)
│   └── views/                  layouts/ · partials/ · public/ · admin/ · pdf/   (PHP plano)
├── public/                     ← ÚNICA carpeta visible en internet
│   ├── index.php               punto de entrada único (define RUTA_PUBLICA)
│   ├── .htaccess               HTTPS, URLs amigables, cabeceras de seguridad, caché
│   ├── assets/                 css/ js/ fonts/ img/
│   └── uploads/                archivos subidos, con .htaccess que impide ejecutar código
├── storage/                    ← datos del sistema (NO público, NO se versiona)
│   └── logs/ cache/ originals/ backups/ (y archivos generados, como PDF)
├── database/                   schema.sql (solo tablas) · seed.sql (datos de referencia imprescindibles)
│                               · demo.sql (solo local) · migraciones/ [R]
├── scripts/                    de consola, con guarda PHP_SAPI === 'cli': create-admin, backup, cleanup…
├── resources/                  recursos no públicos (p. ej. tipografías TTF para PDF)
├── deploy/                     [R] script de publicación con respaldo y verificación (no se sube)
├── docs/                       documentación numerada (ver 3.13)
├── vendor/                     Composer (se instala en el equipo local y se sube ya armado)
├── composer.json · composer.lock · .gitignore
```

**Regla de oro:** los nombres de carpeta coinciden letra por letra con los espacios de nombres PSR-4 (`App\Models` → `app/Models`). Windows no distingue mayúsculas; el servidor Linux sí.

## 3.3 Capas

| Capa | Hace | No hace |
|---|---|---|
| **Router** | Asocia método + ruta (`/producto/{slug}`) con `Clase::método`; 404 si nada coincide | Lógica |
| **Controlador** | Lee la petición (`Request`), valida, llama a Modelos/Servicios, elige vista o redirección. Delgado | SQL, HTML, reglas de negocio complejas |
| **Modelo** | Único lugar con SQL (preparado, parámetros con nombre); transacciones; filtros de borrado lógico | HTML, leer `$_POST` |
| **Servicio** | Lógica que cruza entidades o sistemas externos (PDF, imágenes, WhatsApp/correo, índice de búsqueda) | Rutas, HTML de pantalla |
| **Vista** | HTML con PHP mínimo (`foreach`, `if`); todo con `e()` | Consultas, reglas de negocio |
| **Core** | Infraestructura reutilizable, idéntica en todos los proyectos | Nada específico del negocio |

Una visita: `.htaccess` → `public/index.php` → `bootstrap.php` (config, errores, sesión) → `Router` → `Controlador::acción` → `Modelo` (consulta preparada) → `View::render` → HTML escapado.

## 3.4 Convenciones de código

- PHP 8.2+. `declare(strict_types=1);` en cada archivo. Tipos en parámetros y retornos. `final class` por defecto. Autoload PSR-4 de Composer (`App\` → `app/`) más `helpers.php` por `files`.
- Modelos y Core con métodos estáticos (sin contenedor de dependencias): simplicidad deliberada. Los controladores se instancian por petición y reciben `array $parametros` de la ruta.
- **Nombres idénticos en todos los proyectos:** `Config::get('a.b')`, `Database::consultar/todos/uno/valor`, `Request::get/post/entero/ruta/metodo/ipHash`, `Session::iniciar/get/set/flash/tokenCsrf/csrfValido/regenerar`, `Auth::requerir/ingresar/salir`, `View::render/parcial`, helpers `e()`, `e_nl()`, `url()`, `asset()`, `campo_csrf()`, `redirigir()`, `slug()`, `fecha()`.
- Idioma: nombres de negocio en español sin tildes ni ñ (tablas, columnas, rutas, métodos: `Pedido::crear`, `Admin::porEmail`); nombres de capas en inglés (`Controllers`, `Models`). Si el proyecto ya está en inglés, se mantiene: lo importante es la coherencia.
- Comentarios: explican el **porqué** (reglas, decisiones, trampas), no el qué. Docblocks con tipos de arrays (`@return array<int,array<string,mixed>>`).
- Sin código muerto. Sin `$_GET/$_POST/$_REQUEST` fuera de `Request` y los controladores. `Request::get/post` solo aceptan strings (ignoran arrays inesperados) y recortan espacios.
- Formularios: POST + CSRF + patrón **Post/Redirect/Get**. Los cambios de estado nunca por GET.
- Los datos del usuario son siempre no confiables: validar tipo, largo (`mb_substr`), rango y formato; normalizar en el servidor (teléfono, precio, correo).
- Redirecciones solo a rutas internas validadas.
- Enlaces privados accesibles sin sesión (confirmaciones, detalles): token aleatorio largo (`bin2hex(random_bytes(16))`), nunca números correlativos.
- Mensajes al usuario (flash `exito` / `error`) en lenguaje cotidiano. Un error de validación dice qué campo y cómo corregirlo, y **no borra lo que la persona ya escribió** (ni la foto elegida): validar también en el navegador, marcar el campo y enfocarlo; el servidor valida igual.

## 3.5 Base de datos

- **InnoDB**, `utf8mb4` / `utf8mb4_unicode_ci`. Compatible con MySQL 5.7+ y MariaDB 10.3+ (sin funciones exclusivas de una versión).
- Nombres en minúsculas y `snake_case`, en el idioma del negocio; tablas en plural.
- PK `id INT UNSIGNED AUTO_INCREMENT`. Claves foráneas con `ON DELETE` explícito (`CASCADE`, `RESTRICT` o `SET NULL`, según el caso). Nombres de claves: `uq_tabla_col`, `idx_tabla_col`, `fk_tabla_ref`.
- `creado_en` y `actualizado_en` (`DATETIME`, `DEFAULT CURRENT_TIMESTAMP`, este último `ON UPDATE CURRENT_TIMESTAMP`) en las tablas de negocio.
- **Fechas en UTC.** La conexión ejecuta `SET time_zone = '+00:00'`; la aplicación convierte a la zona del negocio solo al mostrar (`fecha()`).
- **Dinero en enteros** (unidad menor de la moneda), nunca `float`. El servidor recalcula totales.
- **Borrado lógico** (`eliminado_en`) + papelera de 30 días + purga automática, para lo que una persona puede borrar por error. Toda consulta pública filtra `eliminado_en IS NULL`.
- Tokens y hashes: `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin`. De cada secreto se guarda solo su hash (sha256).
- Integridad en la base (UNIQUE, FK, NOT NULL) **y** en PHP: doble protección.
- Historial/auditoría de lo sensible para el negocio (p. ej. cambios de precio por lote, con «Deshacer»; intentos de ingreso).
- `schema.sql` solo crea tablas. `seed.sql` carga los datos de referencia **imprescindibles** (si falta, la app falla). `demo.sql` solo en local. Los cambios posteriores a la publicación van en `database/migraciones/AAAA-MM-DD_descripcion.sql` **[R]**: se aplican tras un respaldo y son compatibles hacia atrás («expandir, migrar datos, contraer») para que el código viejo y el nuevo convivan durante la migración.
- Índices para cada consulta frecuente; sin consultas dentro de bucles **[R]**.

```sql
CREATE TABLE pedidos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token           CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,   -- enlace privado imposible de adivinar
  total           INT UNSIGNED NOT NULL,                                      -- dinero en enteros
  ip_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,    -- nunca la IP real
  eliminado_en    DATETIME NULL,                                              -- borrado lógico (papelera)
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pedidos_token (token),
  KEY idx_pedidos_ip (ip_hash, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 3.6 Seguridad

| # | Riesgo | Medida estándar | Nivel |
|---|---|---|---|
| 1 | Acceso al código y credenciales | Solo `public/` es visible. `app/`, `storage/`, `vendor/`, `database/`, `scripts/` quedan fuera. `config.php` fuera de Git | O |
| 2 | Inyección SQL | SQL solo en Modelos, solo con consultas preparadas (`ATTR_EMULATE_PREPARES => false`, parámetros con nombre). Nunca concatenar datos del usuario. Listas blancas (`match`) para `ORDER BY` y nombres de columna | O |
| 3 | XSS | Toda salida con `e()`. Atributos y URLs escapados. JSON con `json_encode`. Sin HTML del usuario (si hace falta, lista blanca) | O |
| 4 | CSRF | Token por sesión (`random_bytes(32)`), campo `_token` en todo formulario POST, verificación con `hash_equals` en la clase base del panel y en los POST públicos. Cambios de estado solo por POST | O |
| 5 | Robo de sesión | Cookie `HttpOnly`, `Secure` en producción, `SameSite=Lax`; nombre de sesión propio; `session_regenerate_id(true)` al entrar y al salir | O |
| 6 | Contraseñas | `password_hash(PASSWORD_DEFAULT)` / `password_verify`; mínimo 10 caracteres; sin contraseñas por defecto. Las heredadas (md5, sha1, texto plano) se migran re-hasheando en el siguiente ingreso correcto | O |
| 7 | Fuerza bruta | Registro de intentos por correo e IP-hash; bloqueo temporal tras 5 fallos en 15 minutos; mensaje genérico | O |
| 8 | «Mantener sesión» | Cookie `selector:validador`; en la base solo `sha256(validador)`; `hash_equals`; vence a los 30 días; se revoca al cambiar la contraseña; lista de dispositivos | O |
| 9 | Recuperación de contraseña | Token de 32 bytes aleatorios, solo su hash en la base, vence en 1 hora, un solo uso; **misma respuesta exista o no el correo**; validar el formato del token antes de consultar | O |
| 10 | Subidas de archivos | Ver 3.7 | O |
| 11 | Datos manipulados por el cliente | El servidor recalcula precios y totales desde la base; acota cantidades; ignora lo que diga el navegador | O |
| 12 | Spam en formularios públicos | Campo trampa (honeypot) + límite por IP-hash (p. ej. 5 envíos cada 10 minutos). Sin CAPTCHA salvo necesidad real | O |
| 13 | Autorización | Toda acción del panel exige sesión (clase base). Con varios usuarios o roles: verificar que puede tocar *ese* registro (IDOR) | O / R (roles) |
| 14 | Redirecciones | Solo a rutas internas validadas (p. ej. el destino tras el ingreso debe empezar con `/admin`) | O |
| 15 | Fuga de información | `display_errors=0` en producción; errores a `storage/logs/php.log`; 404 amigable. Manejador global de excepciones con página 500 amigable | O / R (500) |
| 16 | Cabeceras HTTP | `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` mínima; HTTPS forzado (salvo localhost) | O |
| 17 | CSP y HSTS | `Content-Security-Policy` ajustada y `Strict-Transport-Security` una vez verificado el HTTPS. El proyecto de referencia aún no los tiene | R |
| 18 | Archivos sensibles expuestos | `.htaccess` deniega `ini`, `log`, `sql`, `md`, `json`, `lock`; `Options -Indexes`. Nada de `phpinfo.php`, respaldos `.sql/.zip`, `.git`, `.env` ni instaladores en producción | O |
| 19 | Instalador web temporal | Solo si no hay SSH: protegido con clave de un solo uso (`hash_equals`), se borra tras usarlo, la clave queda vacía y se comprueba que da 404 | O |
| 20 | Datos personales | Mínimos; IP solo como hash con sal; retención con limpieza automática; página de privacidad | O |
| 21 | Archivos privados (PDF, respaldos, originales) | Fuera de `public/`; descarga solo por una ruta autenticada | O |
| 22 | Respaldos | Base de datos diaria, comprimida, 14 días de historial; copia fuera del servidor | O / R (copia externa) |
| 23 | Dependencias | Versiones fijadas con `composer.lock`; revisar con `composer audit` | R |

## 3.7 Subida de archivos (imágenes)

1. Tope de tamaño (p. ej. 8 MB) y comprobación `is_uploaded_file`.
2. Tipo **real**: `finfo(FILEINFO_MIME_TYPE)` + `getimagesize()`; lista blanca (`image/jpeg`, `image/png`, `image/webp`). La extensión y el `Content-Type` del navegador no prueban nada.
3. **Volver a generar** la imagen con GD (decodificar y re-codificar): lo que haya escondido el archivo no sobrevive.
4. Corregir la rotación EXIF (fotos de celular).
5. Nombre aleatorio (`bin2hex(random_bytes(8))`); nunca el nombre original.
6. Generar todas las versiones necesarias (p. ej. 1200/600/300 px en WebP y una JPEG para PDF) y guardar el original reducido en `storage/` (no público), para poder regenerar.
7. `public/uploads/.htaccess` impide ejecutar código; las carpetas no se listan.
8. Al borrar: solo nombres que cumplan `^[a-f0-9]{16}$` (evita recorrer rutas) y se eliminan **todas** las variantes y cachés derivadas.

```apache
# public/uploads/.htaccess
php_flag engine off
<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phar|cgi|pl|py|sh|htaccess)$">
    Require all denied
</FilesMatch>
Options -Indexes
```

## 3.8 Configuración y secretos

- `app/config/config.php` (no versionado) con secciones `app` (`env` local|produccion, `debug`, `url`, `timezone`), `db`, `mail`, `seguridad.sal_hash` e `instalacion.clave`. `config.example.php` (versionado) tiene la misma forma con marcadores `CAMBIAR-...`.
- Textos y datos de contacto por defecto en `app/config/site.php`; el panel los sobrescribe en una tabla clave/valor (`configuracion`) mediante `ajuste('clave')`. Así el dueño cambia textos sin depender del desarrollador.
- Nunca secretos en: Git, documentación, capturas, chat, URLs ni JavaScript del navegador. Si un secreto estuvo expuesto, se cambia.
- El archivo `config.php` de producción se crea solo en el servidor y no se sobrescribe jamás al publicar.

## 3.9 Errores, registros, correo y tareas programadas

- `bootstrap.php`: `log_errors=1`, `error_log` en `storage/logs/php.log`, `display_errors` solo con `app.debug`, `error_reporting(E_ALL)`.
- 404 desde el Router. **[R]** `set_exception_handler` que registre el error y muestre una página 500 amable.
- Correo con **PHPMailer + SMTP autenticado** del hosting (SPF/DKIM revisados), no `mail()`, que suele caer en spam. En local, driver `log` (escribe en `storage/logs/mail.log`).
- Scripts de consola con guarda `PHP_SAPI !== 'cli'`:
  - `create-admin.php`: pide los datos por consola; sin contraseña por defecto.
  - `backup.php`: volcado comprimido de la base sin depender de `mysqldump` (en hosting compartido no siempre existe).
  - `cleanup.php`: retenciones (papelera 30 días, pedidos 12 meses, intentos de ingreso, sesiones y recuperaciones vencidas, archivos generados antiguos, temporales y respaldos de más de 14 días).
- Cron del hosting: respaldo diario (03:00) y limpieza semanal (domingo 04:00).

## 3.10 Interfaz y rendimiento

- Vistas con `layouts/` + `partials/`. `asset()` agrega `?v=` con el `filemtime` del archivo real (cache busting). Caché larga en estáticos, compresión gzip, tipografías propias (WOFF2), sprite SVG para íconos, JavaScript puro, CSS propio con variables.
- Imágenes WebP con `width`/`height`, `loading="lazy"` salvo la imagen principal (`fetchpriority="high"`). Regla global `img { height: auto }` y cajas con `object-fit`.
- Sitio público: título y descripción por página, `noindex` en páginas privadas, `sitemap.xml` y `robots.txt` generados, URLs estables (el slug se crea una vez y no cambia), datos estructurados.
- Panel para personas no técnicas (si aplica): letra de 18 px o más, botones grandes **con texto**, lenguaje cotidiano («Ocultar del catálogo», no «Estado: inactivo»), confirmaciones claras («✓ Guardado»), nada se borra al primer clic, funciona bien desde el celular.

## 3.11 Despliegue

- **Hosting compartido sin SSH, disposición dividida:** `app/ storage/ vendor/ database/ scripts/ resources/` en una carpeta propia (`miapp/`) junto a `public_html/`, y el **contenido** de `public/` dentro de `public_html/`. `index.php` detecta dónde está `app/`. Toda ruta a la carpeta pública usa `RUTA_PUBLICA`, nunca `RAIZ . '/public'`.
- Composer se ejecuta en el equipo local: `composer install --no-dev --optimize-autoloader` y se sube `vendor/` ya armado.
- PHP 8.2+ (MultiPHP), `memory_limit 256M`, `max_execution_time 120`, `upload_max_filesize 10M`, `post_max_size 12M`, `display_errors Off`; permisos 755 en carpetas escribibles; HTTPS gratuito activo y redirección en `.htaccess`.
- **Publicar siempre con lista explícita de archivos.** Nunca arrastrar carpetas completas: se cuelan cachés, fotos y datos locales.
- Cambios de base de datos: manuales (phpMyAdmin) con respaldo previo, desde `database/migraciones/`, y anunciados antes de subir el código que los necesita.
- **[R] Script de publicación (`deploy/subir.sh`):** modo «solo plan» por defecto (qué es nuevo, qué se reemplaza, qué ya está igual) → aprobación → respaldo de lo que se reemplaza → subida → verificación descargando y comparando → prueba de humo (`/`, `/catalogo`, ingreso) → revisión de los registros de error → `--restaurar`. Rechaza `config.php`, `vendor/`, `storage/`, `uploads/` e instaladores. Credenciales solo en `~/_netrc`, nunca en pantalla. FTP con TLS; si el servidor deja de aceptar TLS, **no degradar a FTP sin cifrar**: subir a mano y reintentar después.
- Lista de comprobación posterior: sitio abre con candado; panel abre y entra; crear un producto de prueba con foto y borrarlo; instaladores y archivos de depuración dan 404; `config.php`, `vendor/` y `database/` no se pueden abrir desde el navegador; registros de error sin novedades.

## 3.12 Documentación y control de versiones

- `docs/` numerada: `01-requerimientos` (principio rector, reglas de usabilidad, decisiones), `02-arquitectura` (mapa del sitio, flujos, decisiones técnicas, seguridad), `03-base-de-datos`, `04-identidad-visual` (si aplica), `05-desarrollo` (**bitácora viva**: qué funciona, pruebas hechas, correcciones), `06-despliegue` (guía paso a paso). En una migración se agregan `00-auditoria` y `01-plan-migracion`.
- Las decisiones pendientes se marcan con ⏳, con fecha, y se resuelven por escrito con el dueño.
- Git: repositorio privado; `.gitignore` con la configuración real, `vendor/`, datos de `storage/`, `uploads/` y respaldos de publicación; ramas por fase; mensajes que explican qué y por qué.

## 3.13 Patrones de referencia (código)

**Punto de entrada — `public/index.php`**

```php
<?php
declare(strict_types=1);

// "public/" puede estar junto a "app/" (local) o separado (hosting): se detecta solo.
$raiz = is_dir(dirname(__DIR__) . '/app') ? dirname(__DIR__) : dirname(__DIR__) . '/NOMBRE_CARPETA_APP';
define('RUTA_PUBLICA', __DIR__);          // carpeta pública REAL. Nunca RAIZ . '/public'
require $raiz . '/app/bootstrap.php';

use App\Controllers\Admin;
use App\Controllers\ErrorController;
use App\Controllers\ProductController;
use App\Core\Request;
use App\Core\Router;

$router = new Router();
$router->get('/producto/{slug}', [ProductController::class, 'ver']);
$router->post('/admin/productos/{id}/guardar', [Admin\ProductsController::class, 'guardar']);

if (!$router->despachar(Request::metodo(), Request::ruta())) {
    (new ErrorController())->noEncontrado();   // 404 amigable
}
```

**Arranque — `app/bootstrap.php`**

```php
define('RAIZ', dirname(__DIR__));
if (!defined('RUTA_PUBLICA')) { define('RUTA_PUBLICA', RAIZ . '/public'); }   // solo scripts de consola
require RAIZ . '/vendor/autoload.php';

Config::cargar(RAIZ . '/app/config/config.php');
date_default_timezone_set((string) Config::get('app.timezone', 'America/Santiago'));
ini_set('log_errors', '1');
ini_set('error_log', RAIZ . '/storage/logs/php.log');
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
error_reporting(E_ALL);
Session::iniciar();
```

**Base de datos — todo con parámetros con nombre**

```php
$pdo = new PDO($dsn, $usuario, $clave, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,       // consultas preparadas de verdad
]);
$pdo->exec("SET time_zone = '+00:00'");           // todo en UTC

Database::uno('SELECT * FROM usuarios WHERE email = :e AND activo = 1', ['e' => $email]);   // todos(), valor()

// Escrituras de varias filas: transacción (todo o nada)
$pdo = Database::pdo();
$pdo->beginTransaction();
try {
    Database::consultar('INSERT INTO pedidos (...) VALUES (...)', [...]);
    $id = (int) $pdo->lastInsertId();
    foreach ($lineas as $l) { Database::consultar('INSERT INTO pedido_items (...) VALUES (...)', [...]); }
    $pdo->commit();
} catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
```

**Escape, CSRF y redirección — `app/Core/helpers.php`**

```php
function e(?string $t): string { return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function campo_csrf(): string  { return '<input type="hidden" name="_token" value="' . e(Session::tokenCsrf()) . '">'; }
function redirigir(string $ruta, int $codigo = 302): never { header('Location: ' . url($ruta), true, $codigo); exit; }
function asset(string $ruta): string {   // ?v= con la fecha del archivo REAL: comprobar que no queda en ?v=1
    $f = RUTA_PUBLICA . '/' . ltrim($ruta, '/');
    return url($ruta) . '?v=' . (is_file($f) ? filemtime($f) : '1');
}

// Session
public static function tokenCsrf(): string { return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32)); }
public static function csrfValido(?string $t): bool {
    $g = $_SESSION['_csrf'] ?? null;
    return is_string($g) && is_string($t) && hash_equals($g, $t);
}
```

**Sesión segura**

```php
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https || Config::esProduccion(),
                           'httponly' => true, 'samesite' => 'Lax']);
session_name('miapp_sesion');
session_start();
```

**Clase base del panel — exige sesión y centraliza el CSRF**

```php
abstract class BaseAdminController
{
    protected array $usuario;
    public function __construct() { $this->usuario = Auth::requerir(); }   // sin sesión → redirige al ingreso

    protected function verificarCsrf(string $volverA): void {
        if (!Session::csrfValido(Request::post('_token'))) {
            Session::flash('error', 'La página estuvo abierta demasiado tiempo. Inténtelo otra vez.');
            redirigir($volverA);
        }
    }
}
```

**Acción típica de un controlador (Post/Redirect/Get)**

```php
public function guardar(array $parametros = []): void
{
    $this->verificarCsrf('/admin/cosas/nueva');                       // 1. CSRF
    $nombre = trim((string) Request::post('nombre', ''));             // 2. leer y normalizar
    if ($nombre === '') {                                              // 3. validar → mensaje claro y volver
        Session::flash('error', 'Escriba el nombre.');
        redirigir('/admin/cosas/nueva');
    }
    Cosa::guardar(['nombre' => mb_substr($nombre, 0, 150)]);          // 4. el Modelo hace el SQL preparado
    Session::flash('exito', '✓ Guardado.');                           // 5. PRG: siempre redirigir tras un POST
    redirigir('/admin/cosas');
}
```

**Ingreso con bloqueo, y «mantener sesión»**

```php
// ¿5 o más intentos fallidos en los últimos 15 minutos desde esta conexión o con este correo?
Database::valor('SELECT COUNT(*) FROM intentos_login
                  WHERE exitoso = 0 AND creado_en > (UTC_TIMESTAMP() - INTERVAL :min MINUTE)
                    AND (ip_hash = :ip OR email = :email)', [...]);

// IP anonimizada con sal (la sal vive en config.php): hash('sha256', $ip . '|' . $sal)

// Tras ingresar bien:  Session::regenerar();  Session::set('admin_id', $id);

// Mantener sesión: cookie "selector:validador"; en la base SOLO el hash del validador
$selector  = bin2hex(random_bytes(12));
$validador = bin2hex(random_bytes(32));
// INSERT (selector, validador_hash = hash('sha256', $validador), expira_en = ahora UTC + 30 días)
setcookie('miapp_recordar', "$selector:$validador", ['expires' => time() + 30 * 86400, 'path' => '/',
          'secure' => Config::esProduccion(), 'httponly' => true, 'samesite' => 'Lax']);
// Verificar: buscar por selector no vencido; hash_equals($fila['validador_hash'], hash('sha256', $validador))
```

**`public/.htaccess`**

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_HOST} !^localhost$
    RewriteCond %{HTTPS} !=on
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]        # HTTPS (no afecta a localhost)
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]                                                # archivos reales, tal cual
    RewriteRule ^ index.php [L]                                        # todo lo demás, al punto de entrada
</IfModule>
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
    Header set Permissions-Policy "geolocation=(), microphone=(), camera=()"   # camera=(self) solo si el panel toma fotos
</IfModule>
Options -Indexes
<FilesMatch "\.(ini|log|sql|md|json|lock)$">
    Require all denied
</FilesMatch>
```

# 4. ADAPTACIÓN AL STACK

La arquitectura de referencia está escrita en **PHP 8 sin framework**. Primero identifica la tecnología real del proyecto (sección «DATOS DEL PROYECTO» y lo que veas en el código) y adapta así:

| Si el proyecto es… | Qué hacer |
|---|---|
| PHP plano (archivos sueltos que mezclan SQL y HTML) | Aplicar el estándar completo con la estrategia por cortes verticales de la Fase 6 |
| PHP con framework (Laravel, Symfony, CodeIgniter, Slim…) | **Conservar el framework.** No lo reemplaces por el Core propio. Aplica los principios y las listas de control: capas, seguridad (3.6), base de datos (3.5), subidas (3.7), despliegue (3.11), documentación (3.12) |
| WordPress u otro CMS | Aplicar solo a temas y plugins propios: lógica separada de plantillas, `$wpdb->prepare`, nonces, funciones `esc_*`, sanitización, capacidades de usuario. No mover el núcleo |
| Otro lenguaje (Node, Python, etc.) | Conservar el stack y traducir cada principio a sus idiomas: capas, consultas parametrizadas, escape, CSRF, sesiones seguras, secretos fuera del repositorio, subidas, registros, despliegue y documentación |

Si hay datos reales en producción: ningún cambio destructivo, siempre con respaldo previo, y migraciones compatibles hacia atrás.

# 5. PROCEDIMIENTO DE MIGRACIÓN (por fases, con aprobación entre ellas)

**Fase 0 · Respaldo y línea base (sin cambiar código).**
Revisa `git status` y crea la rama. Respalda archivos y volcado de la base **fuera del repositorio**. Anota versiones (PHP, base de datos) en local y en producción. Levanta el proyecto en local y comprueba que funciona. Guarda este estándar en `docs/estandar-arquitectura.md` y, si existe `CLAUDE.md`, haz que lo mencione.
*Entregable:* nota breve del estado inicial.

**Fase 1 · Auditoría (solo lectura).**
Inventario completo y hallazgos de seguridad por severidad (con `archivo:línea`), según la plantilla 6.1. Incluye puntos de entrada, formularios, APIs, tareas programadas, correos, subidas, dependencias, secretos (dónde están y si están en el historial de Git) y comportamientos sutiles que hay que preservar.
*Entregable:* `docs/00-auditoria.md`.

**Fase 2 · Plan y aprobación.**
Orden de los cortes verticales, dependencias entre ellos, riesgos, esfuerzo relativo, decisiones pendientes (⏳) y plan de reversa. Para cada medida [R], di qué aporta y qué cuesta.
*Entregable:* `docs/01-plan-migracion.md`. **Espera mi aprobación antes de seguir.**

**Fase 3 · Red de seguridad.**
Crea una prueba de humo (`scripts/smoke.php` o equivalente) que recorra las rutas clave y verifique códigos HTTP, textos esperados y ausencia de `Warning`, `Notice` y `Fatal error`. Registra la línea base del comportamiento antiguo en los flujos críticos (respuestas y efectos en la base de datos). Para pantallas con riesgo visual, compara capturas antes y después, en escritorio y celular. Para lo que no se pueda automatizar, deja una lista de comprobación manual. **[R]** Pruebas unitarias de la lógica crítica (totales, validación de subidas, bloqueo de ingreso).
Para probar el panel, crea un usuario temporal con un script y bórralo al terminar.

**Fase 4 · Esqueleto conviviendo con lo antiguo.**
Crea `app/` (Core mínimo: Config, Database, Request, Router, Session, View, helpers), `bootstrap.php`, `public/index.php` y `.htaccess` (los archivos reales se sirven tal cual; el resto pasa por `index.php`) y el autoload de Composer. El código antiguo sigue accesible y puede incluir `bootstrap.php` para compartir base de datos y sesión. Sin cambios visibles. Ejecuta la prueba de humo.

**Fase 5 · Endurecimiento transversal (ganancias rápidas, por severidad).**
1. Credenciales fuera de la carpeta pública y de Git (y **rotarlas** si estuvieron expuestas).
2. Consultas preparadas, empezando por los puntos críticos.
3. `e()` en las salidas.
4. CSRF y cambios de estado solo por POST.
5. Sesión, contraseñas y bloqueo de ingreso.
6. Subidas.
7. Errores y registros.
8. Cabeceras y HTTPS.
9. Retirar archivos peligrosos expuestos (respaldos, `phpinfo`, instaladores, `.git`).
Cada ítem: cambio mínimo + prueba + registro en la bitácora. Si un arreglo cambia algo visible (p. ej. un enlace GET que borraba y ahora es un botón POST), avísalo antes.

**Fase 6 · Migración por cortes verticales.**
Un corte = una pantalla o flujo completo (ruta → controlador → modelo → vista). Para cada uno, con la plantilla 6.2:
a) Describe su comportamiento actual (entradas, salidas, efectos en base de datos, archivos y correo, casos de borde).
b) Agrega o ajusta su prueba.
c) Crea Modelo (SQL preparado), Controlador delgado, Vista con `e()` y la ruta.
d) Conserva la URL, o agrega una redirección 301 desde la antigua (cuida el SEO).
e) Compara con la línea base: orden de resultados, redondeos, formatos de fecha y moneda, textos.
f) Retira el archivo antiguo solo cuando el nuevo esté verificado.
g) Documenta y registra.
*Orden sugerido:* autenticación y sesión → páginas públicas de solo lectura → formularios y escrituras → funciones auxiliares (PDF, correo, tareas). Un corte por vez.

**Fase 7 · Base de datos.**
Llévala al estándar sin romper nada: tablas de seguridad faltantes (`intentos_login`, `sesiones_recordadas`, `recuperaciones_clave`), índices, claves únicas y foráneas, charset, borrado lógico, columnas de auditoría y fechas en UTC (si hoy están en hora local, plan de conversión con respaldo). Actualiza `schema.sql` y agrega los archivos de `migraciones/`. **Cada cambio con respaldo y aprobación.** Patrón: expandir → migrar datos → contraer.

**Fase 8 · Archivos y subidas.**
Pipeline seguro de 3.7; originales y archivos privados fuera de `public/`; `uploads/.htaccess`; nombres aleatorios; limpieza de huérfanos.

**Fase 9 · Despliegue.**
Sigue 3.11. Ensaya antes en un entorno de prueba si existe. Elige un horario de poco tráfico. Deja lista la reversa. Verifica después de publicar y confirma que instaladores y archivos de depuración dan 404.

**Fase 10 · Cierre.**
Revisión final contra la tabla 3.6 y la definición de terminado (6.4). Actualiza `docs/` (arquitectura final, base de datos, despliegue, bitácora). Lista de pendientes [R]. Propón mejoras a este estándar según lo aprendido. Entrega al dueño una guía breve de mantenimiento.

# 6. PLANTILLAS

## 6.1 Informe de auditoría (`docs/00-auditoria.md`)

1. **Resumen** (3–5 líneas) y semáforo de riesgo.
2. **Ficha técnica:** stack y versiones, hosting, tamaño (archivos y líneas), base de datos (tablas, filas aproximadas), dependencias.
3. **Inventario de funcionalidades:** `id | pantalla / ruta / script | qué hace | quién la usa | entradas | efectos (base de datos, archivos, correo) | riesgo | corte propuesto`.
4. **Hallazgos de seguridad:** `id | severidad (crítica/alta/media/baja) | dónde (archivo:línea) | qué ocurre | cómo se aprovecharía (una línea) | corrección propuesta | esfuerzo`.
5. **Arquitectura y deuda técnica.**
6. **Secretos:** dónde están las credenciales y si aparecen en Git o en su historial.
7. **Riesgos de la migración** y cómo mitigarlos.
8. **Qué no se debe tocar** y comportamientos sutiles a preservar.
9. **Preguntas para el dueño.**

## 6.2 Ficha de corte vertical

Nombre · URL(s) · archivos antiguos → archivos nuevos · comportamiento actual (entradas, salidas, efectos) · casos de borde · pruebas de paridad · seguridad aplicada (casillas de 3.6 que correspondan) · riesgos · reversa · estado.

## 6.3 Informe al terminar cada paso (formato de tu respuesta)

1. Qué cambió, en lenguaje de negocio.
2. Archivos creados, modificados y eliminados (con su carpeta de destino en el servidor, si la publicación es manual).
3. Cómo se verificó (comandos y resultados reales).
4. Qué quedó pendiente o no se pudo probar.
5. Qué debe revisar el dueño.
6. Siguiente paso propuesto y qué aprobación necesita.

## 6.4 Definición de terminado (por corte)

- [ ] `php -l` sin errores en todo lo tocado y sin `Warning`/`Notice` en los registros durante las pruebas.
- [ ] Paridad comprobada y documentada (mismas entradas → mismas salidas y mismos efectos).
- [ ] Casillas de seguridad aplicables cumplidas: SQL preparado, salida escapada, CSRF, sesión/autorización, validación, subidas.
- [ ] Funciona con la estructura de producción (carpetas separadas, Linux, versión de PHP del hosting).
- [ ] Sin datos, usuarios ni archivos de prueba que sobren (cifras antes/después).
- [ ] Documentación y bitácora al día; lista de archivos a publicar con su destino.
- [ ] Commit, solo si el dueño lo pidió.

# 7. LECCIONES APRENDIDAS (errores reales del proyecto de referencia; no repetirlos)

1. **Mayúsculas en carpetas.** `app/models` con espacio de nombres `App\Models` funcionó en Windows y habría fallado en Linux. Carpeta = espacio de nombres, letra por letra; revisarlo antes de publicar.
2. **Ruta pública fija.** Con `RAIZ . '/public'` las fotos se guardaron donde el navegador no las veía (hosting dividido). Usar siempre `RUTA_PUBLICA` y probar con la estructura de producción.
3. **Caché de CSS/JS pegada.** `asset()` quedó en `?v=1` porque buscaba el archivo en una ruta equivocada y los navegadores mostraban versiones viejas. Verificar que el número de versión cambia al cambiar el archivo.
4. **Datos de referencia sin importar.** Faltó `seed.sql` en producción: la lista «Se vende por» quedó vacía y guardar un producto daba error 500. La guía de despliegue debe decir «importar `schema.sql` **y** `seed.sql`» y hay que comprobarlo después.
5. **Arrastrar carpetas por FTP.** Subió una caché de búsqueda local con productos de demostración y decenas de fotos huérfanas. Publicar solo con lista explícita de archivos.
6. **La extensión no prueba el formato.** Un PNG renombrado a `.webp` (2,3 MB) quedó publicado en lugar de la versión liviana (150 KB). Tras subir a mano, comprobar tipo real y peso, y decirle al dueño qué peso debe tener el archivo correcto.
7. **`srcset` con una variante faltante.** El navegador pidió el archivo que no estaba y no volvió a `src`: imagen rota. Si los archivos se suben a mano, preferir diseños que degraden bien (una sola imagen) o comprobar que todas las variantes existen en el servidor.
8. **Un error de validación borraba el formulario** (incluida la foto elegida). Validar en el navegador, marcar el campo y enfocarlo; el servidor valida igual y conserva lo escrito.
9. **Estimar en vez de medir.** «Caben 9 productos por hoja» sin renderizar falló. Medir con datos de prueba reales (incluidos nombres largos) y borrar esos datos al terminar.
10. **PDF con mPDF.** Los selectores CSS descendientes no estilizan `div` dentro de `td`, y una `<td>` vacía colapsa: usar clases directas y `&nbsp;`. Revisar el PDF mirando páginas reales, no solo el texto.
11. **Imágenes estiradas.** `<img width height>` sin `height: auto` deformó las miniaturas. Regla global `img { height: auto }` y cajas con `object-fit`.
12. **El FTP cifrado puede dejar de responder** (el servidor contestó 504 a `AUTH TLS`). No degradar a FTP sin cifrar: subir a mano y reintentar más tarde.
13. **Secretos en la documentación.** La guía de despliegue incluyó una sal y una clave de instalación reales. Los documentos llevan marcadores (`CAMBIAR-...`); los valores reales viven solo en el `config.php` del servidor.
14. **Textos que cambian.** El negocio cambió cómo explicaba sus envíos y hubo que reescribir textos en el código. Los textos del sitio deben poder editarse desde el panel, con su valor por defecto en `site.php`.

# 8. COMIENZA AHORA

1. Lee «DATOS DEL PROYECTO» y recorre el código **sin modificar nada**.
2. Ejecuta la **Fase 0** y la **Fase 1**.
3. Presenta el informe de auditoría (plantilla 6.1) y propón el plan de la **Fase 2**, con las preguntas que necesites hacerme.
4. **Espera mi aprobación** antes de pasar a la Fase 3. Hasta entonces no modifiques código, base de datos ni producción: solo puedes crear la rama, los respaldos y los documentos de `docs/`.

▲▲▲ **FIN DEL PROMPT** ▲▲▲
