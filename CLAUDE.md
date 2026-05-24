# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Contexto

Sistema que organiza automáticamente facturas escaneadas (escáner Brother DS-640 deja PDFs en una carpeta vigilada). Pensado para un almacén de barrio en Chile: las trabajadoras escanean y el sistema clasifica, renombra y archiva sin intervención. Además del archivado automático, la app es un **administrador de facturas**: ventana de búsqueda con acciones (ver, editar, eliminar), ventana de detalle con visor de PDF embebido, y un analizador de productos con cálculo de precios sugeridos. El idioma del código, comentarios y mensajes al usuario es **español** — mantenerlo así.

## Comandos

Requiere Python 3.x en Windows. No hay entorno virtual ni instalador automatizado.

```powershell
pip install -r requirements.txt          # dependencias (incluye tkcalendar)
copy .env.ejemplo .env                   # luego pegar la ANTHROPIC_API_KEY real

py src/main.py                           # modo consola: vigila + logs en pantalla (debug)
py src/main.py --tray                    # modo bandeja del sistema (uso diario)
py src/main.py --reindexar               # re-clasifica los PDFs ya archivados e inserta en BD
py src/buscador.py                       # ventana Tkinter del administrador de facturas
py src/prototipo.py [ruta.pdf]           # prueba aislada de extracción+clasificación de un PDF

py -m unittest discover -s tests         # suite de tests (unittest, sin dependencias externas)
```

`tests/test_validacion.py` cubre el parser de montos chilenos, la detección de fechas futuras y la cooperación con `organizer.parsear_fecha`. Es la única suite por ahora; `tests/facturas_ejemplo/` (ignorada por git) son solo PDFs de muestra para `prototipo.py`. No hay linter configurado.

## Arquitectura

Los módulos en `src/` se importan **planos** (`from classifier import ...`, no `from src.classifier`). Esto funciona solo porque al ejecutar `py src/main.py` o `py src/buscador.py` Python agrega ese directorio al path; el test suite hace lo mismo con `sys.path.insert(0, str(RAIZ / "src"))`. No convertir `src/` en paquete ni cambiar a imports relativos sin ajustar todos los puntos de entrada.

**Pipeline de procesamiento automático** (una factura, en `main.py:crear_procesador`; el mismo flujo, sin moverla, se aplica en `modo_reindexar`):
1. `extractor.extraer` — PyMuPDF saca texto + render PNG de la **primera página** a base64.
2. `classifier.Clasificador.clasificar` — manda texto+imagen + la fecha de hoy a Claude Haiku con `tool_choice` forzado a `registrar_factura`; devuelve un `DatosFactura` (frozen dataclass). **El `total` viene como string** (preserva "221.713" tal cual aparece en la factura, sin que el modelo lo interprete como decimal). La herramienta también devuelve `es_factura: bool` y `tipo_documento: str` — el modelo decide primero si el PDF realmente es una factura comercial chilena con detalle de productos.
3. **Filtro de tipo de documento**: si `datos.es_factura == False`, el PDF se mueve a `_no_facturas` con un `.motivo.txt` y se corta el procesamiento aquí. **No se toca la base de datos ni se crea carpeta** en `AÑO/Mes/Marca`. Esto descarta comprobantes de transferencia, vouchers, cotizaciones, guías, estados de cuenta y cualquier otro PDF que no sea factura, antes de que generen registros sucios.
4. **Validación + reintentos enfocados** (`validacion.validar_datos_factura` y los métodos `Clasificador.verificar_total` / `verificar_fecha`):
   - `parsear_monto_chileno` convierte el total a número entendiendo que el `.` separa miles en CLP. Si el modelo respondió `12.8` para un total CLP, se interpreta como `12800`.
   - Si la validación detecta un **monto CLP sospechosamente bajo**, se hace una **segunda lectura enfocada** (`verificar_total`) con un prompt y herramienta dedicados solo al total. Si esa lectura tiene confianza ≥ 0.75 se reemplaza el valor; si no, la factura queda bloqueada para revisión.
   - Si la fecha quedó **en el futuro**, se hace otra lectura enfocada (`verificar_fecha`) y se reintenta la validación.
   - Si la validación final no pasa, el PDF va a `_revisar` con el detalle del problema antes incluso de evaluar la confianza global.
5. `db.resolver_proveedor` — resuelve la identidad de la empresa emisora: primero por **RUT** (tabla `empresa_rut`; idéntico en todas las facturas de una empresa aunque el modelo escriba la marca distinta), con respaldo por nombre normalizado (tabla `alias_proveedor`; sin acentos ni sufijos/palabras legales). La primera factura de una empresa fija su nombre de carpeta y cada variante nueva de la marca queda aprendida como alias.
6. Ramas por confianza: `< umbral_escaneo_defectuoso` → `_revisar` con aviso de re-escanear; `< umbral_confianza` → `_revisar`.
7. `db.buscar_duplicado` — match por `numero_factura` + `rut_emisor`; si existe, la versión vieja va a `_reemplazadas` y se borra de la BD.
8. `organizer.archivar` — mueve el PDF a `AÑO/MesEnEspañol/Marca/factura_DD-MM-YYYY.pdf`, con sufijo numérico ante colisión; `db.registrar_factura` inserta el registro. `organizer.parsear_fecha` **rechaza fechas futuras** (segunda línea de defensa) y `db.registrar_factura` re-valida con `validar_datos_factura` antes de insertar — ambas levantan `ValueError` si algo se coló.

Cualquier excepción del pipeline manda el PDF a `_errores` con un `.txt` de log adjunto.

**Vigilancia** (`watcher.py`): watchdog detecta PDFs nuevos. Dos esperas antes de procesar: estabilización (el archivo deja de crecer = el escáner terminó) y un **período de gracia** configurable — si el PDF se borra de `_entrada` durante ese tiempo, no se procesa (margen para que la vendedora cancele un mal escaneo).

**Modo bandeja** (`tray.py` + `estado.py`): el observer corre en su propio hilo y el ícono pystray en el hilo principal. `Estado` es el objeto compartido entre ambos (con `Lock`): contadores diarios y bandera de pausa. Al reanudar tras pausa se reprocesa lo que quedó en `_entrada`. Sin consola (autoarranque vía `pyw.exe`), los logs van a `data/logs/AAAA-MM-DD.log`.

**Base de datos** (`db.py`): SQLite con tablas `facturas`, `alias_proveedor`, `empresa_rut`, `instruccion_proveedor`, `producto`, `detalle_factura` y la tabla FTS5 (`facturas_fts`) mantenida por triggers (insert, delete y **update** — el `facturas_au` la re-sincroniza al editar una factura). `PRAGMA foreign_keys` está activo en cada conexión: borrar una factura arrastra en cascada `producto`/`detalle_factura`. `Database.__init__` ejecuta el `ESQUEMA` con `IF NOT EXISTS` y luego un pequeño `_migrar()` que aplica `ALTER TABLE` para columnas agregadas después (ej: `detalle_factura.margen_ganancia`). Métodos clave: `actualizar_factura` (edición desde la UI, valida y mueve el PDF si cambia carpeta), `buscar_duplicado(excluir_id=...)` para que una edición no se detecte a sí misma, `guardar_detalle/recalcular_precios/actualizar_producto` para el análisis de productos.

**Buscador como administrador de facturas** (`buscador.py`): la ventana principal. Filtros (texto libre con FTS, proveedor, calendario opcional con `tkcalendar`), tabla con la columna **Estado** (un punto de color verde/amarillo/rojo dibujado como overlay `tk.Label` y reposicionado en scroll/resize/select vía `_programar_puntos_estado`; tooltip explica el motivo) y la columna **Acciones** con 3 íconos. Las acciones se detectan por `identify_column`+`bbox` partiendo el ancho de la celda en tres zonas:
- **Ver** → abre el PDF en el visor predeterminado de Windows (`os.startfile`).
- **Editar** → `DialogoEditarFactura` (modal): valida con `validar_datos_factura`, llama a `db.actualizar_factura`, y si cambió proveedor/fecha **mueve el PDF a la nueva carpeta** y limpia las carpetas vacías que queden (sin tocar `_entrada`/`_revisar`/`_errores`/`_reemplazadas`).
- **Eliminar** → confirma, borra el archivo PDF, limpia carpetas vacías y borra el registro (el `ON DELETE CASCADE` arrastra productos y detalle).

Doble clic en una fila sigue abriendo `ventana_factura.py` (visor de PDF + panel de detalle). El buscador también pasa `db` y `config` a la ventana de factura, así el flujo de análisis de productos tiene todo lo necesario.

**Ventana de detalle de factura** (`ventana_factura.py`): `Toplevel` con cabecera + barra inferior (Cerrar / Modo pantalla completa, Esc para salir). Dividida 50/50 con `ttk.PanedWindow`: a la izquierda `VisorPDF` (renderiza todas las páginas con PyMuPDF en un `tk.Canvas`, scroll con la rueda, **Ctrl+rueda para zoom apuntado al cursor**); a la derecha `PanelDetalle` con datos de cabecera, la tabla de productos y el botón "Analizar productos con IA".

**Analizador de productos** (`ventana_factura.AnalizadorProductos`): es un `Toplevel` modal que se abre **antes** de cada análisis (primer análisis o re-análisis, el mismo flujo). Permite al usuario revisar y editar:
- El **prompt** que se enviará a la IA (precargado con la memoria del proveedor si existe; al confirmar reemplaza esa memoria — vacío la borra).
- El **margen de ganancia** (combobox con 30/35/40/45/50% o un porcentaje personalizado). Se guarda en `detalle_factura.margen_ganancia` y se reusa al reabrir la factura.
- Un checkbox **"Los valores del detalle incluyen IVA"**. Se pasa explícitamente a la IA y luego al cálculo de precios.

Devuelve un `ConfiguracionAnalisisProductos` que el `_worker` usa para llamar a `extraer_completo` + `Clasificador.extraer_detalle` en un hilo aparte.

**Extracción de detalle (bajo demanda, fuera del pipeline automático)**: `extractor.extraer_completo` saca texto + imágenes de todas las páginas (una página muy alta se parte en franjas para no perder resolución); `Clasificador.extraer_detalle` usa la herramienta forzada `registrar_detalle_factura`, acepta `instrucciones` con margen+IVA+prompt del usuario. El resultado —productos línea por línea + si los precios traen IVA— se guarda en `producto`/`detalle_factura` y queda cacheado. Doble clic en una celda de la tabla edita el valor, recalcula el precio y marca la fila como editada (`editado_manual`). Esto NO ocurre en el escaneo automático, solo cuando el usuario lo pide.

**Memoria de instrucciones por proveedor**: las instrucciones que el usuario confirma en el `AnalizadorProductos` se guardan en `instruccion_proveedor` con la clave del **RUT normalizado** del emisor (`db.guardar_instrucciones`). Al abrir el analizador para cualquier factura futura del mismo RUT, esas instrucciones precargan el campo (`db.obtener_instrucciones`). Así el sistema "aprende" a leer las facturas de cada proveedor.

**Precio de venta sugerido** (`precios.py`): valor unitario = `monto/cantidad` (cae a `precio_unitario` si falta `monto`) → + IVA si el producto es afecto y el precio venía neto → + margen → redondeo hacia arriba al múltiplo configurado. Los parámetros base (`iva`, `margen`, `redondear_a`) viven en `config.yaml` bajo `precios`; el margen efectivo de cada factura se sobreescribe con el guardado en `detalle_factura.margen_ganancia`. `db.recalcular_precios` recalcula y persiste el precio sugerido de toda la factura tras cada edición.

**Tema visual** (`estilos.py`): paleta, fuentes y constructores reutilizables (`aplicar_tema`, `cabecera`, `pie`, `boton`, `entrada`, `panel`) para que el buscador, la ventana de factura y los modales (`AnalizadorProductos`, `DialogoEditarFactura`) compartan el mismo look. `aplicar_tema` configura `clam` + el estilo `App.Treeview` (encabezado oscuro, selección azul); cada ventana lo puede ajustar localmente (ej. la ventana de factura usa `rowheight=32`).

## Convenciones importantes

- **Marca vs. razón social**: `proveedor` es la marca comercial visible en el logo (se usa para el nombre de carpeta); `razon_social` + `rut_emisor` son los datos legales del emisor (solo metadatos en BD, para conciliación con el SII). El prompt del clasificador pide ambos explícitamente. No usar la razón social para la estructura de carpetas.
- **Total como texto en el primer salto**: la herramienta `registrar_factura` declara `total` como string para preservar "221.713" exactamente como aparece (en CLP el `.` es separador de miles). La conversión a número la hace `validacion.parsear_monto_chileno`, y el campo en SQLite es `REAL`. Nunca pedir al modelo que devuelva el total como número.
- **Formatos de fecha**: la herramienta `registrar_factura` pide `DD-MM-YYYY`, los nombres de archivo usan `DD-MM-YYYY`; la columna `fecha` de la BD usa `YYYY-MM-DD`. La conversión ocurre en `db.registrar_factura`/`actualizar_factura`. `validacion.parsear_fecha_factura` acepta varios formatos al editar a mano.
- **Fechas futuras prohibidas**: tres líneas de defensa antes de archivar — `validar_datos_factura`, el reintento con `verificar_fecha`, y `organizer.parsear_fecha`. Si una factura intenta archivarse con fecha futura es un bug y queda en `_revisar`/`_errores`.
- **Toda ruta y umbral vive en `config.yaml`** — no hardcodear rutas. Las carpetas de datos (`C:\Facturas\` con `_entrada`, `_revisar`, `_errores`, `_reemplazadas` y la estructura `AÑO/Mes/Marca/`) están fuera del repo.
- **El usuario no es desarrollador**: explicar decisiones técnicas en términos prácticos, no en jerga.
