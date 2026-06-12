# Puesta en producción — admin.minimark.cl

Guía paso a paso para dejar el **dashboard web** funcionando en HostGator (cPanel)
de forma limpia y segura. Producción se rehace **desde cero** (no hay datos reales
que conservar). Este go-live cubre **solo el dashboard web**; la sincronización de
facturas de los PC y el correo de cortes de Eleventa se activan después (ver el
final de esta guía).

> **Convención de rutas**: el ejemplo usa el usuario de cPanel `migue492` y el
> docroot del subdominio `/home/migue492/admin.minimark.cl`. Si tu cuenta usa otra
> ruta, reemplázala. Para ver la tuya: cPanel → *Dominios* → la fila de
> `admin.minimark.cl` muestra el "Document Root".

---

## 0. Antes de empezar (red de seguridad)

1. cPanel → **Copia de seguridad** → *Descargar una copia de seguridad completa de
   la cuenta* (o al menos un respaldo de la base actual por phpMyAdmin). Aunque la
   base esté vacía, deja un punto de retorno.
2. En el repo, etiqueta la versión que vas a publicar:
   `git tag go-live-2026-06-12 && git push --tags`.

## 1. Pre-vuelo de PHP

cPanel → **Select PHP Version** (o *MultiPHP Manager*):
- Versión: **ea-php84**.
- Extensiones activas (marcar si falta alguna): `pdo_mysql`, `mbstring`, **`zip`**
  (lectura del Excel de productos), `fileinfo`, `openssl`, `json`.
- `imap` **no** hace falta todavía (solo cuando actives Ingresos/cortes).

## 2. Base de datos desde cero

cPanel → **MySQL Databases**:
1. Confirma que existe la base `migue492_minimark` y el usuario `migue492_admin`
   **con ALL PRIVILEGES** sobre ella.
2. **Rota la contraseña** del usuario MySQL (ponle una nueva y fuerte). Guárdala:
   irá en `config.php` (paso 4).
3. Deja la base **vacía**: en phpMyAdmin, si hay tablas viejas, selecciónalas y
   *Eliminar* (DROP). El esquema lo crea `setup.php` en el paso 7.

## 3. Subir el código

1. Comprime el **contenido** de la carpeta `web/` del repo en un `.zip`
   **excluyendo**: `config.php`, la carpeta `.git`, y los `.md` de desarrollo
   (`CLAUDE.md`, `CONTRATO.md`; este `DESPLIEGUE.md` y `README_SERVIDOR.md` son
   inofensivos y además el `.htaccess` los bloquea).
2. cPanel → **Administrador de archivos** → entra al docroot del subdominio → sube
   el zip y **Extraer** ahí. Los archivos deben quedar en la raíz del docroot
   (que `index.php` quede en `/home/migue492/admin.minimark.cl/index.php`).
3. **No** subas ni sobreescribas `config.php` (lo maneja el paso 4).

## 4. config.php del servidor

`config.php` **no** está en git ni en el zip: vive solo en el servidor.
1. Si no existe: copia `config.ejemplo.php` → renómbralo `config.php`.
2. Edítalo (clic derecho → *Edit*) y en el bloque de **producción** pon:
   - `db.clave` = la **contraseña nueva** de MySQL (paso 2).
   - `setup_key` = una **clave larga y secreta** (la misma que dejes acordada;
     ya hay una generada en el `config.php` de desarrollo).
   - `cortes_imap` con `usuario`/`clave` **vacíos** por ahora.

> El archivo detecta solo el ambiente por el dominio: en el servidor usa el bloque
> de producción; en tu PC (XAMPP) usa el local. No necesitas tocar el bloque local.

## 5. Permisos de archivos (chmod)

En el Administrador de archivos (clic derecho → *Permissions*):
- Carpetas: **755**  ·  Archivos: **644**.
- `config.php`: **600** (solo el dueño lo lee).
- `almacen_pdf/`: **755** y **escribible** por PHP (ahí se guardarán los PDF al
  activar la sincronización). Verifica que dentro esté su `.htaccess` (deny all).

## 6. SSL / HTTPS

cPanel → **SSL/TLS Status** → confirma que `admin.minimark.cl` tiene certificado
(AutoSSL). El `.htaccess` ya **fuerza HTTPS** (redirige http→https).

## 7. Crear el esquema + primer negocio

En el navegador (reemplaza `SETUP_KEY` por tu clave del paso 4):

```
https://admin.minimark.cl/setup.php?key=SETUP_KEY&negocio=Minimark&slug=minimark
```

Crea **todas las tablas** y el primer negocio. Muestra un **token de máquina**:
cópialo y guárdalo (lo usarás cuando actives la sincronización de los PC; por
ahora puedes ignorarlo).

## 8. Crear tu usuario administrador

Abre `https://admin.minimark.cl/crear_admin_form.php`, llena correo + nombre +
**contraseña fuerte** y envía. (Este formulario no pide clave: por eso se borra
enseguida, paso 9.)

## 9. Borrar los utilitarios de un solo uso  ⚠️ IMPORTANTE

En el Administrador de archivos, **elimina del servidor**:
`setup.php`, `crear_admin.php`, `crear_admin_form.php`, `migrar.php`,
`reset_facturas.php`.

Mientras existan son la mayor superficie de ataque. Si más adelante necesitas uno
(ej. `setup.php` para otro token), lo vuelves a subir, lo usas y lo borras.

## 10. Cargar negocios, usuarios y catálogos

Entra a `https://admin.minimark.cl/` con tu admin y:
- **Administración → Negocios**: crea los negocios reales.
- **Administración → Usuarios**: crea los usuarios, su rol (Administrador /
  Vendedor) y los negocios asignados.
- **Herramientas → Base de Datos de Productos**: sube el Excel de Eleventa de cada
  negocio.

---

## 11. Respaldo diario de la base de datos (cron)

Para no poner la contraseña en el cron, se usa un archivo de credenciales privado.

1. Administrador de archivos → muestra archivos ocultos → en `/home/migue492/`
   crea `.my.cnf` con:

   ```ini
   [client]
   user=migue492_admin
   password=LA_CLAVE_NUEVA_DE_MYSQL
   ```
   Dale permisos **600** a ese `.my.cnf`.

2. Crea la carpeta de respaldos **fuera del docroot**:
   `/home/migue492/respaldos_bd/` (no es accesible por web).

3. Crea el script `/home/migue492/respaldos_bd/respaldo.sh`:

   ```bash
   #!/bin/bash
   FECHA=$(date +%F_%H%M)
   DEST="/home/migue492/respaldos_bd"
   mysqldump --defaults-extra-file=/home/migue492/.my.cnf \
     --single-transaction --quick migue492_minimark \
     | gzip > "$DEST/minimark_$FECHA.sql.gz"
   # conservar solo los ultimos 14 respaldos
   ls -1t "$DEST"/minimark_*.sql.gz | tail -n +15 | xargs -r rm -f
   ```
   Permisos **700** al script.

4. cPanel → **Cron Jobs** → agrega (todos los días a las 04:00):

   ```
   0 4 * * * /home/migue492/respaldos_bd/respaldo.sh >/dev/null 2>&1
   ```

5. Pruébalo una vez a mano (Cron de "una vez" o por terminal SSH si tienes) y
   confirma que aparece un `minimark_*.sql.gz` en la carpeta.

> Como red de seguridad adicional, deja activo el **respaldo completo de la cuenta**
> que ya hace HostGator.

---

## 12. Verificación post-despliegue

1. `http://admin.minimark.cl` → redirige a **https** (301). El candado aparece.
2. **Rutas protegidas** (deben dar *403 / Forbidden*):
   `…/config.php`, `…/lib/esquema.sql`, `…/CLAUDE.md`, `…/.git/config`.
   Y `…/setup.php` y `…/crear_admin_form.php` → **404** (ya borrados).
   `…/setup.php?key=clave-mala` → denegado (si lo volviste a subir).
3. **Login** con tu admin. Crea un usuario **Vendedor** con un negocio y entra con
   él: no debe ver Ingresos/Negocios/Usuarios, y un negocio que no tiene asignado
   no aparece ni escribiendo su URL.
4. **Módulos**: Home, Fiados (cliente + fiado + abono), Herramientas → Contador de
   Caja, generar e imprimir etiquetas, subir un Excel de productos, Consultar
   Catálogo (buscar por nombre y por código). En un **celular** (por HTTPS): probar
   el **escáner** de la cámara.
5. `…/api/facturas.php` abierto en el navegador (GET) → "Método no permitido"
   (correcto).
6. Confirmar que el **cron de respaldo** dejó su `.sql.gz`.

## 13. Rollback

Riesgo bajo (se parte de cero). Si algo sale mal: restaura el volcado de la base
(o el respaldo completo de cPanel) y vuelve a subir la versión anterior del código
(la del `git tag` del paso 0).

---

## Después del go-live (cuando quieras, no es parte de este despliegue)

- **Sincronización de facturas (PCs)**: vuelve a subir `setup.php`, córrelo con
  otro `&slug=` por cada negocio para generar su **token**, configura el cliente
  Python con ese token y la URL `https://admin.minimark.cl/api/facturas.php`, y
  **borra `setup.php`** de nuevo.
- **Correo de cortes (Ingresos)**: crea la casilla `cortes@minimark.cl`, los
  forwarders `corte-{slug}@`, completa el bloque `cortes_imap` del `config.php` de
  producción, activa la extensión `imap` en PHP, configura Eleventa para enviar el
  cierre a su alias e instala el cron de `procesar_cortes.php` cada 10 minutos:
  `*/10 * * * * /usr/local/bin/ea-php84 /home/migue492/admin.minimark.cl/procesar_cortes.php`.
