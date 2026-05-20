# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Contexto

Sistema que organiza automáticamente facturas escaneadas (escáner Brother DS-640 deja PDFs en una carpeta vigilada). Pensado para un almacén de barrio en Chile: las trabajadoras escanean y el sistema clasifica, renombra y archiva sin intervención. El idioma del código, comentarios y mensajes al usuario es **español** — mantenerlo así.

## Comandos

Requiere Python 3.x en Windows. No hay entorno virtual ni instalador automatizado.

```powershell
pip install -r requirements.txt          # dependencias
copy .env.ejemplo .env                   # luego pegar la ANTHROPIC_API_KEY real

py src/main.py                           # modo consola: vigila + logs en pantalla (debug)
py src/main.py --tray                    # modo bandeja del sistema (uso diario)
py src/main.py --reindexar               # re-clasifica los PDFs ya archivados e inserta en BD
py src/buscador.py                       # ventana Tkinter para buscar facturas
py src/prototipo.py [ruta.pdf]           # prueba aislada de extracción+clasificación de un PDF
```

No hay suite de tests ni linter. `tests/facturas_ejemplo/` contiene PDFs de muestra (ignorados por git), no tests automatizados. `prototipo.py` es el banco de pruebas manual para iterar sobre el prompt/herramienta del clasificador sin tocar el pipeline completo.

## Arquitectura

Los módulos en `src/` se importan **planos** (`from classifier import ...`, no `from src.classifier`). Esto funciona solo porque al ejecutar `py src/main.py` Python agrega `src/` al path. No convertir `src/` en paquete ni cambiar a imports relativos sin ajustar todos los puntos de entrada.

**Pipeline de procesamiento** (una factura, en `main.py:crear_procesador`):
1. `extractor.extraer` — PyMuPDF saca texto + render PNG de la **primera página** a base64.
2. `classifier.Clasificador` — manda texto+imagen a Claude Haiku con `tool_choice` forzado a la herramienta `registrar_factura`; devuelve un `DatosFactura` (frozen dataclass).
3. `db.resolver_proveedor` — resuelve la identidad de la empresa emisora: primero por **RUT** (tabla `empresa_rut`; el RUT es idéntico en todas las facturas de una empresa aunque el modelo escriba la marca distinta), con respaldo por nombre normalizado (tabla `alias_proveedor`; sin acentos ni sufijos/palabras legales). La primera factura de una empresa fija su nombre de carpeta y cada variante nueva de la marca queda aprendida como alias.
4. Ramas por confianza: `< umbral_escaneo_defectuoso` → `_revisar` con aviso de re-escanear; `< umbral_confianza` → `_revisar`; si no, continúa.
5. `db.buscar_duplicado` — match por `numero_factura` + `rut_emisor`; si existe, la versión vieja va a `_reemplazadas` y se borra de la BD.
6. `organizer.archivar` — mueve el PDF a `AÑO/MesEnEspañol/Marca/factura_DD-MM-YYYY.pdf`, con sufijo numérico ante colisión; `db.registrar_factura` inserta el registro.

Cualquier excepción manda el PDF a `_errores` con un `.txt` de log adjunto.

**Vigilancia** (`watcher.py`): watchdog detecta PDFs nuevos. Dos esperas antes de procesar: estabilización (el archivo deja de crecer = el escáner terminó) y un **período de gracia** configurable — si el PDF se borra de `_entrada` durante ese tiempo, no se procesa (margen para que la vendedora cancele un mal escaneo).

**Modo bandeja** (`tray.py` + `estado.py`): el observer corre en su propio hilo y el ícono pystray en el hilo principal. `Estado` es el objeto compartido entre ambos (con `Lock`): contadores diarios y bandera de pausa. Al reanudar tras pausa se reprocesa lo que quedó en `_entrada`. Sin consola (autoarranque vía `pyw.exe`), los logs van a `data/logs/AAAA-MM-DD.log`.

**Base de datos** (`db.py`): SQLite con tabla `facturas`, `alias_proveedor`, y una tabla FTS5 (`facturas_fts`) mantenida por triggers para búsqueda full-text. El buscador Tkinter consulta por texto libre, proveedor y rango de fechas.

## Convenciones importantes

- **Marca vs. razón social**: `proveedor` es la marca comercial visible en el logo (se usa para el nombre de carpeta); `razon_social` + `rut_emisor` son los datos legales del emisor (solo metadatos en BD, para conciliación con el SII). El prompt del clasificador pide ambos explícitamente. No usar la razón social para la estructura de carpetas.
- **Formatos de fecha**: `DatosFactura.fecha` y los nombres de archivo usan `DD-MM-YYYY`; la columna `fecha` de la BD usa `YYYY-MM-DD`. La conversión ocurre en `db.registrar_factura`.
- **Toda ruta y umbral vive en `config.yaml`** — no hardcodear rutas. Las carpetas de datos (`C:\Facturas\` con `_entrada`, `_revisar`, `_errores`, `_reemplazadas` y la estructura `AÑO/Mes/Marca/`) están fuera del repo.
- El usuario no es desarrollador: explicar decisiones técnicas en términos prácticos, no en jerga.
