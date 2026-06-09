# Servidor web (admin.minimark.cl) — Fase 1: recepción de facturas

Esta carpeta `web/` contiene la API que recibe las facturas sincronizadas
desde los PCs. Stack: PHP 8.4 + MySQL, sin frameworks.

## Estructura

```
web/
├── index.php              "Servidor activo" (raíz del subdominio)
├── setup.php              instalación inicial (correr UNA vez, luego borrar)
├── config.ejemplo.php     plantilla de configuración
├── config.php             ← lo creas tú con tus datos (NO se sube a git)
├── .htaccess              seguridad + HTTPS forzado
├── lib/
│   ├── db.php             conexión PDO
│   └── esquema.sql        definición de las tablas
├── api/
│   └── facturas.php       POST: recibe una factura
└── almacen_pdf/           PDFs subidos (bloqueado por web)
```

## Instalación en HostGator (cPanel)

### 1. Subir los archivos

1. cPanel → **Administrador de archivos**.
2. Entra a la carpeta del subdominio: `/home1/migue492/admin.minimark.cl`.
3. Sube **todo el contenido** de esta carpeta `web/` ahí dentro
   (puedes comprimir `web/` en zip, subirlo y extraer).

### 2. Crear config.php

1. En el Administrador de archivos, copia `config.ejemplo.php` → renómbralo `config.php`.
2. Edítalo (clic derecho → Edit) y pon:
   - La **contraseña** del usuario MySQL que creaste.
   - Una **setup_key** larga inventada (ej. 30 caracteres al azar).

### 3. Correr el setup

En el navegador, entra a (reemplaza TU_SETUP_KEY por la que pusiste):

```
https://admin.minimark.cl/setup.php?key=TU_SETUP_KEY&negocio=Minimark&slug=minimark&maquina=PC+Principal
```

- Crea las tablas.
- Crea el negocio "Minimark".
- Genera el **TOKEN de la máquina** → cópialo (se muestra una sola vez).

### 4. Borrar setup.php

Por seguridad, borra `setup.php` del servidor después de usarlo.

### 5. Guardar el token

Ese token va en el config del PC de ese negocio (lo configura el cliente
Python en la siguiente fase). Cada negocio/PC tiene su propio token: vuelve
a correr el setup con otro `&slug=` y `&maquina=` para generar otro.

## Probar que la API responde

El endpoint `api/facturas.php` solo acepta POST con token; abrirlo en el
navegador devolverá "Metodo no permitido", lo cual es correcto.

## Dashboard web (login + listado de facturas)

### Crear tu usuario administrador

Una vez subidos los archivos y corrido el setup, crea tu usuario:

```
https://admin.minimark.cl/crear_admin.php?key=TU_SETUP_KEY&email=tu@correo.cl&clave=TuClave123&nombre=Miguel&rol=admin
```

- `rol=admin` ve todos los negocios.
- Para una trabajadora: `rol=sucursal` y agrega `&negocio=ID` (el id del
  negocio que se mostro al crear el negocio en setup).

**Borra `crear_admin.php` del servidor** después de crear tus usuarios.

### Entrar

```
https://admin.minimark.cl/            → redirige al login
https://admin.minimark.cl/login.php   → ingresar
```

Tras ingresar ves el panel con:
- Filtros: búsqueda libre, negocio (si ves más de uno), proveedor, rango de fechas.
- Tabla de facturas con estado (verde/amarillo/rojo) por confianza de lectura.
- Botón "Ver" para abrir el PDF (solo de negocios que te corresponden).

### Archivos del dashboard

```
login.php          formulario de ingreso
logout.php         cerrar sesión
panel_facturas.php listado + filtros (requiere login)
factura.php        detalle de una factura (requiere login)
ver_pdf.php        sirve el PDF con control de acceso por negocio
crear_admin.php    crea usuarios (correr 1 vez, luego borrar)
lib/auth.php       sesión y permisos
assets/estilo.css  tema visual
```
