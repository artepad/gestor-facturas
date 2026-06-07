# CONTRATO.md — Puente entre la app de Python y la web

Este documento define el **contrato de sincronización**: el acuerdo exacto sobre
cómo el cliente de escritorio (Python, en `src/`) le envía facturas a la web
(PHP, en `web/`). **Los dos lados deben respetar este documento.** Si cambiás algo
acá, hay que cambiarlo en ambos lados *y* actualizar este archivo en el mismo
commit. Romper el contrato = las facturas dejan de sincronizarse silenciosamente.

> **Para el chat de la web**: este es el formato que recibís en `api/facturas.php`.
> **Para el chat de Python**: este es el formato que envía `src/sync.py` +
> `Database.factura_para_sync()`.

## Visión general

- La **BD local de cada PC es la fuente de verdad**. La web es un espejo.
- Cada cambio local (alta/edición/borrado de factura) deja un item en la tabla
  local `sync_cola`. Un worker en thread (`SyncWorker` en `src/sync.py`) la vacía:
  arma el payload, hace `POST`, y borra el item si tuvo éxito. Si falla, backoff
  exponencial (`[60, 300, 900, 3600]` s) y reintenta. Sin internet → la cola se
  acumula y se vacía sola al volver la conexión. **Nada se pierde.**
- La sincronización es **opcional**: si `config.sincronizacion.activado` es False
  o falta el token, el worker no arranca.

## Endpoint

```
POST {url}/api/facturas.php
Header:  Authorization: Bearer <token-de-la-maquina>
Body:    multipart/form-data
           - datos : string JSON (ver abajo)
           - pdf   : archivo PDF (opcional; solo en "guardar")
```

- El **token** identifica la máquina. El servidor guarda solo `sha256(token)` en
  `maquinas.token_hash`; de ahí resuelve `negocio_id`. **El cliente nunca envía
  `negocio_id`**: lo deduce el servidor desde el token. Así un PC solo puede
  escribir en su propio negocio.
- **User-Agent realista obligatorio**: el WAF (Mod_Security de HostGator) responde
  **HTTP 406** a `python-requests/x.y`. `sync.py` manda un User-Agent de navegador.
  Además hay una pausa de 0.5 s entre envíos para no parecer un ataque de ráfaga.

## Payload "guardar" (alta o edición)

El cliente arma esto en `Database.factura_para_sync()` y lo manda como el campo
`datos` (JSON). El PDF va aparte como archivo `pdf`.

```json
{
  "uuid_local":     "string (UUID v4, MISMO en local y web — clave de idempotencia)",
  "proveedor":      "string | null",
  "razon_social":   "string | null",
  "rut_emisor":     "string | null",
  "numero_factura": "string | null",
  "fecha":          "YYYY-MM-DD (string)",
  "total":          "número (DECIMAL en CLP, ya parseado; ver nota CLP)",
  "moneda":         "string (ej. 'CLP')",
  "confianza":      "número 0..1 | null",
  "notas":          "string | null",
  "ruta_archivo":   "ruta local del PDF (string; el servidor NO la usa, sirve para adjuntar el archivo)",
  "detalle": [
    {
      "descripcion":     "string",
      "cantidad":        "número | null",
      "precio_unitario": "número | null",
      "descuento":       "número | null",
      "monto":           "número | null",
      "afecto_iva":      "0 | 1 (o booleano)",
      "precio_sugerido": "número | null"
    }
  ]
}
```

Comportamiento del servidor (`api/facturas.php`):
- **Upsert por `uuid_local`**: si ya existe esa factura → `UPDATE`; si no → `INSERT`.
  Por eso los reintentos del cliente no generan duplicados, y restaurar una BD
  local no re-crea facturas en la web.
- `negocio_id` se asigna desde el token, no del payload.
- Si viene `pdf`, se guarda en `almacen_pdf/{negocio_id}/{uuid_local}.pdf` y se
  registra `ruta_pdf` relativa.
- El `detalle` (si viene) **reemplaza completo** el detalle previo de esa factura.
- Cada operación deja una fila en `sync_log` y actualiza `maquinas.ultima_sync`.

## Payload "eliminar" (borrado)

El borrado local es físico (la factura se va de la BD del PC), pero en la web es
**soft-delete**: se marca `eliminada_en = NOW()` y deja de aparecer en los
listados. Payload mínimo:

```json
{ "uuid_local": "string", "eliminar": true }
```

(Sin archivo `pdf`.) El servidor hace
`UPDATE facturas SET eliminada_en = NOW() WHERE uuid_local = ? AND negocio_id = ?`.

## Respuestas

| Código | Significado | Acción del cliente |
|--------|-------------|--------------------|
| `200`  | OK (`{"ok":true,"accion":"insert"\|"update"\|"eliminar"}`) | borrar item de la cola |
| `401`  | Token inválido / falta Bearer | NO reintentar a ciegas: revisar config |
| `400`  | JSON inválido o falta `uuid_local` | error de payload (bug del cliente) |
| `405`  | No es POST | bug del cliente |
| `406`  | WAF bloqueó (User-Agent) | revisar headers |
| `500`  | Error del servidor | reintentar con backoff |

## Nota CLP (separador de miles) — CRÍTICO

En Chile el `.` es **separador de miles**, no decimal: `18.689` = dieciocho mil
seiscientos ochenta y nueve, NO 18,69. **Toda la conversión de texto a número ya
se hace en el lado Python** (`validacion.parsear_monto_chileno`) antes de armar el
payload. Por eso los campos numéricos del payload (`total`, `monto`,
`precio_unitario`, etc.) llegan **ya como números correctos** y el servidor los
guarda tal cual en columnas `DECIMAL(14,2)`. **La web NO debe reinterpretar ni
re-parsear esos montos**; solo formatearlos para mostrar con `clp()`.

## Mapa de campos local (Python) ↔ web (MySQL)

| Payload / `facturas` local (SQLite) | `facturas` web (MySQL) |
|-------------------------------------|------------------------|
| `uuid_local`                        | `uuid_local` (UNIQUE)  |
| (token → ) —                        | `negocio_id`           |
| `proveedor`                         | `proveedor`            |
| `razon_social`                      | `razon_social`         |
| `rut_emisor`                        | `rut_emisor`           |
| `numero_factura`                    | `numero_factura`       |
| `fecha` (YYYY-MM-DD)                | `fecha` (DATE)         |
| `total`                             | `total` (DECIMAL)      |
| `moneda`                            | `moneda`               |
| `confianza`                         | `confianza`            |
| `notas`                             | `notas`                |
| `ruta_archivo` (+ archivo `pdf`)    | `ruta_pdf` (relativa)  |
| `detalle[]` (tabla `producto`)      | `detalle_factura`      |

Tabla `producto` (local) → `detalle_factura` (web): `descripcion`, `cantidad`,
`precio_unitario`, `descuento`, `monto`, `afecto_iva`, `precio_sugerido`.

## Si necesitás agregar un campo nuevo a las facturas

Hacé los cambios **en este orden, idealmente en un solo commit**:

1. Local: agregar la columna en `src/db.py` (esquema + `_migrar()`).
2. Local: incluirla en el payload de `Database.factura_para_sync()`.
3. Web: agregar la columna en `web/lib/esquema.sql` + `web/migrar.php`.
4. Web: aceptarla en el upsert de `web/api/facturas.php`.
5. Web: mostrarla donde corresponda (`panel.php`, etc.).
6. Actualizar este `CONTRATO.md`.
7. Avisar al usuario que corra `migrar.php` en el servidor y suba los archivos.

Saltarse cualquier paso = desincronización silenciosa.
