"""Ventana de búsqueda de facturas (Tkinter).

Uso: py src/buscador.py
"""

from __future__ import annotations

import os
import sys
import tkinter as tk
from pathlib import Path
from tkinter import messagebox, ttk

import yaml

from db import Database

RAIZ = Path(__file__).resolve().parent.parent


def cargar_config() -> dict:
    with open(RAIZ / "config.yaml", "r", encoding="utf-8") as fh:
        return yaml.safe_load(fh)


class Buscador:
    def __init__(self, db: Database) -> None:
        self.db = db
        self.ventana = tk.Tk()
        self.ventana.title("Buscador de Facturas")
        self.ventana.geometry("1000x600")
        self._construir_ui()
        self._refrescar_proveedores()
        self.buscar()

    def _construir_ui(self) -> None:
        marco_filtros = ttk.LabelFrame(self.ventana, text="Filtros", padding=10)
        marco_filtros.pack(fill="x", padx=10, pady=(10, 5))

        ttk.Label(marco_filtros, text="Búsqueda libre:").grid(row=0, column=0, sticky="w")
        self.entrada_texto = ttk.Entry(marco_filtros, width=40)
        self.entrada_texto.grid(row=0, column=1, padx=5, sticky="we")
        self.entrada_texto.bind("<Return>", lambda _e: self.buscar())

        ttk.Label(marco_filtros, text="Proveedor:").grid(row=0, column=2, sticky="w", padx=(10, 0))
        self.combo_proveedor = ttk.Combobox(marco_filtros, width=22, state="readonly")
        self.combo_proveedor.grid(row=0, column=3, padx=5)
        self.combo_proveedor.bind("<<ComboboxSelected>>", lambda _e: self.buscar())

        ttk.Label(marco_filtros, text="Desde (YYYY-MM-DD):").grid(row=1, column=0, sticky="w", pady=(8, 0))
        self.entrada_desde = ttk.Entry(marco_filtros, width=14)
        self.entrada_desde.grid(row=1, column=1, sticky="w", padx=5, pady=(8, 0))

        ttk.Label(marco_filtros, text="Hasta (YYYY-MM-DD):").grid(row=1, column=2, sticky="w", padx=(10, 0), pady=(8, 0))
        self.entrada_hasta = ttk.Entry(marco_filtros, width=14)
        self.entrada_hasta.grid(row=1, column=3, sticky="w", padx=5, pady=(8, 0))

        marco_botones = ttk.Frame(marco_filtros)
        marco_botones.grid(row=2, column=0, columnspan=4, pady=(10, 0), sticky="w")
        ttk.Button(marco_botones, text="Buscar", command=self.buscar).pack(side="left")
        ttk.Button(marco_botones, text="Limpiar filtros", command=self.limpiar).pack(side="left", padx=5)

        marco_filtros.columnconfigure(1, weight=1)

        # Tabla de resultados
        marco_tabla = ttk.Frame(self.ventana, padding=(10, 5))
        marco_tabla.pack(fill="both", expand=True)

        columnas = ("fecha", "proveedor", "numero", "total", "razon_social", "confianza")
        self.tabla = ttk.Treeview(marco_tabla, columns=columnas, show="headings", height=20)
        encabezados = {
            "fecha": ("Fecha", 90),
            "proveedor": ("Proveedor", 150),
            "numero": ("N° Factura", 110),
            "total": ("Total", 110),
            "razon_social": ("Razón Social", 250),
            "confianza": ("Conf.", 60),
        }
        for col, (titulo, ancho) in encabezados.items():
            self.tabla.heading(col, text=titulo)
            self.tabla.column(col, width=ancho, anchor="w")
        self.tabla.column("total", anchor="center")
        self.tabla.column("confianza", anchor="center")

        scroll = ttk.Scrollbar(marco_tabla, orient="vertical", command=self.tabla.yview)
        self.tabla.configure(yscrollcommand=scroll.set)
        self.tabla.pack(side="left", fill="both", expand=True)
        scroll.pack(side="right", fill="y")

        self.tabla.bind("<Double-1>", self._abrir_seleccionado)

        # Pie con conteo + instrucciones
        self.etiqueta_estado = ttk.Label(self.ventana, text="", padding=(10, 5))
        self.etiqueta_estado.pack(fill="x")

        ttk.Label(
            self.ventana,
            text="Doble clic en una fila para abrir el PDF.",
            padding=(10, 0, 10, 10),
            foreground="#666",
        ).pack(fill="x")

        self.filas: list = []  # cache para _abrir_seleccionado

    def _refrescar_proveedores(self) -> None:
        valores = [""] + self.db.listar_proveedores()
        self.combo_proveedor["values"] = valores
        if not self.combo_proveedor.get():
            self.combo_proveedor.set("")

    def buscar(self) -> None:
        texto = self.entrada_texto.get().strip() or None
        proveedor = self.combo_proveedor.get().strip() or None
        desde = self.entrada_desde.get().strip() or None
        hasta = self.entrada_hasta.get().strip() or None

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

        for fila in self.filas:
            total = f"${fila.total:,.0f}".replace(",", ".") if fila.total is not None else ""
            conf = f"{fila.confianza:.2f}" if fila.confianza is not None else ""
            self.tabla.insert("", "end", values=(
                fila.fecha, fila.proveedor, fila.numero_factura or "",
                total, fila.razon_social or "", conf,
            ))

        self.etiqueta_estado.config(text=f"{len(self.filas)} resultado(s)")
        self._refrescar_proveedores()

    def limpiar(self) -> None:
        self.entrada_texto.delete(0, "end")
        self.combo_proveedor.set("")
        self.entrada_desde.delete(0, "end")
        self.entrada_hasta.delete(0, "end")
        self.buscar()

    def _abrir_seleccionado(self, _evento) -> None:
        seleccion = self.tabla.selection()
        if not seleccion:
            return
        indice = self.tabla.index(seleccion[0])
        ruta = Path(self.filas[indice].ruta_archivo)
        if not ruta.exists():
            messagebox.showwarning("No encontrado", f"El archivo ya no está en:\n{ruta}")
            return
        try:
            os.startfile(str(ruta))  # type: ignore[attr-defined]
        except AttributeError:
            messagebox.showinfo("Ruta", str(ruta))

    def ejecutar(self) -> None:
        self.ventana.mainloop()


def main() -> None:
    config = cargar_config()
    db = Database(Path(config["rutas"]["base_datos"]))
    Buscador(db).ejecutar()


if __name__ == "__main__":
    main()
