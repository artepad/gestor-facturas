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

```
https://admin.minimark.cl/            → "Servidor activo"
```

El endpoint `api/facturas.php` solo acepta POST con token; abrirlo en el
navegador devolverá "Metodo no permitido", lo cual es correcto.
