"""Ventana de Configuración del sistema.

Accesible desde el menú hamburguesa del footer del Administrador. Centraliza:
- Información del desarrollador.
- Gestión de respaldos (exportar e importar base de datos).

Los diálogos modales de exportar/importar viven aquí (antes estaban en
`buscador.py`): la lógica real está en `respaldo.py` y solo se invoca desde
estos diálogos. Esto deja al Administrador con una UI más limpia.
"""

from __future__ import annotations

import queue
import threading
import tkinter as tk
from collections.abc import Callable
from pathlib import Path
from tkinter import filedialog, messagebox, ttk

import estilos
import respaldo
from version import NOMBRE, __version__

RAIZ = Path(__file__).resolve().parent.parent


# Datos del desarrollador (mostrar tal cual al usuario)
DESARROLLADOR = {
    "nombre": "Miguel Saavedra Quinteros",
    "telefono": "+56948777448",
    "correo": "misaavedraq1990@gmail.com",
}


class VentanaConfiguracion(tk.Toplevel):
    """Toplevel modal con secciones de información y gestión de respaldos."""

    _TITULO = "Configuración"
    _ANCHO = 760
    _ALTO = 740

    def __init__(self, padre: tk.Misc, config: dict) -> None:
        super().__init__(padre)
        self.config_app = config
        self.title(self._TITULO)
        self.transient(padre)
        self.configure(bg=estilos.FONDO)
        self.resizable(False, False)
        self._centrar()
        self._construir_ui()
        self.bind("<Escape>", lambda _e: self.destroy())

    def _centrar(self) -> None:
        sw = self.winfo_screenwidth()
        sh = self.winfo_screenheight()
        x = (sw - self._ANCHO) // 2
        y = (sh - self._ALTO) // 2
        self.geometry(f"{self._ANCHO}x{self._ALTO}+{x}+{y}")

    def _construir_ui(self) -> None:
        estilos.cabecera(
            self, self._TITULO, subtitulo="Información y herramientas del sistema",
            alto=76, franja=5, fuente_titulo=estilos.F_H2)
        estilos.pie(self, NOMBRE, alto=50, franja=5,
                    version=f"v{__version__}")
        self._barra_inferior()

        cont = tk.Frame(self, bg=estilos.FONDO)
        cont.pack(fill="both", expand=True, padx=28, pady=(18, 8))

        self._construir_panel_desarrollador(cont)
        self._construir_panel_respaldos(cont)

    # --- Secciones ---

    def _construir_panel_desarrollador(self, parent: tk.Misc) -> None:
        panel = estilos.panel(parent, "Información del desarrollador")
        panel.pack(fill="x", pady=(0, 16))
        panel.configure(padx=20, pady=14)

        tarjeta = tk.Frame(panel, bg=estilos.FONDO)
        tarjeta.pack(fill="x")

        # Avatar simple: círculo con iniciales sobre canvas
        iniciales = "".join(p[0] for p in DESARROLLADOR["nombre"].split()[:2])
        canvas = tk.Canvas(tarjeta, width=72, height=72, bg=estilos.FONDO,
                           highlightthickness=0)
        canvas.pack(side="left", padx=(0, 18), pady=4)
        canvas.create_oval(2, 2, 70, 70, fill=estilos.ACENTO_AZUL, outline="")
        canvas.create_text(36, 36, text=iniciales,
                           font=(estilos.F_H3[0], 22, "bold"), fill="white")

        datos = tk.Frame(tarjeta, bg=estilos.FONDO)
        datos.pack(side="left", fill="x", expand=True)

        tk.Label(datos, text=DESARROLLADOR["nombre"], font=estilos.F_H3,
                 bg=estilos.FONDO, fg=estilos.TEXTO).pack(anchor="w")
        tk.Label(datos, text="Desarrollador del sistema",
                 font=estilos.F_SMALL, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC).pack(anchor="w", pady=(0, 12))

        self._fila_contacto(datos, "📞", "Teléfono", DESARROLLADOR["telefono"])
        self._fila_contacto(datos, "📧", "Correo",   DESARROLLADOR["correo"])

    def _fila_contacto(self, parent: tk.Misc, icono: str, etiqueta: str,
                       valor: str) -> None:
        fila = tk.Frame(parent, bg=estilos.FONDO)
        fila.pack(fill="x", pady=4)
        tk.Label(fila, text=icono, font=(estilos.F_BODY[0], 15),
                 bg=estilos.FONDO, fg=estilos.ACENTO_AZUL,
                 width=2, anchor="center").pack(side="left")
        tk.Label(fila, text=f"{etiqueta}:", font=estilos.F_BODY_BOLD,
                 bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
                 width=10, anchor="w").pack(side="left", padx=(4, 0))
        tk.Label(fila, text=valor, font=estilos.F_BODY_BOLD,
                 bg=estilos.FONDO, fg=estilos.TEXTO).pack(side="left")

    def _construir_panel_respaldos(self, parent: tk.Misc) -> None:
        panel = estilos.panel(parent, "Gestión de respaldos")
        panel.pack(fill="x")
        panel.configure(padx=20, pady=14)

        tk.Label(
            panel,
            text="Genera o restaura una copia completa de la base de datos "
                 "y los PDFs archivados.",
            font=estilos.F_SMALL, bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
            wraplength=620, justify="left",
        ).pack(anchor="w", pady=(0, 14))

        tarjetas = tk.Frame(panel, bg=estilos.FONDO)
        tarjetas.pack(fill="x")
        tarjetas.grid_columnconfigure(0, weight=1, uniform="resp")
        tarjetas.grid_columnconfigure(1, weight=1, uniform="resp")

        self._tarjeta_accion(
            tarjetas, 0, "⬆", "Exportar base de datos",
            "Crea un archivo .zip con toda la información del sistema "
            "para guardarlo en otro disco o en la nube.",
            "Exportar", self._exportar)
        self._tarjeta_accion(
            tarjetas, 1, "⬇", "Importar base de datos",
            "Restaura un respaldo previamente generado. Reemplaza la "
            "información actual por la del archivo .zip.",
            "Importar", self._importar)

    def _tarjeta_accion(self, parent: tk.Misc, col: int, icono: str,
                        titulo: str, descripcion: str, etiqueta_boton: str,
                        accion: Callable[[], None]) -> None:
        tarjeta = tk.Frame(parent, bg="white",
                           highlightthickness=1, highlightbackground=estilos.BORDE)
        tarjeta.grid(row=0, column=col, sticky="nsew",
                     padx=(0 if col == 0 else 10, 10 if col == 0 else 0))
        interior = tk.Frame(tarjeta, bg="white", padx=18, pady=16)
        interior.pack(fill="both", expand=True)

        encabezado = tk.Frame(interior, bg="white")
        encabezado.pack(fill="x")
        tk.Label(encabezado, text=icono,
                 font=(estilos.F_H3[0], 22, "bold"),
                 bg="white", fg=estilos.ACENTO_AZUL).pack(side="left")
        tk.Label(encabezado, text=titulo, font=estilos.F_BODY_BOLD,
                 bg="white", fg=estilos.TEXTO).pack(side="left", padx=(10, 0))

        tk.Label(interior, text=descripcion, font=estilos.F_SMALL,
                 bg="white", fg=estilos.TEXTO_SEC, wraplength=260,
                 justify="left", anchor="w").pack(fill="x", pady=(10, 16))

        estilos.boton(interior, etiqueta_boton, accion, "azul",
                      grande=False).pack(anchor="w", pady=(0, 4))

    # --- Barra inferior ---

    def _barra_inferior(self) -> None:
        barra = tk.Frame(self, bg=estilos.FONDO, height=56)
        barra.pack(fill="x", side="bottom")
        barra.pack_propagate(False)
        centro = tk.Frame(barra, bg=estilos.FONDO)
        centro.place(relx=0.5, rely=0.5, anchor="center")
        estilos.boton(centro, "Cerrar", self.destroy, "gris").pack()

    # --- Acciones ---

    def _exportar(self) -> None:
        DialogoExportarRespaldo(self, self.config_app).mostrar()

    def _importar(self) -> None:
        if not messagebox.askyesno(
                "Importar respaldo",
                "Importar reemplazará la base de datos y los PDFs actuales con "
                "los del respaldo.\n\n"
                "Antes de continuar, IMPORTANTE:\n"
                "• Pausa la vigilancia desde el ícono de la bandeja.\n"
                "• Asegúrate de haber exportado un respaldo de seguridad.\n\n"
                "¿Deseas continuar?",
                parent=self, icon="warning"):
            return
        DialogoImportarRespaldo(self, self.config_app).mostrar()


def abrir_configuracion(padre: tk.Misc, config: dict) -> None:
    """Helper para abrir la ventana desde cualquier punto de la app."""
    VentanaConfiguracion(padre, config)


# ============================================================
#   Diálogos de exportar / importar (lógica real en respaldo.py)
# ============================================================


class _DialogoRespaldoBase(tk.Toplevel):
    """Base común para los diálogos de exportar e importar.

    Ejecuta la operación pesada en un thread para no congelar la UI, y
    transfiere actualizaciones de progreso por una cola consumida desde el
    hilo de Tk vía `after()`.
    """

    def __init__(self, padre: tk.Misc, titulo: str, ancho: int = 560,
                 alto: int = 540) -> None:
        super().__init__(padre)
        self.title(titulo)
        self.transient(padre)
        self.configure(bg=estilos.FONDO)
        self.resizable(False, False)
        self._ancho, self._alto = ancho, alto
        self._centrar(padre)
        self._cola: queue.Queue = queue.Queue()
        self._thread: threading.Thread | None = None
        self._cerrar_permitido = True
        self.protocol("WM_DELETE_WINDOW", self._intentar_cerrar)

    def _centrar(self, padre: tk.Misc) -> None:
        """Centra el diálogo sobre la ventana padre (o la pantalla)."""
        try:
            padre_top = padre.winfo_toplevel()
            padre_top.update_idletasks()
            px = padre_top.winfo_rootx()
            py = padre_top.winfo_rooty()
            pw = padre_top.winfo_width()
            ph = padre_top.winfo_height()
            x = px + (pw - self._ancho) // 2
            y = py + (ph - self._alto) // 2
        except tk.TclError:
            sw = self.winfo_screenwidth()
            sh = self.winfo_screenheight()
            x = (sw - self._ancho) // 2
            y = (sh - self._alto) // 2
        x = max(0, min(x, self.winfo_screenwidth() - self._ancho))
        y = max(0, min(y, self.winfo_screenheight() - self._alto))
        self.geometry(f"{self._ancho}x{self._alto}+{x}+{y}")

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
        raise NotImplementedError

    def mostrar(self) -> None:
        self.wait_window()


class DialogoExportarRespaldo(_DialogoRespaldoBase):
    """Modal para generar un respaldo .zip."""

    def __init__(self, padre: tk.Misc, config: dict) -> None:
        super().__init__(padre, "Exportar respaldo", ancho=620, alto=520)
        self.config_app = config
        self._carpeta_destino = tk.StringVar(value=str(Path.home() / "Desktop"))
        self._nombre_negocio = tk.StringVar(value="")
        self._incluir_api_key = tk.BooleanVar(value=False)
        self._construir_ui()

    def _construir_ui(self) -> None:
        estilos.cabecera(
            self, "Exportar respaldo",
            subtitulo="Genera un archivo .zip con toda la información del sistema",
            alto=72, franja=5, fuente_titulo=estilos.F_H3)
        estilos.pie(self, NOMBRE, alto=50, franja=5,
                    version=f"v{__version__}")
        self._construir_barra_inferior()

        cont = tk.Frame(self, bg=estilos.FONDO)
        cont.pack(fill="x", padx=24, pady=(16, 6))
        panel = estilos.panel(cont, "Datos del respaldo")
        panel.pack(fill="x")
        panel.configure(padx=18, pady=14)

        tk.Label(panel, text="Nombre del negocio (etiqueta del respaldo):",
                 font=estilos.F_BODY_BOLD, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC).pack(anchor="w")
        estilos.entrada(panel, textvariable=self._nombre_negocio
                        ).pack(fill="x", pady=(4, 12), ipady=4)

        tk.Label(panel, text="Carpeta donde guardar:",
                 font=estilos.F_BODY_BOLD, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC).pack(anchor="w")
        fila_carpeta = tk.Frame(panel, bg=estilos.FONDO)
        fila_carpeta.pack(fill="x", pady=(4, 12))
        estilos.entrada(fila_carpeta, textvariable=self._carpeta_destino
                        ).pack(side="left", fill="x", expand=True, ipady=4)
        estilos.boton(fila_carpeta, "Elegir…", self._elegir_carpeta, "gris",
                      grande=False).pack(side="left", padx=(8, 0))

        tk.Checkbutton(
            panel, text="Incluir API key (.env) — solo si el respaldo es para ti",
            variable=self._incluir_api_key, font=estilos.F_SMALL,
            bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
            activebackground=estilos.FONDO, selectcolor="white",
            cursor="hand2").pack(anchor="w")

        # Bloque de progreso pegado debajo del panel
        bloque = tk.Frame(self, bg=estilos.FONDO)
        bloque.pack(fill="x", padx=24, pady=(4, 0))
        self._estado = tk.Label(bloque, text="", font=estilos.F_SMALL,
                                bg=estilos.FONDO, fg=estilos.TEXTO_SEC)
        self._estado.pack(anchor="w", pady=(8, 4))
        self._barra = ttk.Progressbar(bloque, mode="determinate")
        self._barra.pack(fill="x")

    def _construir_barra_inferior(self) -> None:
        """Barra inferior con botones centrados, estilo consistente."""
        barra = tk.Frame(self, bg=estilos.FONDO, height=64)
        barra.pack(fill="x", side="bottom")
        barra.pack_propagate(False)
        tk.Frame(barra, bg=estilos.BORDE, height=1).pack(fill="x")
        centro = tk.Frame(barra, bg=estilos.FONDO)
        centro.place(relx=0.5, rely=0.5, anchor="center")
        self._btn_exportar = estilos.boton(
            centro, "Exportar", self._iniciar_exportar, "azul")
        self._btn_exportar.pack(side="left", padx=10)
        self._btn_cerrar = estilos.boton(centro, "Cerrar", self.destroy, "gris")
        self._btn_cerrar.pack(side="left", padx=10)

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
        super().__init__(padre, "Importar respaldo", ancho=620, alto=480)
        self.config_app = config
        self._ruta_zip = tk.StringVar(value="")
        self._info = tk.StringVar(value="Selecciona un archivo .zip de respaldo.")
        self._manifiesto: respaldo.Manifiesto | None = None
        self._auto_respaldar = tk.BooleanVar(value=True)
        self._ruta_auto: Path | None = None
        self._construir_ui()

    def _construir_ui(self) -> None:
        estilos.cabecera(
            self, "Importar respaldo",
            subtitulo="Restaura una copia previamente generada",
            alto=72, franja=5, fuente_titulo=estilos.F_H3)
        estilos.pie(self, NOMBRE, alto=50, franja=5,
                    version=f"v{__version__}")
        self._construir_barra_inferior()

        cont = tk.Frame(self, bg=estilos.FONDO)
        cont.pack(fill="x", padx=24, pady=(16, 6))
        panel = estilos.panel(cont, "Archivo de respaldo")
        panel.pack(fill="x")
        panel.configure(padx=18, pady=14)

        tk.Label(panel, text="Archivo .zip:",
                 font=estilos.F_BODY_BOLD, bg=estilos.FONDO,
                 fg=estilos.TEXTO_SEC).pack(anchor="w")
        fila = tk.Frame(panel, bg=estilos.FONDO)
        fila.pack(fill="x", pady=(4, 12))
        estilos.entrada(fila, textvariable=self._ruta_zip, state="readonly"
                        ).pack(side="left", fill="x", expand=True, ipady=4)
        estilos.boton(fila, "Elegir…", self._elegir_zip, "gris",
                      grande=False).pack(side="left", padx=(8, 0))

        self._info_label = tk.Label(panel, textvariable=self._info,
                                    font=estilos.F_SMALL,
                                    bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
                                    wraplength=520, justify="left")
        self._info_label.pack(anchor="w", pady=(0, 10))

        tk.Checkbutton(
            panel,
            text="Respaldar datos actuales antes de importar (recomendado)",
            variable=self._auto_respaldar, font=estilos.F_SMALL,
            bg=estilos.FONDO, fg=estilos.TEXTO_SEC,
            activebackground=estilos.FONDO, selectcolor="white",
            cursor="hand2").pack(anchor="w")

        bloque = tk.Frame(self, bg=estilos.FONDO)
        bloque.pack(fill="x", padx=24, pady=(4, 0))
        self._estado = tk.Label(bloque, text="", font=estilos.F_SMALL,
                                bg=estilos.FONDO, fg=estilos.TEXTO_SEC)
        self._estado.pack(anchor="w", pady=(8, 4))
        self._barra = ttk.Progressbar(bloque, mode="determinate")
        self._barra.pack(fill="x")

    def _construir_barra_inferior(self) -> None:
        barra = tk.Frame(self, bg=estilos.FONDO, height=64)
        barra.pack(fill="x", side="bottom")
        barra.pack_propagate(False)
        tk.Frame(barra, bg=estilos.BORDE, height=1).pack(fill="x")
        centro = tk.Frame(barra, bg=estilos.FONDO)
        centro.place(relx=0.5, rely=0.5, anchor="center")
        self._btn_importar = estilos.boton(
            centro, "Importar", self._iniciar_importar, "azul")
        self._btn_importar.pack(side="left", padx=10)
        self._btn_importar.configure(state="disabled")
        self._btn_cerrar = estilos.boton(centro, "Cerrar", self.destroy, "gris")
        self._btn_cerrar.pack(side="left", padx=10)

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
            with respaldo.bloquear_procesamiento(self.config_app):
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
