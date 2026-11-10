# Despliegue — admin.minimark.cl

No hay CI/CD: todo se sube a mano por el Administrador de archivos de cPanel.
`admin.minimark.cl` está **en producción desde el 2026-11-08**, con negocios,
usuarios y ventas reales — **nunca se parte de cero** salvo que de verdad se
esté armando un servidor nuevo (caso raro, cubierto al final de esta guía).

> **Convención de rutas**: el ejemplo usa el usuario de cPanel `migue492` y el
> docroot del subdominio `/home/migue492/admin.minimark.cl`. Si tu cuenta usa otra
> ruta, reemplázala. Para ver la tuya: cPanel → *Dominios* → la fila de
> `admin.minimark.cl` muestra el "Document Root".

---

# Actualizar el servidor (caso normal)

Esto es lo que se hace cada vez que hay cambios en `web/` para subir —
incluidos los que están pendientes ahora mismo: el Router de punto único de
entrada (ya no hay archivos puente como `pos.php`/`usuarios.php`/etc., todo
pasa por `index.php` + `.htaccess`) y la carpeta `documentos/` (reemplazó a
`almacen_pdf/`/`respaldos_pdf/`). Ver `web/docs/bitacora-migracion.md`,
Fases 19-21, para el detalle de esos dos cambios.

## 1. Antes de subir (red de seguridad)

1. cPanel → **Copia de seguridad** → *Descargar una copia de seguridad completa
   de la cuenta* (o al menos un respaldo de la base por phpMyAdmin:
   **Exportar**, formato SQL).
2. En el repo, etiqueta la versión que vas a publicar:
   `git tag go-live-AAAA-MM-DD && git push --tags`.

## 2. Qué subir

Todo el contenido de la carpeta `web/` del repo **tal cual está**, con una
sola excepción: **nunca subas `config.php`** (tiene las claves reales del
servidor; cada ambiente tiene el suyo y no viaja por git ni por FTP). Todo lo
demás — incluidos `index.php`, `app/` completo, `.htaccess`, `documentos/`
(con su `.htaccess` adentro), `logs/` (con el suyo) — se sube igual, son
parte del código.

1. Comprime el **contenido** de `web/` en un `.zip` (excluyendo `config.php`
   y la carpeta `.git`; los `.md` son inofensivos, el `.htaccess` ya los
   bloquea por web).
2. cPanel → **Administrador de archivos** → entra al docroot del subdominio
   → sube el zip → **Extraer** ahí, **sobreescribiendo** lo que ya existe.
   `index.php` debe quedar en `/home/migue492/admin.minimark.cl/index.php`.
3. Confirma que `config.php` del servidor sigue intacto (no lo tocó la
   extracción — si tu zip no lo incluía, no hay riesgo).

## 3. Correr las migraciones

Entrar a `https://admin.minimark.cl/migrar.php` como administrador.

Es **aditivo e idempotente**: crea las tablas/columnas que falten y
reubica lo que corresponda (ej. la Fase 21 reordena los PDF existentes a
`documentos/facturas/{slug}/{año}/{mes}/`) — **nunca borra datos**. Se puede
correr tantas veces como haga falta; si no hay nada pendiente, no hace nada.

`migrar.php` **se deja en el servidor a propósito** (a diferencia de los
utilitarios de un solo uso, que ya no existen — ver más abajo): se necesita
de nuevo cada vez que el esquema cambia.

## 4. Verificación post-despliegue

1. `http://admin.minimark.cl` → redirige a **https** (301). El candado aparece.
2. **Rutas protegidas** (deben dar *403 Forbidden*): `…/config.php`,
   `…/lib/esquema.sql`, `…/CLAUDE.md`, `…/.git/config`, `…/logs/app.log`,
   cualquier cosa dentro de `…/documentos/`.
3. Una dirección que no exista (ej. `…/esto-no-existe.php`) → **404** limpio
   de la aplicación (confirma que el Router está activo, no un 404 crudo de
   Apache).
4. **Login** con tu admin real. Entra con un usuario **Vendedor**: no debe
   ver Ingresos/Negocios/Usuarios, y un negocio que no tiene asignado no
   aparece ni escribiendo su URL directo.
5. Abrir una factura con PDF real → **Ver PDF** descarga el archivo
   (confirma `documentos/facturas/` y `VerPdfController`).
6. `…/api/facturas.php` abierto en el navegador (GET) → `{"error":"Metodo no
   permitido..."}`  con código 405 (correcto: ese endpoint es solo POST desde
   la app de escritorio).
7. Si tocaste algo del POS: abrir caja, una venta de prueba, cerrar caja —
   el corte debe aparecer en Ingresos.

## 5. Si algo sale mal (rollback)

Restaura el respaldo de la base (paso 1) y vuelve a subir la versión anterior
del código (el `git tag` del paso 1). Como nunca se borran datos en el camino
de ida, en la práctica alcanza con resubir el código viejo.

---

# Instalación desde cero (servidor nuevo, sin datos)

Caso raro — solo si alguna vez se arma una instalación nueva desde el
principio (otro dominio, un ambiente de prueba real en el hosting, etc.). Si
`admin.minimark.cl` **ya tiene negocios y usuarios reales, no sigas esta
sección** — es la de arriba.

Los utilitarios que antes hacían esto automático (`setup.php`,
`crear_admin.php`, `crear_admin_form.php`, `reset_facturas.php`) **ya no
existen** (se borraron del repo y del servidor el 2026-11-08 por ser la
mayor superficie de ataque del sitio — hallazgo H5 de la auditoría). El
procedimiento de abajo los reemplaza sin necesitar ningún archivo nuevo de
un solo uso.

## 1. Pre-vuelo de PHP

cPanel → **Select PHP Version** (o *MultiPHP Manager*):
- Versión: **ea-php84**.
- Extensiones activas: `pdo_mysql`, `mbstring`, **`zip`** (lectura del Excel
  de productos), `fileinfo`, `openssl`, `json`. `imap` solo si vas a activar
  Ingresos/cortes de una vez (si no, se agrega después).

## 2. Base de datos vacía

cPanel → **MySQL Databases**: crea la base y un usuario con **ALL
PRIVILEGES** sobre ella. Guarda la contraseña — va en `config.php` (paso 5).

## 3. Importar el esquema

phpMyAdmin → entra a la base nueva → pestaña **Importar** → selecciona
`lib/esquema.sql` (del repo, en tu PC) → Ejecutar. Crea todas las tablas
vacías (`CREATE TABLE IF NOT EXISTS`, así que se puede volver a importar sin
problema si hace falta).

## 4. Subir el código

Igual que el paso 2 de "Actualizar el servidor" arriba: todo `web/` menos
`config.php`.

## 5. config.php del servidor

No está en git: vive solo en el servidor.
1. Copia `config.ejemplo.php` → renómbralo `config.php`.
2. Edítalo y en el bloque de **producción** pon: `db.clave` (la contraseña
   del paso 2), `setup_key` (una clave larga y secreta — ya no la usa ningún
   archivo del repo, pero la dejan otros scripts de un solo uso que puedas
   necesitar en el futuro), `cortes_imap` con `usuario`/`clave` vacíos por
   ahora.

> El archivo detecta el ambiente por el dominio: en el servidor usa el
> bloque de producción; en tu PC (XAMPP) usa el local. No toques el bloque
> local.

## 6. Permisos de archivos (chmod)

- Carpetas: **755** · Archivos: **644**.
- `config.php`: **600** (solo el dueño lo lee).
- `documentos/` y `logs/`: **755** y **escribibles** por PHP (`documentos/facturas/`,
  `documentos/respaldos/` y `logs/app.log` se crean solos la primera vez que
  hacen falta). Confirma que cada una tenga su `.htaccess` (deny all) — ya
  viene en el zip, no hay que crearlo a mano.

## 7. SSL / HTTPS

cPanel → **SSL/TLS Status** → confirma que el dominio tiene certificado
(AutoSSL). El `.htaccess` del repo ya fuerza HTTPS.

## 8. Crear el primer usuario administrador

Sin `crear_admin_form.php`, se hace con una consulta directa — dos pasos
cortos, nada queda expuesto en el servidor:

1. En tu PC, genera el hash de la contraseña que vas a usar (XAMPP ya tiene
   PHP instalado):
   ```bash
   php -r "echo password_hash('TU-CONTRASEÑA-FUERTE', PASSWORD_DEFAULT) . PHP_EOL;"
   ```
   Copia el resultado (empieza con `$2y$...`).
2. phpMyAdmin → la base de producción → pestaña **SQL** → pega y ejecuta
   (reemplazando el correo, el nombre y el hash copiado):
   ```sql
   INSERT INTO usuarios (email, password_hash, nombre, rol)
   VALUES ('tu-correo@ejemplo.cl', 'PEGA_AQUI_EL_HASH', 'Tu Nombre', 'admin');
   ```

## 9. Cargar negocios, usuarios, catálogos y tokens de máquina

Todo esto ya se hace desde el panel, con tu admin recién creado — no hace
falta ningún script más:

- **Administración → Negocios → + Crear negocio**: el primer negocio real.
- **Negocios → Tokens (PCs) → + Generar token**: el token que va en el
  `config.yaml` del PC de ese negocio para activar la sincronización de
  facturas (ver `docs/CONTRATO.md`). Se muestra **una sola vez** — cópialo
  antes de salir de la pantalla.
- **Administración → Usuarios**: el resto de los usuarios, su rol
  (Administrador / Vendedor) y los negocios asignados.
- **Herramientas → Base de Datos de Productos**: sube el Excel de Eleventa
  de cada negocio.

## 10. Cron jobs

Tres, independientes entre sí — agregalos los que vayas a usar:

**a) Respaldo de los PDF de facturas** (`scripts/respaldo_pdfs.php` —
pendiente de activar, ver `docs/bitacora-migracion.md`, sección
"Pendientes"):
```
0 4 * * * /usr/local/bin/ea-php84 /home/migue492/admin.minimark.cl/scripts/respaldo_pdfs.php >/dev/null 2>&1
```

**b) Correo de cortes de Eleventa** (módulo Ingresos, si aplica):
completa el bloque `cortes_imap` de `config.php`, activa la extensión
`imap` en PHP, y:
```
*/10 * * * * /usr/local/bin/ea-php84 /home/migue492/admin.minimark.cl/procesar_cortes.php >/dev/null 2>&1
```

**c) Respaldo diario de la base de datos** (recomendado, aparte del
respaldo completo de cuenta que ya hace HostGator): para no poner la
contraseña en el cron, usa un archivo de credenciales privado.

1. En `/home/migue492/` (fuera del docroot) crea `.my.cnf`:
   ```ini
   [client]
   user=TU_USUARIO_MYSQL
   password=TU_CLAVE_MYSQL
   ```
   Permisos **600**.
2. Crea `/home/migue492/respaldos_bd/respaldo.sh` (fuera del docroot):
   ```bash
   #!/bin/bash
   FECHA=$(date +%F_%H%M)
   DEST="/home/migue492/respaldos_bd"
   mysqldump --defaults-extra-file=/home/migue492/.my.cnf \
     --single-transaction --quick TU_BASE_DE_DATOS \
     | gzip > "$DEST/minimark_$FECHA.sql.gz"
   ls -1t "$DEST"/minimark_*.sql.gz | tail -n +15 | xargs -r rm -f
   ```
   Permisos **700**.
3. cPanel → **Cron Jobs**:
   ```
   0 4 * * * /home/migue492/respaldos_bd/respaldo.sh >/dev/null 2>&1
   ```
4. Pruébalo una vez a mano y confirma que aparece un `.sql.gz` en la carpeta.

## 11. Verificación

Misma lista que la sección "Actualizar el servidor" más arriba, punto 4.

---

## Qué NO hacer

- **No borrar `migrar.php`**: es idempotente y se reutiliza en cada cambio
  de esquema — a diferencia de los utilitarios de un solo uso que ya no
  existen, este se queda.
- **No subir `config.php`**: cada ambiente tiene el suyo con sus propias
  credenciales.
- **No asumir que un cambio está en producción** hasta subirlo de verdad por
  cPanel — no hay CI/CD, nada se despliega solo.
