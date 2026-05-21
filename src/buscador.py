"""Ventana de búsqueda de facturas (Tkinter).

Uso: py src/buscador.py
"""

from __future__ import annotations

import tkinter as tk
from pathlib import Path
from tkinter import messagebox, ttk

import yaml
from dotenv import load_dotenv
from tkcalendar import DateEntry

import estilos
from db import Database
from ventana_factura import abrir_ventana_factura

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


class Buscador:
    _ANCHO = 900
    _ALTO = 690
    # Íconos de la columna Acciones (por ahora solo visuales)
    _ICONO_VER = "🔍"
    _ICONO_EDITAR = "📝"
    _ICONO_ELIMINAR = "❌"

    def __init__(self, db: Database, config: dict) -> None:
        self.db = db
        self.config = config
        self.filas: list = []
        self._pantalla_completa = False
        self.ventana = tk.Tk()
        self.ventana.title("Buscador de Facturas")
        self.ventana.minsize(780, 520)
        estilos.aplicar_tema(self.ventana)
        self._centrar()
        self._construir_ui()
        self._refrescar_proveedores()
        self.buscar()
        self.ventana.bind("<Escape>", lambda _e: self._salir_pantalla_completa())
        self.entrada_texto.focus_set()  # foco listo en el campo de búsqueda

    def _centrar(self) -> None:
        sw = self.ventana.winfo_screenwidth()
        sh = self.ventana.winfo_screenheight()
        x = (sw - self._ANCHO) // 2
        y = (sh - self._ALTO) // 2
        self.ventana.geometry(f"{self._ANCHO}x{self._ALTO}+{x}+{y}")

    # --- Construcción de la interfaz ---

    def _construir_ui(self) -> None:
        estilos.cabecera(self.ventana, "Buscador de Facturas")
        estilos.pie(self.ventana, "Gestor de Facturas")
        self._construir_barra_inferior()

        cont = tk.Frame(self.ventana, bg=estilos.FONDO)
        cont.pack(fill="both", expand=True, padx=24, pady=(14, 8))
        self._construir_filtros(cont)
        self._construir_tabla(cont)

    def _construir_filtros(self, parent: tk.Misc) -> None:
        filtros = estilos.panel(parent, "Filtros")
        filtros.pack(fill="x")

        # Fila 1: búsqueda libre + proveedor + activador del filtro de fecha
        fila1 = tk.Frame(filtros, bg=estilos.FONDO)
        fila1.pack(fill="x")
        tk.Label(fila1, text="Búsqueda libre:", font=estilos.F_BODY,
                 bg=estilos.FONDO, fg=estilos.TEXTO).pack(side="left")
        self.entrada_texto = estilos.entrada(fila1, width=26)
        self.entrada_texto.pack(side="left", padx=(8, 16))
        self.entrada_texto.bind("<Return>", lambda _e: self.buscar())

        tk.Label(fila1, text="Proveedor:", font=estilos.F_BODY,
                 bg=estilos.FONDO, fg=estilos.TEXTO).pack(side="left")
        self.combo_proveedor = ttk.Combobox(fila1, width=18, state="readonly")
        self.combo_proveedor.pack(side="left", padx=(8, 16))
        self.combo_proveedor.bind("<<ComboboxSelected>>", lambda _e: self.buscar())

        self.usar_fecha = tk.BooleanVar(value=False)
        tk.Checkbutton(
            fila1, text="Filtrar por fecha", variable=self.usar_fecha,
            command=self._alternar_fechas, font=estilos.F_BODY,
            bg=estilos.FONDO, fg=estilos.TEXTO, activebackground=estilos.FONDO,
            activeforeground=estilos.TEXTO, selectcolor="white",
            cursor="hand2").pack(side="left")

        # Marco de fechas: oculto por defecto, con calendarios visuales
        self.marco_fechas = tk.Frame(filtros, bg=estilos.FONDO)
        tk.Label(self.marco_fechas, text="Desde:", font=estilos.F_BODY,
                 bg=estilos.FONDO, fg=estilos.TEXTO).pack(side="left")
        self.fecha_desde = self._crear_calendario(self.marco_fechas)
        self.fecha_desde.pack(side="left", padx=(6, 18))
        tk.Label(self.marco_fechas, text="Hasta:", font=estilos.F_BODY,
                 bg=estilos.FONDO, fg=estilos.TEXTO).pack(side="left")
        self.fecha_hasta = self._crear_calendario(self.marco_fechas)
        self.fecha_hasta.pack(side="left", padx=6)

        # Fila de botones
        self.fila_botones = tk.Frame(filtros, bg=estilos.FONDO)
        self.fila_botones.pack(fill="x", pady=(12, 2))
        estilos.boton(self.fila_botones, "Buscar", self.buscar, "azul").pack(side="left")
        estilos.boton(self.fila_botones, "Limpiar filtros", self.limpiar,
                      "gris").pack(side="left", padx=8)

    def _crear_calendario(self, parent: tk.Misc) -> DateEntry:
        """Campo de fecha con calendario visual desplegable."""
        cal = DateEntry(
            parent, width=11, date_pattern="yyyy-mm-dd", locale="es_CL",
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
        marco.pack(fill="both", expand=True, pady=(14, 0))

        columnas = ("fecha", "proveedor", "numero", "total", "razon_social",
                    "confianza", "acciones")
        self.tabla = ttk.Treeview(marco, columns=columnas, show="headings",
                                  style="App.Treeview")
        encabezados = {
            "fecha": ("Fecha", 92),
            "proveedor": ("Proveedor", 128),
            "numero": ("N° Factura", 98),
            "total": ("Total", 98),
            "razon_social": ("Razón Social", 210),
            "confianza": ("Conf.", 54),
            "acciones": ("Acciones", 120),
        }
        for col, (titulo, ancho) in encabezados.items():
            self.tabla.heading(col, text=titulo, anchor="center")
            self.tabla.column(col, width=ancho, anchor="w", stretch=False)
        self.tabla.column("razon_social", stretch=True)
        self.tabla.column("confianza", anchor="center")
        self.tabla.column("acciones", anchor="center")

        # Filas alternadas (efecto cebra) para mejorar la lectura
        self.tabla.tag_configure("par", background="white")
        self.tabla.tag_configure("impar", background="#eef2f6")

        scroll = ttk.Scrollbar(marco, orient="vertical", command=self.tabla.yview)
        self.tabla.configure(yscrollcommand=scroll.set)
        self.tabla.pack(side="left", fill="both", expand=True)
        scroll.pack(side="right", fill="y")
        self.tabla.bind("<Double-1>", self._abrir_seleccionado)

        self.etiqueta_estado = tk.Label(
            parent, text="", font=estilos.F_SMALL,
            bg=estilos.FONDO, fg=estilos.TEXTO_SEC)
        self.etiqueta_estado.pack(anchor="w", pady=(8, 0))
        tk.Label(parent, text="Doble clic en una fila para abrir el detalle de la factura.",
                 font=estilos.F_HINT, bg=estilos.FONDO,
                 fg=estilos.TEXTO_TENUE).pack(anchor="w")

    def _construir_barra_inferior(self) -> None:
        barra = tk.Frame(self.ventana, bg=estilos.FONDO, height=60)
        barra.pack(fill="x", side="bottom")
        barra.pack_propagate(False)
        centro = tk.Frame(barra, bg=estilos.FONDO)
        centro.place(relx=0.5, rely=0.5, anchor="center")
        estilos.boton(centro, "Cerrar", self._cerrar, "gris").pack(side="left", padx=8)
        self.btn_pantalla = estilos.boton(
            centro, "Modo pantalla completa",
            self._alternar_pantalla_completa, "verde")
        self.btn_pantalla.pack(side="left", padx=8)

    # --- Acciones ---

    def _alternar_fechas(self) -> None:
        if self.usar_fecha.get():
            self.marco_fechas.pack(fill="x", pady=(10, 0), before=self.fila_botones)
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
            conf = f"{fila.confianza:.2f}" if fila.confianza is not None else ""
            etiqueta = "par" if indice % 2 == 0 else "impar"
            iconos = f"{self._ICONO_VER}    {self._ICONO_EDITAR}    {self._ICONO_ELIMINAR}"
            self.tabla.insert("", "end", tags=(etiqueta,), values=(
                _fecha_dmy(fila.fecha), fila.proveedor, fila.numero_factura or "",
                total, fila.razon_social or "", conf, iconos,
            ))

        self.etiqueta_estado.config(text=f"{len(self.filas)} resultado(s)")
        self._refrescar_proveedores()

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

    def ejecutar(self) -> None:
        self.ventana.mainloop()


def main() -> None:
    config = cargar_config()
    load_dotenv(RAIZ / ".env", override=True)  # ANTHROPIC_API_KEY para el análisis con IA
    db = Database(Path(config["rutas"]["base_datos"]))
    Buscador(db, config).ejecutar()


if __name__ == "__main__":
    main()
