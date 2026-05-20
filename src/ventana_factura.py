"""Ventana de detalle de una factura: PDF a la izquierda, datos a la derecha.

Se abre con doble clic desde el buscador. La extracción de productos y el
cálculo de precios sugeridos se agregan en fases posteriores.
"""

from __future__ import annotations

import threading
import tkinter as tk
from pathlib import Path
from tkinter import messagebox, ttk

import fitz  # PyMuPDF
from PIL import Image, ImageTk

from db import Database, FilaFactura


def _formato_peso(valor: float | None) -> str:
    """119068.0 → '$119.068' (formato de peso chileno)."""
    if valor is None:
        return "—"
    return f"${valor:,.0f}".replace(",", ".")


def _num(valor: float | None) -> str:
    """Muestra una cantidad: 12.0 → '12', 2.5 → '2,5', None → ''."""
    if valor is None:
        return ""
    if float(valor) == int(valor):
        return str(int(valor))
    return f"{valor:g}".replace(".", ",")


def _parsear_numero(texto: str) -> float | None:
    """Convierte el texto de una celda en número: '4.674' → 4674.0, '2,5' → 2.5.
    Texto vacío → None. Lanza ValueError si no es un número válido."""
    limpio = texto.strip().replace("$", "").replace(" ", "")
    if not limpio:
        return None
    return float(limpio.replace(".", "").replace(",", "."))


class VisorPDF(ttk.Frame):
    """Muestra todas las páginas de un PDF en un área con scroll y zoom."""

    _MARGEN = 12
    _ZOOM_MIN = 0.5
    _ZOOM_MAX = 4.0
    _ZOOM_PASO = 0.25

    def __init__(self, master: tk.Misc, ruta_pdf: Path) -> None:
        super().__init__(master)
        self.ruta_pdf = ruta_pdf
        self.zoom = 1.3
        self._imagenes: list[ImageTk.PhotoImage] = []  # refs vivas (evita que el GC las borre)
        self.doc: fitz.Document | None = None

        # Barra de herramientas (zoom)
        barra = ttk.Frame(self)
        barra.pack(fill="x")
        ttk.Button(barra, text="−", width=3, command=self.alejar).pack(side="left", padx=(2, 0), pady=2)
        ttk.Button(barra, text="+", width=3, command=self.acercar).pack(side="left", padx=2, pady=2)
        self.lbl_zoom = ttk.Label(barra, text="")
        self.lbl_zoom.pack(side="left", padx=8)
        ttk.Label(barra, text="(Ctrl + rueda del mouse para hacer zoom donde apuntes)",
                  foreground="#999").pack(side="left", padx=4)

        # Área de visualización: canvas con scroll vertical
        cont = ttk.Frame(self)
        cont.pack(fill="both", expand=True)
        self.canvas = tk.Canvas(cont, background="#3c3c3c", highlightthickness=0)
        scroll = ttk.Scrollbar(cont, orient="vertical", command=self.canvas.yview)
        self.canvas.configure(yscrollcommand=scroll.set)
        self.canvas.pack(side="left", fill="both", expand=True)
        scroll.pack(side="right", fill="y")
        self.canvas.bind("<MouseWheel>", self._scroll_rueda)
        self.canvas.bind("<Control-MouseWheel>", self._zoom_rueda)

        try:
            self.doc = fitz.open(ruta_pdf)
        except Exception:  # PDF corrupto o ilegible
            self.doc = None

        self._render()

    def _render(self) -> None:
        """Renderiza todas las páginas al zoom actual y las apila en el canvas."""
        self.canvas.delete("all")
        self._imagenes.clear()

        if self.doc is None:
            self.canvas.create_text(
                20, 20, anchor="nw", fill="white",
                text="No se pudo mostrar el PDF.",
            )
            self.lbl_zoom.configure(text="")
            return

        matriz = fitz.Matrix(self.zoom, self.zoom)
        items: list[tuple[int, int]] = []
        y = self._MARGEN
        ancho_max = 0
        for num in range(self.doc.page_count):
            pagina = self.doc.load_page(num)
            pix = pagina.get_pixmap(matrix=matriz, alpha=False)
            img = Image.frombytes("RGB", (pix.width, pix.height), pix.samples)
            foto = ImageTk.PhotoImage(img)
            self._imagenes.append(foto)
            item = self.canvas.create_image(0, y, image=foto, anchor="nw")
            items.append((item, foto.width()))
            y += foto.height() + self._MARGEN
            ancho_max = max(ancho_max, foto.width())

        # Centrar horizontalmente las páginas más angostas
        for item, ancho in items:
            cx = max((ancho_max - ancho) // 2, 0) + self._MARGEN
            self.canvas.coords(item, cx, self.canvas.coords(item)[1])

        self.canvas.configure(scrollregion=(0, 0, ancho_max + 2 * self._MARGEN, y))
        self.lbl_zoom.configure(text=f"{round(self.zoom * 100)}%")

    def _aplicar_zoom(self, nuevo_zoom: float, ancla_x: int, ancla_y: int) -> None:
        """Cambia el zoom manteniendo fijo el punto del documento que está bajo
        (ancla_x, ancla_y), coordenadas relativas al canvas."""
        nuevo_zoom = max(self._ZOOM_MIN, min(self._ZOOM_MAX, nuevo_zoom))
        if self.doc is None or abs(nuevo_zoom - self.zoom) < 1e-6:
            return
        # Punto del documento que está bajo el ancla, antes de re-renderizar
        doc_x = self.canvas.canvasx(ancla_x)
        doc_y = self.canvas.canvasy(ancla_y)
        factor = nuevo_zoom / self.zoom
        self.zoom = nuevo_zoom
        self._render()
        # Reposicionar la vista para que ese mismo punto quede bajo el ancla
        region = self.canvas.cget("scrollregion").split()
        if len(region) == 4:
            ancho_total, alto_total = float(region[2]), float(region[3])
            if ancho_total > 0:
                self.canvas.xview_moveto(max(0.0, (doc_x * factor - ancla_x) / ancho_total))
            if alto_total > 0:
                self.canvas.yview_moveto(max(0.0, (doc_y * factor - ancla_y) / alto_total))

    def acercar(self) -> None:
        self._aplicar_zoom(self.zoom + self._ZOOM_PASO,
                           self.canvas.winfo_width() // 2,
                           self.canvas.winfo_height() // 2)

    def alejar(self) -> None:
        self._aplicar_zoom(self.zoom - self._ZOOM_PASO,
                           self.canvas.winfo_width() // 2,
                           self.canvas.winfo_height() // 2)

    def _zoom_rueda(self, evento: tk.Event) -> None:
        """Ctrl + rueda: hace zoom apuntando al lugar exacto del cursor."""
        paso = self._ZOOM_PASO if evento.delta > 0 else -self._ZOOM_PASO
        self._aplicar_zoom(self.zoom + paso, evento.x, evento.y)

    def _scroll_rueda(self, evento: tk.Event) -> None:
        self.canvas.yview_scroll(-1 if evento.delta > 0 else 1, "units")

    def cerrar(self) -> None:
        if self.doc is not None:
            self.doc.close()
            self.doc = None


class PanelDetalle(ttk.Frame):
    """Panel derecho: datos de la factura, productos extraídos por IA y el
    precio de venta sugerido de cada uno. Permite corregir los valores a mano."""

    # Columnas de la tabla (en orden) y su mapeo a campos editables
    _COLUMNAS = ("descripcion", "cantidad", "precio", "monto", "iva", "sugerido")
    _CAMPO_DB = {
        "descripcion": "descripcion",
        "cantidad": "cantidad",
        "precio": "precio_unitario",
        "monto": "monto",
    }

    def __init__(
        self, master: tk.Misc, fila: FilaFactura, db: Database, config: dict
    ) -> None:
        super().__init__(master, padding=12)
        self.fila = fila
        self.db = db
        self.config = config
        precios = config.get("precios", {})
        self._iva = float(precios.get("iva", 0.19))
        self._margen = float(precios.get("margen", 0.35))
        self._redondeo = int(precios.get("redondear_a", 100))
        self._productos: dict[int, object] = {}

        # --- Información de la factura: todo en una línea, dentro de un contenedor ---
        info = ttk.LabelFrame(self, text="Información de la factura", padding=8)
        info.pack(fill="x", pady=(0, 10))
        ttk.Label(info, text="Proveedor:", foreground="#666").pack(side="left")
        ttk.Label(info, text=fila.proveedor,
                  font=("Segoe UI", 9, "bold")).pack(side="left", padx=(4, 18))
        ttk.Label(info, text="RUT:", foreground="#666").pack(side="left")
        ttk.Label(info, text=fila.rut_emisor or "—").pack(side="left", padx=(4, 18))
        ttk.Label(info, text="Fecha:", foreground="#666").pack(side="left")
        ttk.Label(info, text=fila.fecha).pack(side="left", padx=(4, 0))

        # --- Encabezado de la sección de productos ---
        cabecera = ttk.Frame(self)
        cabecera.pack(fill="x")
        ttk.Label(cabecera, text="Productos y precios sugeridos",
                  font=("Segoe UI", 11, "bold")).pack(side="left")
        self.btn_analizar = ttk.Button(
            cabecera, text="Analizar productos con IA", command=self._iniciar_analisis)
        self.btn_analizar.pack(side="right")

        self.lbl_estado = ttk.Label(self, text="", foreground="#666", wraplength=430)
        self.lbl_estado.pack(anchor="w", pady=(6, 1))
        ttk.Label(self, text="Doble clic en una celda para corregir un valor.",
                  foreground="#999").pack(anchor="w", pady=(0, 4))

        # --- Tabla de productos ---
        marco = ttk.Frame(self)
        marco.pack(fill="both", expand=True)
        self.tabla = ttk.Treeview(marco, columns=self._COLUMNAS, show="headings")
        encabezados = {
            "descripcion": ("Producto", 150),
            "cantidad": ("Cant.", 45),
            "precio": ("P. unitario", 75),
            "monto": ("Monto", 75),
            "iva": ("IVA", 40),
            "sugerido": ("P. sugerido", 90),
        }
        for col, (titulo, ancho) in encabezados.items():
            self.tabla.heading(col, text=titulo)
            self.tabla.column(col, width=ancho, anchor="w")
        self.tabla.column("cantidad", anchor="center")
        self.tabla.column("iva", anchor="center")
        for col in ("precio", "monto", "sugerido"):
            self.tabla.column(col, anchor="e")
        self.tabla.tag_configure("editado", background="#fff6d5")
        scroll = ttk.Scrollbar(marco, orient="vertical", command=self.tabla.yview)
        self.tabla.configure(yscrollcommand=scroll.set)
        self.tabla.pack(side="left", fill="both", expand=True)
        scroll.pack(side="right", fill="y")
        self.tabla.bind("<Double-1>", self._doble_clic)

        self._cargar_existente()

    # --- Carga y refresco de la tabla ---

    def _cargar_existente(self) -> None:
        """Si la factura ya fue analizada, recalcula precios y muestra el detalle."""
        if self.db.tiene_detalle(self.fila.id):
            self.db.recalcular_precios(
                self.fila.id, self._iva, self._margen, self._redondeo)
            self.btn_analizar.config(text="Re-analizar con IA")
            self._refrescar()
        else:
            self.lbl_estado.config(
                text="Aún sin analizar. Presiona el botón para extraer los productos con IA.")

    def _refrescar(self) -> None:
        """Vuelve a leer los productos de la BD y repuebla la tabla."""
        productos = self.db.obtener_productos(self.fila.id)
        self._productos = {p.id: p for p in productos}
        self.tabla.delete(*self.tabla.get_children())
        for p in productos:
            self.tabla.insert(
                "", "end", iid=str(p.id),
                values=self._valores_fila(p),
                tags=("editado",) if p.editado_manual else (),
            )
        meta = self.db.obtener_meta_detalle(self.fila.id)
        self.lbl_estado.config(text=self._texto_estado(len(productos), meta))

    @staticmethod
    def _valores_fila(p: object) -> tuple:
        return (
            p.descripcion,                                          # type: ignore[attr-defined]
            _num(p.cantidad),                                       # type: ignore[attr-defined]
            _formato_peso(p.precio_unitario) if p.precio_unitario is not None else "",  # type: ignore[attr-defined]
            _formato_peso(p.monto) if p.monto is not None else "",  # type: ignore[attr-defined]
            "Sí" if p.afecto_iva else "No",                         # type: ignore[attr-defined]
            _formato_peso(p.precio_sugerido) if p.precio_sugerido is not None else "—",  # type: ignore[attr-defined]
        )

    @staticmethod
    def _texto_estado(cantidad: int, meta: dict | None) -> str:
        texto = f"{cantidad} producto(s)."
        if meta:
            tipo = "con IVA incluido" if meta["precios_incluyen_iva"] else "netos (sin IVA)"
            texto += f"  Precios {tipo}."
        return texto

    # --- Análisis con IA ---

    def _iniciar_analisis(self) -> None:
        instrucciones: str | None = None
        if self.db.tiene_detalle(self.fila.id):
            instrucciones = self._pedir_instrucciones()
            if instrucciones is None:  # el usuario canceló
                return
        self.btn_analizar.config(state="disabled")
        self.lbl_estado.config(
            text="Analizando la factura con IA… esto puede tardar unos segundos.")
        threading.Thread(
            target=self._worker, args=(instrucciones,), daemon=True).start()

    def _worker(self, instrucciones: str | None) -> None:
        """Corre en un hilo aparte para no congelar la ventana durante la API."""
        try:
            from classifier import Clasificador
            from extractor import extraer_completo

            max_img = self.config["clasificacion"].get("max_imagenes_detalle", 24)
            contenido = extraer_completo(
                Path(self.fila.ruta_archivo), max_imagenes=max_img)
            clasificador = Clasificador(modelo=self.config["clasificacion"]["modelo"])
            detalle = clasificador.extraer_detalle(contenido, instrucciones or None)
            self.db.guardar_detalle(self.fila.id, detalle)
            self.db.recalcular_precios(
                self.fila.id, self._iva, self._margen, self._redondeo)
            resultado: tuple = ("ok", detalle)
        except Exception as exc:  # noqa: BLE001 — se reporta al usuario
            resultado = ("error", exc)
        try:
            self.after(0, lambda: self._finalizar(*resultado))
        except tk.TclError:
            pass  # la ventana se cerró durante el análisis

    def _finalizar(self, tipo: str, dato: object) -> None:
        self.btn_analizar.config(state="normal")
        if tipo == "error":
            self.lbl_estado.config(text="No se pudo completar el análisis.")
            messagebox.showerror(
                "Error al analizar",
                f"Ocurrió un error al analizar la factura:\n\n{dato}", parent=self)
            return
        self.btn_analizar.config(text="Re-analizar con IA")
        self._refrescar()
        if dato.notas:  # type: ignore[attr-defined]
            messagebox.showinfo(
                "Observaciones de la IA", dato.notas, parent=self)  # type: ignore[attr-defined]

    def _pedir_instrucciones(self) -> str | None:
        """Diálogo modal para una pista opcional a la IA antes de re-analizar.
        Devuelve el texto escrito (puede ser '') o None si el usuario cancela."""
        dlg = tk.Toplevel(self)
        dlg.title("Re-analizar con IA")
        dlg.transient(self.winfo_toplevel())
        dlg.resizable(False, False)
        ttk.Label(dlg, text="Instrucción para la IA (opcional):",
                  font=("Segoe UI", 9, "bold")).pack(anchor="w", padx=12, pady=(12, 2))
        ttk.Label(
            dlg, foreground="#666", justify="left",
            text="Si la factura tiene un formato difícil, escribe una pista.\n"
                 "Ejemplos: 'el monto real está en la última columna',\n"
                 "'los precios ya incluyen IVA', 'ignora la fila de flete'.",
        ).pack(anchor="w", padx=12, pady=(0, 6))
        caja = tk.Text(dlg, height=4, width=54, wrap="word")
        caja.pack(padx=12)
        resultado: dict[str, str] = {}
        botones = ttk.Frame(dlg, padding=12)
        botones.pack(fill="x")

        def aceptar() -> None:
            resultado["valor"] = caja.get("1.0", "end").strip()
            dlg.destroy()

        ttk.Button(botones, text="Analizar", command=aceptar).pack(side="right")
        ttk.Button(botones, text="Cancelar", command=dlg.destroy).pack(side="right", padx=6)
        caja.focus_set()
        dlg.grab_set()
        self.wait_window(dlg)
        return resultado.get("valor")  # None si se cerró sin "Analizar"

    # --- Edición manual de celdas ---

    def _doble_clic(self, evento: tk.Event) -> None:
        fila_id = self.tabla.identify_row(evento.y)
        columna = self.tabla.identify_column(evento.x)
        if not fila_id or not columna:
            return
        indice = int(columna[1:]) - 1
        if not 0 <= indice < len(self._COLUMNAS):
            return
        nombre = self._COLUMNAS[indice]
        if nombre == "sugerido":
            return  # el precio sugerido se calcula solo, no se edita
        if nombre == "iva":
            self._alternar_iva(int(fila_id))
            return
        self._editar_celda(fila_id, columna, nombre)

    def _editar_celda(self, fila_id: str, columna: str, nombre: str) -> None:
        """Coloca una caja de texto sobre la celda para editar su valor."""
        bbox = self.tabla.bbox(fila_id, columna)
        if not bbox:
            return
        x, y, ancho, alto = bbox
        producto = self._productos.get(int(fila_id))
        if producto is None:
            return
        valor_inicial = {
            "descripcion": producto.descripcion,                     # type: ignore[attr-defined]
            "cantidad": _num(producto.cantidad),                     # type: ignore[attr-defined]
            "precio": _num(producto.precio_unitario),                # type: ignore[attr-defined]
            "monto": _num(producto.monto),                           # type: ignore[attr-defined]
        }.get(nombre, "")
        editor = ttk.Entry(self.tabla)
        editor.place(x=x, y=y, width=ancho, height=alto)
        editor.insert(0, valor_inicial)
        editor.select_range(0, "end")
        editor.focus_set()

        def confirmar(_evento: tk.Event | None = None) -> None:
            texto = editor.get()
            editor.destroy()
            self._guardar_edicion(int(fila_id), nombre, texto)

        editor.bind("<Return>", confirmar)
        editor.bind("<FocusOut>", confirmar)
        editor.bind("<Escape>", lambda _e: editor.destroy())

    def _guardar_edicion(self, producto_id: int, nombre: str, texto: str) -> None:
        campo_db = self._CAMPO_DB[nombre]
        if nombre == "descripcion":
            valor: object = texto.strip()
            if not valor:
                return  # no se permite descripción vacía
        else:
            try:
                valor = _parsear_numero(texto)
            except ValueError:
                messagebox.showwarning(
                    "Valor inválido",
                    f"'{texto}' no es un número válido.", parent=self)
                return
        self.db.actualizar_producto(
            producto_id, **{campo_db: valor, "editado_manual": 1})
        self.db.recalcular_precios(
            self.fila.id, self._iva, self._margen, self._redondeo)
        self._refrescar()

    def _alternar_iva(self, producto_id: int) -> None:
        """Doble clic en la columna IVA: cambia el producto entre afecto y exento."""
        producto = self._productos.get(producto_id)
        if producto is None:
            return
        nuevo = 0 if producto.afecto_iva else 1  # type: ignore[attr-defined]
        self.db.actualizar_producto(
            producto_id, afecto_iva=nuevo, editado_manual=1)
        self.db.recalcular_precios(
            self.fila.id, self._iva, self._margen, self._redondeo)
        self._refrescar()


class VentanaFactura(tk.Toplevel):
    """Ventana con el PDF y los datos de una factura, lado a lado."""

    def __init__(
        self, master: tk.Misc, fila: FilaFactura, db: Database, config: dict
    ) -> None:
        super().__init__(master)
        self.title(f"Factura  ·  {fila.proveedor}  ·  {fila.fecha}")
        self.geometry("1150x720")
        self.minsize(700, 450)

        ruta = Path(fila.ruta_archivo)
        if not ruta.exists():
            messagebox.showwarning(
                "Archivo no encontrado",
                f"El PDF ya no está en:\n{ruta}", parent=master)
            self.destroy()
            return

        panel = ttk.PanedWindow(self, orient="horizontal")
        panel.pack(fill="both", expand=True)

        self.visor = VisorPDF(panel, ruta)
        panel.add(self.visor, weight=1)

        self.panel_detalle = PanelDetalle(panel, fila, db, config)
        panel.add(self.panel_detalle, weight=1)

        self.protocol("WM_DELETE_WINDOW", self._cerrar)
        # Dividir la ventana 50/50 una vez que ya tiene su tamaño real
        self.after(120, lambda: panel.sashpos(0, self.winfo_width() // 2))

    def _cerrar(self) -> None:
        self.visor.cerrar()
        self.destroy()


def abrir_ventana_factura(
    master: tk.Misc, fila: FilaFactura, db: Database, config: dict
) -> None:
    """Abre la ventana de detalle para una factura."""
    VentanaFactura(master, fila, db, config)
