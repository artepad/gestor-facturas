"""Ventana de búsqueda de facturas (Tkinter).

Uso: py src/buscador.py
"""

from __future__ import annotations

import os
import tkinter as tk
import unicodedata
from pathlib import Path
import queue
import threading
from tkinter import filedialog, messagebox, ttk

import yaml
from dotenv import load_dotenv
from tkcalendar import DateEntry

import estilos
from classifier import DatosFactura
from db import Database
from organizer import nombre_archivo, ruta_destino
import respaldo
from validacion import validar_datos_factura
from ventana_factura import abrir_ventana_factura
from version import NOMBRE, __version__

RAIZ = Path(__file__).resolve().parent.parent


def cargar_config() -> dict:
    with open(RAIZ / "config.yaml", "r", encoding="utf-8") as fh:
        return yaml.safe_load(fh)


def _fecha_dmy(fecha_iso: str) -> str:
    """Convierte la fecha de la BD ('2026-01-13') al formato día-mes-año
    ('13-01-2026') para mostrarla en la tabla."""
    partes = (fecha_iso or "").split("-")
    if len(partes) == 3:
        anio, mes, dia = partes
        return f"{dia}-{mes}-{anio}"
    return fecha_iso or ""


def _texto_simple(texto: str) -> str:
    """Texto en minúsculas y sin acentos para detectar advertencias."""
    normalizado = unicodedata.normalize("NFKD", texto.lower())
    return "".join(c for c in normalizado if not unicodedata.combining(c))


class TooltipTabla:
    """Tooltip simple para explicar estados dentro de la tabla."""

    def __init__(self, parent: tk.Misc) -> None:
        self.parent = parent
        self._ventana: tk.Toplevel | None = None
        self._texto: str | None = None

    def mostrar(self, texto: str, x: int, y: int) -> None:
        if self._ventana and self._texto == texto:
            self._ventana.geometry(f"+{x + 14}+{y + 18}")
            return
        self.ocultar()
        self._texto = texto
        self._ventana = tk.Toplevel(self.parent)
        self._ventana.wm_overrideredirect(True)
        self._ventana.configure(bg=estilos.HEADER_BG)
        tk.Label(
            self._ventana, text=texto, font=estilos.F_SMALL,
            bg=estilos.HEADER_BG, fg="white", padx=10, pady=6,
            justify="left", wraplength=280,
        ).pack()
        self._ventana.geometry(f"+{x + 14}+{y + 18}")

    def ocultar(self) -> None:
        if self._ventana:
            self._ventana.destroy()
            self._ventana = None
        self._texto = None


class DialogoEditarFactura(tk.Toplevel):
    """Ventana modal para corregir los datos principales de una factura."""

    _ANCHO = 720
    _ALTO = 560

    def __init__(self, master: tk.Misc, fila) -> None:
        super().__init__(master)
        self.fila = fila
        self.resultado: DatosFactura | None = None
        self.title("Editar Factura")
        self.transient(master.winfo_toplevel())
        self.resizable(False, False)
        self.configure(bg=estilos.FONDO)
        estilos.aplicar_tema(self)
        self._centrar()

        estilos.cabecera(
            self, "Editar Factura",
            "Corrige los datos principales antes de guardar",
            alto=78, franja=6)
        self._construir_barra_inferior()

        cuerpo = tk.Frame(self, bg=estilos.FONDO)
        cuerpo.pack(fill="both", expand=True, padx=28, pady=(18, 8))
        panel = estilos.panel(cuerpo, "Datos de la factura")
        panel.pack(fill="both", expand=True)
        panel.columnconfigure(1, weight=1)
        panel.columnconfigure(3, weight=1)

        self.proveedor = self._campo(panel, "Proveedor:", fila.proveedor, 0, 0)
        self.rut = self._campo(panel, "RUT:", fila.rut_emisor or "", 0, 2)
        self.razon_social = self._campo(panel, "Razón social:", fila.razon_social or "", 1, 0, colspan=3)
        self.fecha = self._campo(panel, "Fecha:", _fecha_dmy(fila.fecha), 2, 0)
        self.numero = self._campo(panel, "N° factura:", fila.numero_factura or "", 2, 2)
        total = f"{fila.total:,.0f}".replace(",", ".") if fila.total is not None else ""
        self.total = self._campo(panel, "Total:", total, 3, 0)
        self.moneda = self._campo(panel, "Moneda:", fila.moneda or "CLP", 3, 2)

        tk.Label(
            panel, text="Notas:", font=estilos.F_BODY,
            bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
        ).grid(row=4, column=0, sticky="nw", padx=(0, 8), pady=(14, 4))
        self.notas = tk.Text(
            panel, height=5, wrap="word", font=estilos.F_BODY,
            bg="white", fg=estilos.TEXTO, relief="flat",
            highlightthickness=2, highlightbackground=estilos.BORDE,
            highlightcolor=estilos.ENTRY_BORDE_ACTIVO,
            insertbackground=estilos.TEXTO)
        self.notas.grid(row=4, column=1, columnspan=3, sticky="nsew", pady=(12, 4))
        if fila.notas:
            self.notas.insert("1.0", fila.notas)

        tk.Label(
            panel, text="Formato de fecha: DD-MM-YYYY. El total acepta formato chileno, por ejemplo 221.713.",
            font=estilos.F_HINT, bg=estilos.FONDO, fg=estilos.TEXTO_TENUE,
        ).grid(row=5, column=0, columnspan=4, sticky="w", pady=(10, 0))

        self.protocol("WM_DELETE_WINDOW", self._cancelar)
        self.proveedor.focus_set()
        self.grab_set()

    def _centrar(self) -> None:
        padre = self.master.winfo_toplevel()
        padre.update_idletasks()
        x = padre.winfo_rootx() + (padre.winfo_width() - self._ANCHO) // 2
        y = padre.winfo_rooty() + (padre.winfo_height() - self._ALTO) // 2
        x = max(0, min(x, self.winfo_screenwidth() - self._ANCHO))
        y = max(0, min(y, self.winfo_screenheight() - self._ALTO))
        self.geometry(f"{self._ANCHO}x{self._ALTO}+{x}+{y}")

    def _campo(
        self,
        parent: tk.Misc,
        etiqueta: str,
        valor: str,
        fila: int,
        columna: int,
        *,
        colspan: int = 1,
    ) -> tk.Entry:
        tk.Label(
            parent, text=etiqueta, font=estilos.F_BODY,
            bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
        ).grid(row=fila, column=columna, sticky="w", padx=(0, 8), pady=7)
        entrada = estilos.entrada(parent)
        entrada.insert(0, valor)
        entrada.grid(
            row=fila, column=columna + 1, columnspan=colspan,
            sticky="ew", padx=(0, 18), pady=7)
        return entrada

    def _construir_barra_inferior(self) -> None:
        botones = tk.Frame(self, bg=estilos.FONDO, height=82)
        botones.pack(fill="x", side="bottom")
        botones.pack_propagate(False)
        tk.Frame(botones, bg=estilos.BORDE, height=2).pack(fill="x", pady=(0, 12))
        centro = tk.Frame(botones, bg=estilos.FONDO)
        centro.pack(anchor="center")
        estilos.boton(centro, "Cancelar", self._cancelar, "gris").pack(side="left", padx=12)
        estilos.boton(centro, "Guardar cambios", self._aceptar, "verde").pack(side="left", padx=12)

    def _aceptar(self) -> None:
        datos = DatosFactura(
            proveedor=self.proveedor.get().strip(),
            fecha=self.fecha.get().strip(),
            confianza=self.fila.confianza or 1.0,
            razon_social=self.razon_social.get().strip() or None,
            rut_emisor=self.rut.get().strip() or None,
            numero_factura=self.numero.get().strip() or None,
            total=self.total.get().strip() or None,
            moneda=self.moneda.get().strip() or "CLP",
            notas=self.notas.get("1.0", "end").strip() or None,
        )
        if not datos.proveedor:
            messagebox.showwarning("Dato obligatorio", "Ingresa el proveedor.", parent=self)
            return
        validacion = validar_datos_factura(datos)
        if not validacion.ok:
            messagebox.showwarning(
                "Datos inválidos", "\n".join(validacion.errores), parent=self)
            return
        self.resultado = validacion.datos
        self.destroy()

    def _cancelar(self) -> None:
        self.resultado = None
        self.destroy()

    def mostrar(self) -> DatosFactura | None:
        self.wait_window()
        return self.resultado


class Buscador:
    _TITULO = "Administrador de Facturas"
    _ANCHO = 1080
    _ALTO = 828
    _POLL_MS = 4000  # cada cuánto se chequea si hay facturas nuevas en la BD
    # Íconos de la columna Acciones (por ahora solo visuales)
    _ICONO_VER = "🔍"
    _ICONO_EDITAR = "📝"
    _ICONO_ELIMINAR = "❌"
    # Tooltips que aparecen al pasar el mouse sobre cada ícono
    _TOOLTIPS_ACCION = (
        "Ver: abre el PDF de la factura en el visor del sistema.",
        "Editar: corrige los datos principales de la factura.",
        "Eliminar: borra el archivo PDF y el registro de la base de datos.",
    )

    def __init__(self, db: Database, config: dict) -> None:
        self.db = db
        self.config = config
        self.filas: list = []
        self._puntos_estado: list[tk.Label] = []
        self._pantalla_completa = False
        self.ventana = tk.Tk()
        self.tooltip = TooltipTabla(self.ventana)
        self.ventana.title(self._TITULO)
        self.ventana.minsize(936, 624)
        self.style = estilos.aplicar_tema(self.ventana)
        self.style.configure("App.Treeview", rowheight=36)
        self.style.configure("App.Treeview.Heading", padding=8)
        self._centrar()
        self._construir_ui()
        self._refrescar_proveedores()
        self.buscar()
        self.ventana.bind("<Escape>", lambda _e: self._salir_pantalla_completa())
        self.entrada_texto.focus_set()  # foco listo en el campo de búsqueda
        # Refresco automático: el watcher (otro proceso) puede insertar facturas
        # nuevas en la BD. Chequeamos cada cierto tiempo si hay novedades.
        self._ultimo_max_id = self.db.max_id_factura()
        self._programar_chequeo()

    def _centrar(self) -> None:
        sw = self.ventana.winfo_screenwidth()
        sh = self.ventana.winfo_screenheight()
        x = (sw - self._ANCHO) // 2
        y = (sh - self._ALTO) // 2
        self.ventana.geometry(f"{self._ANCHO}x{self._ALTO}+{x}+{y}")

    # --- Construcción de la interfaz ---

    def _construir_ui(self) -> None:
        estilos.cabecera(
            self.ventana, self._TITULO,
            alto=84, franja=6, fuente_titulo=("Segoe UI", 24, "bold"))
        estilos.pie(self.ventana, NOMBRE, alto=46, franja=6,
                    version=f"v{__version__}")
        self._construir_barra_inferior()

        cont = tk.Frame(self.ventana, bg=estilos.FONDO)
        cont.pack(fill="both", expand=True, padx=29, pady=(14, 4))
        self._construir_filtros(cont)
        self._construir_tabla(cont)

    def _construir_filtros(self, parent: tk.Misc) -> None:
        filtros = estilos.panel(parent, "Filtros")
        filtros.pack(fill="x")
        # Padding interno uniforme para que respire
        # pady aplica arriba y abajo por igual; el margen inferior extra se
        # compensa con un espaciador al final del bloque.
        filtros.configure(padx=18, pady=10)

        # Todo en una sola fila compacta: Búsqueda | Proveedor | Buscar | Limpiar
        fila = tk.Frame(filtros, bg=estilos.FONDO)
        fila.pack(fill="x")

        # Ambos campos del mismo ancho fijo, etiqueta arriba
        ANCHO_CAMPO = 28  # caracteres, mismo para Entry y Combobox

        def _celda(etiqueta: str) -> tk.Frame:
            celda = tk.Frame(fila, bg=estilos.FONDO)
            tk.Label(celda, text=etiqueta, font=estilos.F_BODY_BOLD,
                     bg=estilos.FONDO, fg=estilos.TEXTO_SEC).pack(
                anchor="w", pady=(0, 5))
            return celda

        # Búsqueda libre
        celda_busqueda = _celda("Búsqueda libre")
        celda_busqueda.pack(side="left")
        # Búsqueda libre claramente más ancha que el resto
        self.entrada_texto = estilos.entrada(celda_busqueda, width=60)
        self.entrada_texto.pack(ipady=4)
        self.entrada_texto.bind("<Return>", lambda _e: self.buscar())

        # Proveedor
        celda_prov = _celda("Proveedor")
        celda_prov.pack(side="left", padx=(14, 0))
        self.combo_proveedor = ttk.Combobox(celda_prov, state="readonly",
                                            font=estilos.F_BODY,
                                            width=ANCHO_CAMPO - 2)
        self.combo_proveedor.pack(ipady=3)
        self.combo_proveedor.bind("<<ComboboxSelected>>",
                                  lambda _e: self.buscar())

        # Botones, alineados con el input (no con la etiqueta)
        self.fila_botones = tk.Frame(fila, bg=estilos.FONDO)
        self.fila_botones.pack(side="left", padx=(20, 0), pady=(22, 0))
        estilos.boton(self.fila_botones, "Buscar", self.buscar,
                      "azul").pack(side="left")
        estilos.boton(self.fila_botones, "Limpiar filtros", self.limpiar,
                      "gris").pack(side="left", padx=(10, 0))

        # --- Filtro por fecha: oculto por ahora, pero la lógica sigue activa ---
        # Para reactivarlo: empaquetar un checkbox que llame _alternar_fechas y
        # `self.marco_fechas` en algún lugar de la barra de filtros.
        self.usar_fecha = tk.BooleanVar(value=False)
        self.marco_fechas = tk.Frame(filtros, bg=estilos.FONDO)
        self.fecha_desde = self._crear_calendario(self.marco_fechas)
        self.fecha_desde.pack(side="left", fill="x", expand=True)
        tk.Label(self.marco_fechas, text="—", font=estilos.F_BODY,
                 bg=estilos.FONDO, fg=estilos.TEXTO_TENUE).pack(
            side="left", padx=8)
        self.fecha_hasta = self._crear_calendario(self.marco_fechas)
        self.fecha_hasta.pack(side="left", fill="x", expand=True)
        # marco_fechas NO se empaqueta: queda invisible hasta que se reactive.

        # Espaciador inferior: aumenta ~10% el margen entre la fila de filtros
        # y el borde inferior del contenedor "Filtros".
        tk.Frame(filtros, bg=estilos.FONDO, height=8).pack(fill="x")

    def _crear_calendario(self, parent: tk.Misc) -> DateEntry:
        """Campo de fecha con calendario visual desplegable."""
        cal = DateEntry(
            parent, width=13, date_pattern="yyyy-mm-dd", locale="es_CL",
            font=estilos.F_BODY, justify="center", borderwidth=2,
            background=estilos.HEADER_BG, foreground="white",
            headersbackground=estilos.HEADER_BG, headersforeground="white",
            selectbackground=estilos.ACENTO_AZUL, selectforeground="white",
            normalbackground="white", normalforeground=estilos.TEXTO,
            weekendbackground="white", weekendforeground=estilos.TEXTO,
            othermonthbackground="#eef2f6", othermonthforeground="#9aa4ad")
        cal.bind("<<DateEntrySelected>>", lambda _e: self.buscar())
        return cal

    def _construir_tabla(self, parent: tk.Misc) -> None:
        marco = tk.Frame(parent, bg=estilos.FONDO)
        marco.pack(fill="both", expand=True, pady=(12, 0))

        columnas = ("fecha", "proveedor", "numero", "total", "razon_social",
                    "estado", "acciones")
        self.tabla = ttk.Treeview(marco, columns=columnas, show="headings",
                                  style="App.Treeview")
        encabezados = {
            "fecha": ("Fecha", 110),
            "proveedor": ("Proveedor", 154),
            "numero": ("N° Factura", 118),
            "total": ("Total", 118),
            "razon_social": ("Razón Social", 252),
            "estado": ("Estado", 105),
            "acciones": ("Acciones", 144),
        }
        for col, (titulo, ancho) in encabezados.items():
            self.tabla.heading(col, text=titulo, anchor="center")
            self.tabla.column(col, width=ancho, anchor="w", stretch=False)
        self.tabla.column("razon_social", stretch=True)
        self.tabla.column("estado", anchor="center")
        self.tabla.column("acciones", anchor="center")

        # Filas alternadas (efecto cebra) para mejorar la lectura
        self.tabla.tag_configure("par", background="white")
        self.tabla.tag_configure("impar", background="#eef2f6")

        scroll = ttk.Scrollbar(marco, orient="vertical")

        def desplazar(*args) -> None:
            self.tabla.yview(*args)
            self._programar_puntos_estado()

        def actualizar_scroll(*args) -> None:
            scroll.set(*args)
            self._programar_puntos_estado()

        scroll.configure(command=desplazar)
        self.tabla.configure(yscrollcommand=actualizar_scroll)
        self.tabla.pack(side="left", fill="both", expand=True)
        scroll.pack(side="right", fill="y")
        self.tabla.bind("<ButtonRelease-1>", self._clic_acciones)
        self.tabla.bind("<Motion>", self._mover_sobre_tabla)
        self.tabla.bind("<Leave>", lambda _e: self.tooltip.ocultar())
        self.tabla.bind("<Configure>", lambda _e: self._programar_puntos_estado())
        self.tabla.bind("<MouseWheel>", lambda _e: self._programar_puntos_estado())
        self.tabla.bind("<<TreeviewSelect>>", lambda _e: self._programar_puntos_estado())
        self.tabla.bind("<Double-1>", self._abrir_seleccionado)

        # Línea inferior: "N resultado(s)" y la pista de doble clic en la misma fila
        fila_estado = tk.Frame(parent, bg=estilos.FONDO)
        fila_estado.pack(fill="x", pady=(6, 0))
        self.etiqueta_estado = tk.Label(
            fila_estado, text="", font=estilos.F_SMALL,
            bg=estilos.FONDO, fg=estilos.TEXTO_SEC)
        self.etiqueta_estado.pack(side="left")
        tk.Label(fila_estado,
                 text="  ·  Doble clic en una fila para abrir el detalle de la factura.",
                 font=estilos.F_HINT, bg=estilos.FONDO,
                 fg=estilos.TEXTO_TENUE).pack(side="left")

    def _construir_barra_inferior(self) -> None:
        barra = tk.Frame(self.ventana, bg=estilos.FONDO, height=58)
        barra.pack(fill="x", side="bottom")
        barra.pack_propagate(False)
        centro = tk.Frame(barra, bg=estilos.FONDO)
        centro.place(relx=0.5, rely=0.5, anchor="center")
        estilos.boton(centro, "Cerrar", self._cerrar, "gris").pack(side="left", padx=10)
        estilos.boton(centro, "Respaldo ▾", self._abrir_menu_respaldo,
                      "azul").pack(side="left", padx=10)
        self.btn_pantalla = estilos.boton(
            centro, "Modo pantalla completa",
            self._alternar_pantalla_completa, "verde")
        self.btn_pantalla.pack(side="left", padx=10)

    def _abrir_menu_respaldo(self) -> None:
        """Muestra un pequeño menú con las dos acciones de respaldo."""
        menu = tk.Menu(self.ventana, tearoff=0, font=estilos.F_BODY)
        menu.add_command(label="Exportar respaldo…", command=self._exportar_respaldo)
        menu.add_command(label="Importar respaldo…", command=self._importar_respaldo)
        # Posiciona el menú justo bajo el cursor
        try:
            x = self.ventana.winfo_pointerx()
            y = self.ventana.winfo_pointery()
            menu.tk_popup(x, y)
        finally:
            menu.grab_release()

    def _exportar_respaldo(self) -> None:
        DialogoExportarRespaldo(self.ventana, self.config).mostrar()

    def _importar_respaldo(self) -> None:
        if not messagebox.askyesno(
                "Importar respaldo",
                "Importar reemplazará la base de datos y los PDFs actuales con "
                "los del respaldo.\n\n"
                "Antes de continuar, IMPORTANTE:\n"
                "• Pausa la vigilancia desde el ícono de la bandeja.\n"
                "• Asegúrate de haber exportado un respaldo de seguridad.\n\n"
                "¿Deseas continuar?",
                parent=self.ventana, icon="warning"):
            return
        DialogoImportarRespaldo(self.ventana, self.config).mostrar()

    # --- Acciones ---

    def _alternar_fechas(self) -> None:
        if self.usar_fecha.get():
            self.marco_fechas.pack(fill="x")
        else:
            self.marco_fechas.pack_forget()
        self.buscar()

    def _alternar_pantalla_completa(self) -> None:
        self._pantalla_completa = not self._pantalla_completa
        self.ventana.attributes("-fullscreen", self._pantalla_completa)
        self.btn_pantalla.config(
            text="Salir de pantalla completa" if self._pantalla_completa
            else "Modo pantalla completa")

    def _salir_pantalla_completa(self) -> None:
        if self._pantalla_completa:
            self._alternar_pantalla_completa()

    def _cerrar(self) -> None:
        self.ventana.destroy()

    def _refrescar_proveedores(self) -> None:
        valores = [""] + self.db.listar_proveedores()
        self.combo_proveedor["values"] = valores
        if not self.combo_proveedor.get():
            self.combo_proveedor.set("")

    def buscar(self) -> None:
        texto = self.entrada_texto.get().strip() or None
        proveedor = self.combo_proveedor.get().strip() or None
        desde = hasta = None
        if self.usar_fecha.get():
            desde = self.fecha_desde.get_date().isoformat()
            hasta = self.fecha_hasta.get_date().isoformat()

        try:
            self.filas = self.db.buscar(
                texto=texto, proveedor=proveedor,
                fecha_inicio=desde, fecha_fin=hasta,
            )
        except Exception as exc:
            messagebox.showerror("Error en la búsqueda", str(exc))
            return

        for iid in self.tabla.get_children():
            self.tabla.delete(iid)

        for indice, fila in enumerate(self.filas):
            total = f"${fila.total:,.0f}".replace(",", ".") if fila.total is not None else ""
            estado, _tooltip, _color = self._estado_factura(fila)
            etiqueta = "par" if indice % 2 == 0 else "impar"
            iconos = f"{self._ICONO_VER}    {self._ICONO_EDITAR}    {self._ICONO_ELIMINAR}"
            self.tabla.insert("", "end", iid=str(fila.id), tags=(etiqueta,), values=(
                _fecha_dmy(fila.fecha), fila.proveedor, fila.numero_factura or "",
                total, fila.razon_social or "", estado, iconos,
            ))

        self.etiqueta_estado.config(text=f"{len(self.filas)} resultado(s)")
        self._refrescar_proveedores()
        self._programar_puntos_estado()

    def limpiar(self) -> None:
        self.entrada_texto.delete(0, "end")
        self.combo_proveedor.set("")
        self.usar_fecha.set(False)
        self._alternar_fechas()  # oculta el marco de fechas y vuelve a buscar

    def _abrir_seleccionado(self, _evento) -> None:
        seleccion = self.tabla.selection()
        if not seleccion:
            return
        indice = self.tabla.index(seleccion[0])
        abrir_ventana_factura(self.ventana, self.filas[indice], self.db, self.config)

    def _estado_factura(self, fila) -> tuple[str, str, str]:
        """Devuelve etiqueta visual y explicación del estado de una factura."""
        umbrales = self.config.get("clasificacion", {})
        umbral_revision = float(umbrales.get("umbral_confianza", 0.70))
        umbral_error = float(umbrales.get("umbral_escaneo_defectuoso", 0.40))
        confianza = fila.confianza
        tiene_advertencias = self._nota_requiere_revision(fila.notas)

        if confianza is not None and confianza < umbral_error:
            return (
                "   Error",
                "Rojo: hay un problema detectado. La confianza de lectura es muy baja "
                "y conviene revisar la factura antes de usar sus datos.",
                "#dc3545",
            )
        if confianza is None:
            return (
                "   Revisar",
                "Amarillo: requiere revisión. Esta factura no tiene confianza de "
                "lectura registrada.",
                "#f1c40f",
            )
        if confianza < umbral_revision or tiene_advertencias:
            return (
                "   Revisar",
                "Amarillo: requiere revisión o existe una advertencia registrada. "
                f"Confianza de lectura: {confianza:.2f}.",
                "#f1c40f",
            )
        return (
            "   Correcto",
            "Verde: todo correcto. La factura fue leída con buena confianza "
            f"({confianza:.2f}) y no tiene advertencias registradas.",
            "#27ae60",
        )

    def _programar_puntos_estado(self) -> None:
        self.ventana.after_idle(self._dibujar_puntos_estado)

    def _dibujar_puntos_estado(self) -> None:
        for punto in self._puntos_estado:
            punto.destroy()
        self._puntos_estado.clear()

        seleccion = set(self.tabla.selection())
        # Alto del área visible de filas (sin contar el encabezado)
        try:
            alto_visible = self.tabla.winfo_height()
        except tk.TclError:
            alto_visible = 0
        for item_id in self.tabla.get_children():
            bbox = self.tabla.bbox(item_id, "estado")
            if not bbox:
                continue
            indice = self.tabla.index(item_id)
            if not 0 <= indice < len(self.filas):
                continue
            x, y, _ancho, alto = bbox
            # Evita dibujar el punto sobre filas parcialmente recortadas en el
            # borde inferior de la tabla (causaba un "Estado" colgando fuera).
            if alto < 20 or (alto_visible and y + alto > alto_visible):
                continue
            _estado, tooltip, color = self._estado_factura(self.filas[indice])
            fondo = estilos.ACENTO_AZUL if item_id in seleccion else (
                "white" if indice % 2 == 0 else "#eef2f6")
            punto = tk.Label(
                self.tabla, text="●", font=("Segoe UI", 13, "bold"),
                fg=color, bg=fondo, bd=0)
            punto.place(x=x + 8, y=y + max((alto - 20) // 2, 0), width=18, height=20)
            punto.bind(
                "<Motion>",
                lambda e, texto=tooltip: self.tooltip.mostrar(texto, e.x_root, e.y_root))
            punto.bind("<Leave>", lambda _e: self.tooltip.ocultar())
            self._puntos_estado.append(punto)

    @staticmethod
    def _nota_requiere_revision(notas: str | None) -> bool:
        """Distingue notas descriptivas normales de advertencias reales."""
        if not notas:
            return False
        texto = _texto_simple(notas)
        indicadores = (
            "validacion local",
            "revisar",
            "advertencia",
            "sospechos",
            "ilegible",
            "invalida",
            "invalido",
            "error",
            "problema",
            "no se pudo",
            "confianza baja",
            "baja confianza",
            "futura",
            "futuro",
            "fecha de emision futura",
            "esta en el futuro",
            "futuro respecto",
            "monto clp con decimales",
            "monto clp sospechosamente bajo",
        )
        return any(indicador in texto for indicador in indicadores)

    def _mover_sobre_tabla(self, evento: tk.Event) -> None:
        columna = self.tabla.identify_column(evento.x)
        if columna == "#6":  # Estado
            self._tooltip_estado(evento)
            return
        if columna == "#7":  # Acciones
            self._tooltip_acciones(evento)
            return
        self.tooltip.ocultar()

    def _tooltip_estado(self, evento: tk.Event) -> None:
        fila_id = self.tabla.identify_row(evento.y)
        if not fila_id:
            self.tooltip.ocultar()
            return
        indice = self.tabla.index(fila_id)
        if not 0 <= indice < len(self.filas):
            self.tooltip.ocultar()
            return
        _estado, texto, _color = self._estado_factura(self.filas[indice])
        self.tooltip.mostrar(texto, evento.x_root, evento.y_root)

    def _tooltip_acciones(self, evento: tk.Event) -> None:
        fila_id = self.tabla.identify_row(evento.y)
        if not fila_id:
            self.tooltip.ocultar()
            return
        bbox = self.tabla.bbox(fila_id, "acciones")
        if not bbox:
            self.tooltip.ocultar()
            return
        x, _y, ancho, _alto = bbox
        # Misma división en 3 zonas que _clic_acciones
        zona = int((evento.x - x) / max(ancho / 3, 1))
        zona = max(0, min(zona, len(self._TOOLTIPS_ACCION) - 1))
        self.tooltip.mostrar(
            self._TOOLTIPS_ACCION[zona], evento.x_root, evento.y_root)

    def _clic_acciones(self, evento: tk.Event) -> None:
        """Ejecuta acciones de la columna Acciones."""
        if self.tabla.identify_column(evento.x) != "#7":
            return
        fila_id = self.tabla.identify_row(evento.y)
        if not fila_id:
            return
        bbox = self.tabla.bbox(fila_id, "acciones")
        if not bbox:
            return
        x, _y, ancho, _alto = bbox
        zona = int((evento.x - x) / max(ancho / 3, 1))
        indice = self.tabla.index(fila_id)
        if zona == 0:
            self._abrir_pdf_predeterminado(self.filas[indice])
        elif zona == 1:
            self._editar_factura(self.filas[indice])
        elif zona == 2:
            self._eliminar_factura(self.filas[indice])
        self._programar_puntos_estado()

    def _abrir_pdf_predeterminado(self, fila) -> None:
        ruta = Path(fila.ruta_archivo)
        if not ruta.exists():
            messagebox.showwarning(
                "Archivo no encontrado",
                f"No se encontró el PDF de esta factura:\n\n{ruta}",
                parent=self.ventana,
            )
            return
        try:
            os.startfile(str(ruta))  # type: ignore[attr-defined]
        except OSError as exc:
            messagebox.showerror(
                "No se pudo abrir",
                f"No se pudo abrir la factura con el lector predeterminado:\n\n{exc}",
                parent=self.ventana,
            )

    def _editar_factura(self, fila) -> None:
        datos = DialogoEditarFactura(self.ventana, fila).mostrar()
        if datos is None:
            return
        duplicado = self.db.buscar_duplicado(
            datos.numero_factura, datos.rut_emisor, excluir_id=fila.id)
        if duplicado:
            messagebox.showwarning(
                "Factura duplicada",
                "Ya existe otra factura con el mismo número y RUT.",
                parent=self.ventana,
            )
            return

        nueva_ruta = self._mover_pdf_si_corresponde(fila, datos)
        try:
            self.db.actualizar_factura(fila.id, datos, nueva_ruta)
        except Exception as exc:
            messagebox.showerror(
                "No se pudo guardar",
                f"No se pudieron guardar los cambios:\n\n{exc}",
                parent=self.ventana,
            )
            return
        self.buscar()

    def _mover_pdf_si_corresponde(self, fila, datos: DatosFactura) -> Path | None:
        ruta_actual = Path(fila.ruta_archivo)
        if not ruta_actual.exists():
            return None
        raiz_archivo = Path(self.config["rutas"]["archivo"])
        carpeta_anterior = ruta_actual.parent
        destino_dir = ruta_destino(raiz_archivo, datos)
        destino = destino_dir / nombre_archivo(datos, ruta_actual.suffix.lower())
        if ruta_actual.resolve() == destino.resolve():
            return None
        destino_dir.mkdir(parents=True, exist_ok=True)
        contador = 2
        stem_base = destino.stem
        while destino.exists():
            destino = destino_dir / f"{stem_base}_{contador}{ruta_actual.suffix.lower()}"
            contador += 1
        try:
            ruta_actual.replace(destino)
        except OSError as exc:
            messagebox.showwarning(
                "Archivo no movido",
                "Los datos se guardarán, pero no se pudo mover el PDF a la "
                f"nueva carpeta:\n\n{exc}",
                parent=self.ventana,
            )
            return None
        self._limpiar_carpetas_vacias(carpeta_anterior, raiz_archivo)
        return destino

    def _limpiar_carpetas_vacias(self, inicio: Path, raiz_archivo: Path) -> None:
        """Borra carpetas vacías creadas por movimientos, sin salir de la raíz de facturas."""
        especiales = {"_entrada", "_revisar", "_errores", "_reemplazadas"}
        try:
            actual = inicio.resolve()
            raiz = raiz_archivo.resolve()
        except OSError:
            return

        while actual != raiz and raiz in actual.parents:
            if actual.name in especiales:
                return
            try:
                actual.rmdir()
            except OSError:
                return
            actual = actual.parent

    def _eliminar_factura(self, fila) -> None:
        confirmar = messagebox.askyesno(
            "Eliminar factura",
            "¿Seguro que deseas eliminar esta factura?\n\n"
            "Se borrará el registro del sistema y también el archivo PDF.",
            parent=self.ventana,
        )
        if not confirmar:
            return

        ruta = Path(fila.ruta_archivo)
        carpeta_anterior = ruta.parent
        if ruta.exists():
            try:
                ruta.unlink()
            except OSError as exc:
                messagebox.showerror(
                    "No se pudo eliminar",
                    f"No se pudo borrar el PDF de la factura:\n\n{exc}",
                    parent=self.ventana,
                )
                return
            self._limpiar_carpetas_vacias(
                carpeta_anterior, Path(self.config["rutas"]["archivo"]))
        try:
            self.db.eliminar(fila.id)
        except Exception as exc:
            messagebox.showerror(
                "No se pudo eliminar",
                f"No se pudo borrar el registro de la factura:\n\n{exc}",
                parent=self.ventana,
            )
            return
        self.buscar()

    # --- Auto-refresco cuando llegan facturas nuevas ---

    def _programar_chequeo(self) -> None:
        try:
            self.ventana.after(self._POLL_MS, self._chequear_nuevos)
        except tk.TclError:
            pass  # la ventana se cerró

    def _chequear_nuevos(self) -> None:
        """Si el watcher procesó una factura nueva, refresca la tabla."""
        try:
            max_id = self.db.max_id_factura()
            if max_id != self._ultimo_max_id:
                self._ultimo_max_id = max_id
                seleccionado = self._id_seleccionado()
                self.buscar()
                if seleccionado and self.tabla.exists(seleccionado):
                    self.tabla.selection_set(seleccionado)
                    self.tabla.see(seleccionado)
        except Exception as exc:  # noqa: BLE001 — no romper la UI
            print(f"[buscador] error chequeando facturas nuevas: {exc}", flush=True)
        finally:
            self._programar_chequeo()

    def _id_seleccionado(self) -> str | None:
        seleccion = self.tabla.selection()
        return seleccion[0] if seleccion else None

    def ejecutar(self) -> None:
        self.ventana.mainloop()


class _DialogoRespaldoBase(tk.Toplevel):
    """Base común para los diálogos de exportar e importar.

    Ejecuta la operación pesada en un thread para no congelar la UI, y
    transfiere actualizaciones de progreso por una cola consumida desde el
    hilo de Tk vía `after()`.
    """

    def __init__(self, padre: tk.Misc, titulo: str, ancho: int = 540,
                 alto: int = 480) -> None:
        super().__init__(padre)
        self.title(titulo)
        self.transient(padre)
        self.configure(bg=estilos.FONDO)
        self.resizable(False, False)
        self.geometry(f"{ancho}x{alto}")
        self._cola: queue.Queue = queue.Queue()
        self._thread: threading.Thread | None = None
        self._cerrar_permitido = True
        self.protocol("WM_DELETE_WINDOW", self._intentar_cerrar)

    def _intentar_cerrar(self) -> None:
        if self._cerrar_permitido:
            self.destroy()
        else:
            messagebox.showinfo(
                "Operación en curso",
                "Espera a que termine para cerrar esta ventana.",
                parent=self)

    def _bloquear_cerrar(self, bloquear: bool) -> None:
        self._cerrar_permitido = not bloquear

    def _lanzar_en_thread(self, fn) -> None:
        def correr():
            try:
                fn()
            except Exception as exc:  # noqa: BLE001
                self._cola.put(("error", str(exc)))
            else:
                self._cola.put(("ok", None))
        self._thread = threading.Thread(target=correr, daemon=True)
        self._thread.start()
        self.after(120, self._consumir_cola)

    def _consumir_cola(self) -> None:
        try:
            while True:
                tipo, payload = self._cola.get_nowait()
                self._manejar_evento(tipo, payload)
        except queue.Empty:
            pass
        if self._thread is not None and self._thread.is_alive():
            self.after(120, self._consumir_cola)

    def _manejar_evento(self, tipo: str, payload) -> None:
        """Sobreescribir en cada subclase."""
        raise NotImplementedError

    def mostrar(self) -> None:
        self.wait_window()


class DialogoExportarRespaldo(_DialogoRespaldoBase):
    """Modal para generar un respaldo .zip."""

    def __init__(self, padre: tk.Misc, config: dict) -> None:
        super().__init__(padre, "Exportar respaldo")
        self.config_app = config
        self._carpeta_destino = tk.StringVar(value=str(Path.home() / "Desktop"))
        self._nombre_negocio = tk.StringVar(value="")
        self._incluir_api_key = tk.BooleanVar(value=False)
        self._construir_ui()

    def _construir_ui(self) -> None:
        cont = tk.Frame(self, bg=estilos.FONDO, padx=22, pady=18)
        cont.pack(fill="both", expand=True)

        tk.Label(cont, text="Exportar respaldo", font=estilos.F_H3,
                 bg=estilos.FONDO, fg=estilos.TEXTO).pack(anchor="w")
        tk.Label(cont,
                 text="Genera un archivo .zip con la base de datos, los PDFs "
                      "y la configuración.",
                 font=estilos.F_SMALL, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC, wraplength=480, justify="left"
                 ).pack(anchor="w", pady=(2, 14))

        # Nombre del negocio
        tk.Label(cont, text="Nombre del negocio (etiqueta del respaldo):",
                 font=estilos.F_BODY_BOLD, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC).pack(anchor="w")
        estilos.entrada(cont, textvariable=self._nombre_negocio, width=50
                        ).pack(anchor="w", pady=(4, 12), ipady=3)

        # Carpeta destino
        tk.Label(cont, text="Carpeta donde guardar:",
                 font=estilos.F_BODY_BOLD, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC).pack(anchor="w")
        fila_carpeta = tk.Frame(cont, bg=estilos.FONDO)
        fila_carpeta.pack(fill="x", pady=(4, 12))
        estilos.entrada(fila_carpeta, textvariable=self._carpeta_destino
                        ).pack(side="left", fill="x", expand=True, ipady=3)
        estilos.boton(fila_carpeta, "Elegir…", self._elegir_carpeta, "gris",
                      grande=False).pack(side="left", padx=(8, 0))

        # API key
        tk.Checkbutton(
            cont, text="Incluir API key (.env) — solo si el respaldo es para ti",
            variable=self._incluir_api_key, font=estilos.F_SMALL,
            bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
            activebackground=estilos.FONDO, selectcolor="white",
            cursor="hand2").pack(anchor="w", pady=(0, 14))

        # Progreso
        self._estado = tk.Label(cont, text="", font=estilos.F_SMALL,
                                bg=estilos.FONDO, fg=estilos.TEXTO_SEC)
        self._estado.pack(anchor="w")
        self._barra = ttk.Progressbar(cont, mode="determinate", length=480)
        self._barra.pack(fill="x", pady=(4, 14))

        # Botones
        botones = tk.Frame(cont, bg=estilos.FONDO)
        botones.pack(fill="x")
        self._btn_cerrar = estilos.boton(botones, "Cerrar", self.destroy, "gris")
        self._btn_cerrar.pack(side="right", padx=(10, 0))
        self._btn_exportar = estilos.boton(botones, "Exportar",
                                           self._iniciar_exportar, "azul")
        self._btn_exportar.pack(side="right")

    def _elegir_carpeta(self) -> None:
        carpeta = filedialog.askdirectory(
            parent=self, title="Carpeta de destino del respaldo",
            initialdir=self._carpeta_destino.get())
        if carpeta:
            self._carpeta_destino.set(carpeta)

    def _iniciar_exportar(self) -> None:
        destino = Path(self._carpeta_destino.get().strip())
        if not destino:
            messagebox.showwarning("Carpeta requerida",
                                   "Elige una carpeta de destino.", parent=self)
            return
        self._btn_exportar.configure(state="disabled")
        self._bloquear_cerrar(True)
        self._barra.configure(mode="indeterminate")
        self._barra.start(80)
        self._estado.config(text="Iniciando…")

        def progreso(p: respaldo.ProgresoRespaldo) -> None:
            self._cola.put(("progreso", p))

        def trabajo():
            # Crea un marcador que el watcher (otro proceso) respeta para no
            # tocar la BD ni los PDFs mientras se genera el respaldo.
            with respaldo.bloquear_procesamiento(self.config_app):
                self._resultado = respaldo.exportar(
                    self.config_app, destino,
                    incluir_api_key=self._incluir_api_key.get(),
                    nombre_negocio=self._nombre_negocio.get().strip(),
                    ruta_config_yaml=RAIZ / "config.yaml",
                    ruta_env=RAIZ / ".env",
                    progreso=progreso)

        self._lanzar_en_thread(trabajo)

    def _manejar_evento(self, tipo: str, payload) -> None:
        if tipo == "progreso":
            p: respaldo.ProgresoRespaldo = payload
            self._estado.config(text=p.paso or "Trabajando…")
            if p.total:
                self._barra.stop()
                self._barra.configure(mode="determinate", maximum=p.total,
                                      value=p.actual)
        elif tipo == "ok":
            self._barra.stop()
            self._barra.configure(mode="determinate",
                                  maximum=100, value=100)
            self._estado.config(text="Respaldo creado correctamente.",
                                fg=estilos.VERDE_OK)
            self._bloquear_cerrar(False)
            self._btn_cerrar.configure(text="Cerrar")
            r = self._resultado
            mb = r.tamano_bytes / (1024 * 1024)
            messagebox.showinfo(
                "Respaldo creado",
                f"Archivo: {r.ruta_zip.name}\n"
                f"Carpeta: {r.ruta_zip.parent}\n"
                f"Tamaño: {mb:.1f} MB\n"
                f"Facturas: {r.conteos.get('facturas', '?')}\n"
                f"PDFs: {r.conteos.get('pdfs', '?')}",
                parent=self)
        elif tipo == "error":
            self._barra.stop()
            self._estado.config(text=f"Error: {payload}", fg="#dc3545")
            self._bloquear_cerrar(False)
            self._btn_exportar.configure(state="normal")
            messagebox.showerror("Error al exportar",
                                 str(payload), parent=self)


class DialogoImportarRespaldo(_DialogoRespaldoBase):
    """Modal para restaurar un respaldo .zip."""

    def __init__(self, padre: tk.Misc, config: dict) -> None:
        super().__init__(padre, "Importar respaldo", alto=560)
        self.config_app = config
        self._ruta_zip = tk.StringVar(value="")
        self._info = tk.StringVar(value="Selecciona un archivo .zip de respaldo.")
        self._manifiesto: respaldo.Manifiesto | None = None
        self._auto_respaldar = tk.BooleanVar(value=True)
        self._ruta_auto: Path | None = None
        self._construir_ui()

    def _construir_ui(self) -> None:
        cont = tk.Frame(self, bg=estilos.FONDO, padx=22, pady=18)
        cont.pack(fill="both", expand=True)

        tk.Label(cont, text="Importar respaldo", font=estilos.F_H3,
                 bg=estilos.FONDO, fg=estilos.TEXTO).pack(anchor="w")
        tk.Label(cont,
                 text="Reemplaza la base de datos y los PDFs actuales con los "
                      "contenidos del respaldo.",
                 font=estilos.F_SMALL, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC, wraplength=480, justify="left"
                 ).pack(anchor="w", pady=(2, 14))

        tk.Label(cont, text="Archivo .zip:",
                 font=estilos.F_BODY_BOLD, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC).pack(anchor="w")
        fila = tk.Frame(cont, bg=estilos.FONDO)
        fila.pack(fill="x", pady=(4, 12))
        estilos.entrada(fila, textvariable=self._ruta_zip, state="readonly"
                        ).pack(side="left", fill="x", expand=True, ipady=3)
        estilos.boton(fila, "Elegir…", self._elegir_zip, "gris",
                      grande=False).pack(side="left", padx=(8, 0))

        self._info_label = tk.Label(cont, textvariable=self._info,
                                    font=estilos.F_SMALL,
                                    bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
                                    wraplength=480, justify="left")
        self._info_label.pack(anchor="w", pady=(0, 10))

        # Red de seguridad: respalda automáticamente lo actual antes de pisar
        tk.Checkbutton(
            cont,
            text="Respaldar datos actuales antes de importar (recomendado)",
            variable=self._auto_respaldar, font=estilos.F_SMALL,
            bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
            activebackground=estilos.FONDO, selectcolor="white",
            cursor="hand2").pack(anchor="w", pady=(0, 14))

        # Progreso
        self._estado = tk.Label(cont, text="", font=estilos.F_SMALL,
                                bg=estilos.FONDO, fg=estilos.TEXTO_SEC)
        self._estado.pack(anchor="w")
        self._barra = ttk.Progressbar(cont, mode="determinate", length=480)
        self._barra.pack(fill="x", pady=(4, 14))

        botones = tk.Frame(cont, bg=estilos.FONDO)
        botones.pack(fill="x")
        self._btn_cerrar = estilos.boton(botones, "Cerrar", self.destroy, "gris")
        self._btn_cerrar.pack(side="right", padx=(10, 0))
        self._btn_importar = estilos.boton(botones, "Importar",
                                           self._iniciar_importar, "azul")
        self._btn_importar.pack(side="right")
        self._btn_importar.configure(state="disabled")

    def _elegir_zip(self) -> None:
        ruta = filedialog.askopenfilename(
            parent=self, title="Elegir respaldo a importar",
            filetypes=[("Respaldo (.zip)", "*.zip"), ("Todos", "*.*")])
        if not ruta:
            return
        self._ruta_zip.set(ruta)
        try:
            self._manifiesto = respaldo.leer_manifiesto(Path(ruta))
        except ValueError as exc:
            self._info.set(f"Archivo inválido: {exc}")
            self._info_label.configure(fg="#dc3545")
            self._btn_importar.configure(state="disabled")
            return
        m = self._manifiesto
        self._info.set(
            f"Respaldo válido.\n"
            f"Negocio: {m.negocio or '(sin nombre)'}\n"
            f"Fecha: {m.fecha_respaldo}\n"
            f"Versión programa: {m.version_programa}\n"
            f"Facturas: {m.conteos.get('facturas', '?')}  ·  "
            f"PDFs: {m.conteos.get('pdfs', '?')}\n"
            f"Incluye API key: {'sí' if m.incluye_api_key else 'no'}")
        self._info_label.configure(fg=estilos.TEXTO_SEC)
        self._btn_importar.configure(state="normal")

    def _iniciar_importar(self) -> None:
        if not self._manifiesto:
            return
        if not messagebox.askyesno(
                "Confirmar importación",
                "Esto sobrescribirá la base de datos y los PDFs actuales.\n\n"
                "¿Continuar?", parent=self, icon="warning"):
            return
        self._btn_importar.configure(state="disabled")
        self._bloquear_cerrar(True)
        self._barra.configure(mode="indeterminate")
        self._barra.start(80)

        def progreso(p: respaldo.ProgresoRespaldo) -> None:
            self._cola.put(("progreso", p))

        def trabajo():
            # Marcador para que el watcher (otro proceso) no toque la BD
            # mientras la reemplazamos.
            with respaldo.bloquear_procesamiento(self.config_app):
                # Red de seguridad: respaldo automático de lo actual ANTES
                # de pisar nada. Si la importación falla o el usuario se
                # arrepiente, este zip permite volver atrás.
                if self._auto_respaldar.get():
                    self._cola.put(
                        ("progreso",
                         respaldo.ProgresoRespaldo(
                             paso="Generando respaldo de seguridad…")))
                    carpeta_auto = respaldo.carpeta_respaldos_automaticos(
                        self.config_app)
                    res_auto = respaldo.exportar(
                        self.config_app, carpeta_auto,
                        incluir_api_key=False,
                        nombre_negocio="AUTO_antes_de_importar",
                        ruta_config_yaml=RAIZ / "config.yaml",
                        ruta_env=RAIZ / ".env")
                    self._ruta_auto = res_auto.ruta_zip
                    # Mantén solo los últimos 5 para no llenar el disco
                    respaldo.limpiar_respaldos_automaticos(
                        self.config_app, conservar=5)
                respaldo.importar(
                    Path(self._ruta_zip.get()), self.config_app,
                    ruta_config_yaml_local=RAIZ / "config.yaml",
                    ruta_env_local=RAIZ / ".env",
                    progreso=progreso)

        self._lanzar_en_thread(trabajo)

    def _manejar_evento(self, tipo: str, payload) -> None:
        if tipo == "progreso":
            p: respaldo.ProgresoRespaldo = payload
            self._estado.config(text=p.paso or "Trabajando…")
            if p.total:
                self._barra.stop()
                self._barra.configure(mode="determinate", maximum=p.total,
                                      value=p.actual)
        elif tipo == "ok":
            self._barra.stop()
            self._barra.configure(mode="determinate",
                                  maximum=100, value=100)
            self._estado.config(text="Respaldo restaurado correctamente.",
                                fg=estilos.VERDE_OK)
            self._bloquear_cerrar(False)
            mensaje = ("El respaldo se restauró correctamente.\n\n"
                       "Cierra y vuelve a abrir el programa para que los "
                       "cambios se reflejen en todas las ventanas.")
            if self._ruta_auto is not None:
                mensaje += (
                    "\n\nRed de seguridad: tus datos anteriores quedaron "
                    f"respaldados en:\n{self._ruta_auto}")
            messagebox.showinfo("Importación completa", mensaje, parent=self)
        elif tipo == "error":
            self._barra.stop()
            self._estado.config(text=f"Error: {payload}", fg="#dc3545")
            self._bloquear_cerrar(False)
            self._btn_importar.configure(state="normal")
            messagebox.showerror("Error al importar",
                                 str(payload), parent=self)


def main() -> None:
    config = cargar_config()
    load_dotenv(RAIZ / ".env", override=True)  # ANTHROPIC_API_KEY para el análisis con IA
    db = Database(Path(config["rutas"]["base_datos"]))
    Buscador(db, config).ejecutar()


if __name__ == "__main__":
    main()
