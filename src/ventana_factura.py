"""Ventana de detalle de una factura: PDF a la izquierda, datos a la derecha.

Se abre con doble clic desde el buscador. La extracción de productos y el
cálculo de precios sugeridos se agregan en fases posteriores.
"""

from __future__ import annotations

import threading
import tkinter as tk
from dataclasses import dataclass, replace
from pathlib import Path
from tkinter import messagebox, ttk

import fitz  # PyMuPDF
from PIL import Image, ImageTk

import estilos
from db import Database, FilaFactura
from version import NOMBRE, __version__


def _formato_peso(valor: float | None) -> str:
    """119068.0 → '$119.068' (formato de peso chileno)."""
    if valor is None:
        return "—"
    return f"${valor:,.0f}".replace(",", ".")


def _formato_fecha_chilena(fecha: str) -> str:
    """YYYY-MM-DD → DD-MM-YYYY. Si ya viene en otro formato, la deja igual."""
    partes = fecha.split("-")
    if len(partes) == 3 and len(partes[0]) == 4:
        return f"{partes[2]}-{partes[1]}-{partes[0]}"
    return fecha


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


def _formato_porcentaje(valor: float) -> str:
    """0.35 -> '35%'."""
    return f"{round(valor * 100):g}%"


def _parsear_margen(texto: str) -> float:
    """Convierte '35%' o '35' a 0.35."""
    limpio = texto.strip().replace("%", "").replace(",", ".")
    if not limpio:
        raise ValueError("Ingresa un porcentaje de ganancia.")
    valor = float(limpio)
    if valor <= 0:
        raise ValueError("El porcentaje de ganancia debe ser mayor que cero.")
    if valor > 1:
        valor /= 100
    if valor > 2:
        raise ValueError("El porcentaje de ganancia parece demasiado alto.")
    return valor


@dataclass(frozen=True)
class ConfiguracionAnalisisProductos:
    prompt: str
    instrucciones_usuario: str
    margen: float
    precios_incluyen_iva: bool


class VisorPDF(tk.Frame):
    """Muestra todas las páginas de un PDF en un área con scroll y zoom."""

    _MARGEN = 12
    _ZOOM_MIN = 0.5
    _ZOOM_MAX = 4.0
    _ZOOM_PASO = 0.25

    def __init__(self, master: tk.Misc, ruta_pdf: Path) -> None:
        super().__init__(master, bg=estilos.FONDO)
        self.ruta_pdf = ruta_pdf
        self.zoom = 1.3
        self._imagenes: list[ImageTk.PhotoImage] = []  # refs vivas (evita que el GC las borre)
        self.doc: fitz.Document | None = None

        # Barra de herramientas (zoom)
        barra = tk.Frame(self, bg=estilos.FONDO)
        barra.pack(fill="x", pady=(0, 8))
        estilos.boton(barra, "−", self.alejar, "gris", grande=False).pack(
            side="left", padx=(0, 6))
        estilos.boton(barra, "+", self.acercar, "gris", grande=False).pack(
            side="left", padx=(0, 10))
        self.lbl_zoom = tk.Label(barra, text="", font=estilos.F_SMALL,
                                 bg=estilos.FONDO, fg=estilos.TEXTO)
        self.lbl_zoom.pack(side="left", padx=8)
        tk.Label(
            barra, text="Ctrl + rueda para hacer zoom donde apuntes",
            font=estilos.F_HINT, bg=estilos.FONDO,
            fg=estilos.TEXTO_TENUE).pack(side="left", padx=4)

        # Área de visualización: canvas con scroll vertical
        cont = tk.Frame(self, bg=estilos.FONDO)
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


class AnalizadorProductos(tk.Toplevel):
    """Ventana modal que centraliza la configuración antes de llamar a la IA."""

    _ANCHO = 920
    _ALTO = 700

    def __init__(
        self,
        master: tk.Misc,
        fila: FilaFactura,
        instrucciones_iniciales: str,
        margen_default: float,
        precios_incluyen_iva_default: bool,
    ) -> None:
        super().__init__(master)
        self.fila = fila
        self.resultado: ConfiguracionAnalisisProductos | None = None
        self.title("Analizador de Productos")
        self.transient(master.winfo_toplevel())
        self.resizable(True, True)
        self.minsize(920, 680)
        self.configure(bg=estilos.FONDO)
        estilos.aplicar_tema(self)
        self._centrar()

        estilos.cabecera(
            self, "Analizador de Productos",
            "Revisa el prompt y los parámetros antes de ejecutar la IA",
            alto=78, franja=6)

        self._construir_barra_inferior()

        cuerpo = tk.Frame(self, bg=estilos.FONDO)
        cuerpo.pack(fill="both", expand=True, padx=28, pady=(18, 8))

        self.margen_var = tk.StringVar(value=_formato_porcentaje(margen_default))
        self.incluye_iva = tk.BooleanVar(value=precios_incluyen_iva_default)

        configuracion = estilos.panel(cuerpo, "Configuración de precios")
        configuracion.pack(fill="x", pady=(0, 16))
        configuracion.columnconfigure(0, weight=1)
        configuracion.columnconfigure(1, weight=1)

        margen_col = tk.Frame(configuracion, bg=estilos.FONDO)
        margen_col.grid(row=0, column=0, sticky="nsew", padx=(0, 18))
        tk.Label(
            margen_col, text="Margen de ganancia sugerido",
            font=estilos.F_BODY_BOLD, bg=estilos.FONDO,
            fg=estilos.TEXTO).pack(anchor="w")
        tk.Label(
            margen_col, text="Porcentaje base para proyectar precios de venta.",
            font=estilos.F_SMALL, bg=estilos.FONDO,
            fg=estilos.TEXTO_SEC, wraplength=330,
            justify="left").pack(anchor="w", pady=(4, 10))
        ttk.Combobox(
            margen_col, textvariable=self.margen_var,
            values=("30%", "35%", "40%", "45%", "50%"),
            state="normal", width=12, font=estilos.F_BODY,
            justify="center").pack(anchor="w")
        tk.Label(
            margen_col,
            text="Puedes escribir un porcentaje personalizado si el proveedor requiere otro margen.",
            font=estilos.F_HINT, bg=estilos.FONDO,
            fg=estilos.TEXTO_TENUE, wraplength=330,
            justify="left").pack(anchor="w", pady=(8, 0))

        iva_col = tk.Frame(configuracion, bg=estilos.FONDO)
        iva_col.grid(row=0, column=1, sticky="nsew", padx=(18, 0))
        tk.Label(
            iva_col, text="Detalle de IVA",
            font=estilos.F_BODY_BOLD, bg=estilos.FONDO,
            fg=estilos.TEXTO).pack(anchor="w")
        tk.Label(
            iva_col,
            text="Indica cómo vienen los valores del detalle para orientar el análisis.",
            font=estilos.F_SMALL, bg=estilos.FONDO,
            fg=estilos.TEXTO_SEC, wraplength=330,
            justify="left").pack(anchor="w", pady=(4, 10))
        tk.Checkbutton(
            iva_col, text="Los valores del detalle incluyen IVA",
            variable=self.incluye_iva, font=estilos.F_BODY_BOLD,
            bg=estilos.FONDO, fg=estilos.TEXTO,
            activebackground=estilos.FONDO, activeforeground=estilos.TEXTO,
            selectcolor="white", cursor="hand2").pack(anchor="w")
        tk.Label(
            iva_col,
            text="Esta opción se enviará a la IA y también se usará para calcular precios.",
            font=estilos.F_HINT, bg=estilos.FONDO,
            fg=estilos.TEXTO_TENUE, wraplength=330,
            justify="left").pack(anchor="w", pady=(6, 0))

        instrucciones_panel = estilos.panel(cuerpo, "Prompt editable para la IA")
        instrucciones_panel.pack(fill="both", expand=True)
        tk.Label(
            instrucciones_panel,
            text="Ajusta las instrucciones antes de ejecutar el análisis. "
                 "Esta será la única llamada a la IA.",
            font=estilos.F_SMALL, bg=estilos.FONDO,
            fg=estilos.TEXTO_SEC, wraplength=760,
            justify="left").pack(anchor="w", pady=(0, 8))
        self.caja = tk.Text(
            instrucciones_panel, height=10, wrap="word", font=estilos.F_BODY,
            bg="white", fg=estilos.TEXTO, relief="flat",
            highlightthickness=2, highlightbackground=estilos.BORDE,
            highlightcolor=estilos.ENTRY_BORDE_ACTIVO,
            insertbackground=estilos.TEXTO)
        self.caja.pack(fill="both", expand=True)
        self.caja.insert("1.0", self._prompt_inicial(instrucciones_iniciales))
        tk.Label(
            instrucciones_panel,
            text=f"El prompt confirmado se guardará como memoria para {self.fila.proveedor}. "
                 "Si lo dejas vacío, se borra lo aprendido.",
            font=estilos.F_HINT, bg=estilos.FONDO, fg=estilos.VERDE_OK,
            wraplength=760, justify="left").pack(anchor="w", pady=(8, 0))

        self.protocol("WM_DELETE_WINDOW", self._cancelar)
        self.caja.focus_set()
        self.grab_set()

    def _centrar(self) -> None:
        padre = self.master.winfo_toplevel()
        padre.update_idletasks()
        x = padre.winfo_rootx() + (padre.winfo_width() - self._ANCHO) // 2
        y = padre.winfo_rooty() + (padre.winfo_height() - self._ALTO) // 2
        x = max(0, min(x, self.winfo_screenwidth() - self._ANCHO))
        y = max(0, min(y, self.winfo_screenheight() - self._ALTO))
        self.geometry(f"{self._ANCHO}x{self._ALTO}+{x}+{y}")

    def _construir_barra_inferior(self) -> None:
        botones = tk.Frame(self, bg=estilos.FONDO, height=82)
        botones.pack(fill="x", side="bottom")
        botones.pack_propagate(False)
        tk.Frame(botones, bg=estilos.BORDE, height=2).pack(fill="x", pady=(0, 12))
        centro = tk.Frame(botones, bg=estilos.FONDO)
        centro.pack(anchor="center")
        estilos.boton(centro, "Cancelar", self._cancelar, "gris").pack(side="left", padx=12)
        estilos.boton(centro, "Analizar con IA", self._aceptar, "verde").pack(side="left", padx=12)

    def _prompt_inicial(self, instrucciones: str) -> str:
        texto = instrucciones.strip()
        if texto:
            return texto
        return (
            "Extrae solo productos reales de la factura. Identifica bien columnas de "
            "cantidad, precio unitario, descuentos y monto de línea. Ignora subtotal, "
            "neto, IVA, total, transporte, datos de despacho y observaciones que no "
            "sean productos."
        )

    def _prompt_final(self, margen: float, prompt_usuario: str) -> str:
        iva = (
            "Los valores del detalle YA incluyen IVA."
            if self.incluye_iva.get()
            else "Los valores del detalle NO incluyen IVA; son precios netos."
        )
        return (
            "Parámetros definidos por el usuario para este análisis:\n"
            f"- Margen de ganancia para precios sugeridos: {_formato_porcentaje(margen)}.\n"
            f"- {iva}\n\n"
            "Prompt revisado por el usuario:\n"
            f"{prompt_usuario.strip()}"
        )

    def _aceptar(self) -> None:
        prompt_usuario = self.caja.get("1.0", "end").strip()
        try:
            margen = _parsear_margen(self.margen_var.get())
        except ValueError as exc:
            messagebox.showwarning("Margen inválido", str(exc), parent=self)
            return
        self.resultado = ConfiguracionAnalisisProductos(
            prompt=self._prompt_final(margen, prompt_usuario),
            instrucciones_usuario=prompt_usuario,
            margen=margen,
            precios_incluyen_iva=self.incluye_iva.get(),
        )
        self.destroy()

    def _cancelar(self) -> None:
        self.resultado = None
        self.destroy()

    def mostrar(self) -> ConfiguracionAnalisisProductos | None:
        self.wait_window()
        return self.resultado


class PanelDetalle(tk.Frame):
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
        super().__init__(master, bg=estilos.FONDO, padx=14, pady=14)
        self.fila = fila
        self.db = db
        self.config = config
        precios = config.get("precios", {})
        self._iva = float(precios.get("iva", 0.19))
        self._margen = float(precios.get("margen", 0.35))
        self._redondeo = int(precios.get("redondear_a", 100))
        self._productos: dict[int, object] = {}

        # --- Información de la factura ---
        info = estilos.panel(self, "Información de la factura")
        info.pack(fill="x", pady=(0, 12))
        for col in (1, 3):
            info.columnconfigure(col, weight=1)

        campos_info = (
            ("Proveedor:", fila.proveedor, estilos.F_BODY_BOLD, 0, 0),
            ("RUT:", fila.rut_emisor or "—", estilos.F_BODY_BOLD, 0, 2),
            ("Fecha:", _formato_fecha_chilena(fila.fecha), estilos.F_BODY_BOLD, 1, 0),
            ("Total:", _formato_peso(fila.total), estilos.F_BODY_BOLD, 1, 2),
        )
        for etiqueta, valor, fuente, fila_grid, columna in campos_info:
            tk.Label(
                info, text=etiqueta, font=estilos.F_BODY,
                bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
            ).grid(row=fila_grid, column=columna, sticky="w", padx=(0, 4), pady=3)
            tk.Label(
                info, text=valor, font=fuente, bg=estilos.FONDO,
                fg=estilos.TEXTO, anchor="w",
            ).grid(row=fila_grid, column=columna + 1, sticky="w", padx=(0, 18), pady=3)

        # --- Encabezado de la sección de productos ---
        cabecera = tk.Frame(self, bg=estilos.FONDO)
        cabecera.pack(fill="x")
        tk.Label(cabecera, text="Productos y precios sugeridos",
                 font=estilos.F_H4, bg=estilos.FONDO,
                 fg=estilos.TEXTO).pack(side="left")
        self.btn_analizar = estilos.boton(
            cabecera, "Analizar productos con IA", self._iniciar_analisis,
            "azul", grande=False)
        self.btn_analizar.pack(side="right")

        self.lbl_estado = tk.Label(
            self, text="", font=estilos.F_SMALL, bg=estilos.FONDO,
            fg=estilos.TEXTO_SEC, wraplength=390, justify="left")
        self.lbl_estado.pack(anchor="w", pady=(6, 1))
        tk.Label(self, text="Doble clic en una celda para corregir un valor.",
                 font=estilos.F_HINT, bg=estilos.FONDO,
                 fg=estilos.TEXTO_TENUE).pack(anchor="w", pady=(0, 1))
        self.lbl_memoria = tk.Label(
            self, text="", font=estilos.F_SMALL, bg=estilos.FONDO,
            fg=estilos.VERDE_OK, wraplength=390, justify="left")
        self.lbl_memoria.pack(anchor="w", pady=(0, 4))

        # --- Tabla de productos ---
        marco = tk.Frame(self, bg=estilos.FONDO)
        marco.pack(fill="both", expand=True)
        self.tabla = ttk.Treeview(
            marco, columns=self._COLUMNAS, show="headings", style="App.Treeview")
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
        self.tabla.tag_configure("par", background="white")
        self.tabla.tag_configure("impar", background="#eef2f6")
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
            meta = self.db.obtener_meta_detalle(self.fila.id)
            if meta and meta.get("margen_ganancia") is not None:
                self._margen = float(meta["margen_ganancia"])
            self.db.recalcular_precios(
                self.fila.id, self._iva, self._margen, self._redondeo)
            self.btn_analizar.config(text="Analizar productos con IA")
            self._refrescar()
        else:
            self.lbl_estado.config(
                text="Aún sin analizar. Abre el analizador para revisar el prompt y extraer los productos con IA.")
        self._actualizar_memoria()

    def _actualizar_memoria(self) -> None:
        """Muestra si el sistema ya aprendió instrucciones para este proveedor."""
        if self.db.obtener_instrucciones(self.fila.rut_emisor):
            self.lbl_memoria.config(
                text=f"Memoria activa: hay instrucciones aprendidas para "
                     f"{self.fila.proveedor}; se aplican solas al analizar.")
        else:
            self.lbl_memoria.config(text="")

    def _refrescar(self) -> None:
        """Vuelve a leer los productos de la BD y repuebla la tabla."""
        productos = self.db.obtener_productos(self.fila.id)
        self._productos = {p.id: p for p in productos}
        self.tabla.delete(*self.tabla.get_children())
        for indice, p in enumerate(productos):
            etiqueta = "par" if indice % 2 == 0 else "impar"
            self.tabla.insert(
                "", "end", iid=str(p.id),
                values=self._valores_fila(p),
                tags=("editado",) if p.editado_manual else (etiqueta,),
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
            if meta.get("margen_ganancia") is not None:
                texto += f"  Margen {_formato_porcentaje(float(meta['margen_ganancia']))}."
        return texto

    # --- Análisis con IA ---

    def _iniciar_analisis(self) -> None:
        aprendidas = self.db.obtener_instrucciones(self.fila.rut_emisor) or ""
        meta = self.db.obtener_meta_detalle(self.fila.id)
        precios_incluyen_iva = bool(meta["precios_incluyen_iva"]) if meta else False
        configuracion = AnalizadorProductos(
            self,
            self.fila,
            aprendidas,
            self._margen,
            precios_incluyen_iva,
        ).mostrar()
        if configuracion is None:
            return
        self.db.guardar_instrucciones(
            self.fila.rut_emisor, configuracion.instrucciones_usuario)
        self._margen = configuracion.margen
        self.btn_analizar.config(state="disabled")
        self.lbl_estado.config(
            text="Analizando la factura con IA… esto puede tardar unos segundos.")
        threading.Thread(
            target=self._worker, args=(configuracion,), daemon=True).start()

    def _worker(self, configuracion: ConfiguracionAnalisisProductos) -> None:
        """Corre en un hilo aparte para no congelar la ventana durante la API."""
        try:
            from classifier import Clasificador
            from extractor import extraer_completo

            max_img = self.config["clasificacion"].get("max_imagenes_detalle", 24)
            contenido = extraer_completo(
                Path(self.fila.ruta_archivo), max_imagenes=max_img)
            clasificador = Clasificador(modelo=self.config["clasificacion"]["modelo"])
            detalle = clasificador.extraer_detalle(contenido, configuracion.prompt)
            detalle = replace(
                detalle, precios_incluyen_iva=configuracion.precios_incluyen_iva)
            self.db.guardar_detalle(
                self.fila.id, detalle, margen_ganancia=configuracion.margen)
            self.db.recalcular_precios(
                self.fila.id, self._iva, configuracion.margen, self._redondeo)
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
        self.btn_analizar.config(text="Analizar productos con IA")
        self._refrescar()
        self._actualizar_memoria()
        if dato.notas:  # type: ignore[attr-defined]
            messagebox.showinfo(
                "Observaciones de la IA", dato.notas, parent=self)  # type: ignore[attr-defined]

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

    _ANCHO = 1080
    _ALTO = 828

    def __init__(
        self, master: tk.Misc, fila: FilaFactura, db: Database, config: dict
    ) -> None:
        super().__init__(master)
        self._pantalla_completa = False
        self.title(f"Detalle de Factura · {fila.proveedor} · {fila.fecha}")
        self.minsize(936, 624)
        self.configure(bg=estilos.FONDO)
        self.style = estilos.aplicar_tema(self)
        self.style.configure("App.Treeview", rowheight=32)
        self.style.configure("App.Treeview.Heading", padding=7)
        self._centrar()

        ruta = Path(fila.ruta_archivo)
        if not ruta.exists():
            messagebox.showwarning(
                "Archivo no encontrado",
                f"El PDF ya no está en:\n{ruta}", parent=master)
            self.destroy()
            return

        estilos.cabecera(self, "Detalle de Factura", alto=72, franja=6)
        estilos.pie(self, NOMBRE, alto=50, franja=6,
                    version=f"v{__version__}")
        self._construir_barra_inferior()

        contenedor = tk.Frame(self, bg=estilos.FONDO)
        contenedor.pack(fill="both", expand=True, padx=20, pady=(14, 8))

        panel = ttk.PanedWindow(contenedor, orient="horizontal")
        panel.pack(fill="both", expand=True)

        self.visor = VisorPDF(panel, ruta)
        panel.add(self.visor, weight=1)

        self.panel_detalle = PanelDetalle(panel, fila, db, config)
        panel.add(self.panel_detalle, weight=1)

        self.protocol("WM_DELETE_WINDOW", self._cerrar)
        self.bind("<Escape>", lambda _e: self._salir_pantalla_completa())
        # Dividir la ventana 50/50 una vez que ya tiene su tamaño real
        self.after(120, lambda: panel.sashpos(0, self.winfo_width() // 2))

    def _centrar(self) -> None:
        sw = self.winfo_screenwidth()
        sh = self.winfo_screenheight()
        x = (sw - self._ANCHO) // 2
        y = (sh - self._ALTO) // 2
        self.geometry(f"{self._ANCHO}x{self._ALTO}+{x}+{y}")

    def _construir_barra_inferior(self) -> None:
        barra = tk.Frame(self, bg=estilos.FONDO, height=62)
        barra.pack(fill="x", side="bottom")
        barra.pack_propagate(False)
        centro = tk.Frame(barra, bg=estilos.FONDO)
        centro.place(relx=0.5, rely=0.5, anchor="center")
        estilos.boton(centro, "Cerrar", self._cerrar, "gris").pack(side="left", padx=10)
        self.btn_pantalla = estilos.boton(
            centro, "Modo pantalla completa",
            self._alternar_pantalla_completa, "verde")
        self.btn_pantalla.pack(side="left", padx=10)

    def _alternar_pantalla_completa(self) -> None:
        self._pantalla_completa = not self._pantalla_completa
        self.attributes("-fullscreen", self._pantalla_completa)
        self.btn_pantalla.config(
            text="Salir de pantalla completa" if self._pantalla_completa
            else "Modo pantalla completa")

    def _salir_pantalla_completa(self) -> None:
        if self._pantalla_completa:
            self._alternar_pantalla_completa()

    def _cerrar(self) -> None:
        self.visor.cerrar()
        self.destroy()


def abrir_ventana_factura(
    master: tk.Misc, fila: FilaFactura, db: Database, config: dict
) -> None:
    """Abre la ventana de detalle para una factura."""
    VentanaFactura(master, fila, db, config)
