"""Tema visual compartido: colores, fuentes y componentes de interfaz.

Centraliza el estilo para que el buscador y la ventana de factura se vean
iguales. Basado en la guía de estilo del Sistema de Gestión Comercial.
"""

from __future__ import annotations

import tkinter as tk
from tkinter import ttk

# --- Colores ---
FONDO        = "#f8f9fa"   # fondo de toda la app
TEXTO        = "#2c3e50"   # texto principal y títulos
TEXTO_SEC    = "#6c757d"   # texto secundario
TEXTO_TENUE  = "#8a939e"   # hints y footer
SUBTITULO    = "#b4bcc4"   # subtítulo sobre el header oscuro
HEADER_BG    = "#2c3e50"   # header oscuro
ACENTO_VERDE = "#2ecc71"   # franja superior
ACENTO_AZUL  = "#1565c0"   # franja inferior
BORDE        = "#dde1e6"   # bordes suaves
VERDE_OK     = "#1a7a3a"   # texto de confirmación / memoria activa
ENTRY_BORDE_ACTIVO = "#2ecc71"

# Botones de acción: (color normal, color al pasar el mouse)
BOTONES = {
    "azul":  ("#1565c0", "#0d47a1"),
    "verde": ("#27ae60", "#1e8449"),
    "rojo":  ("#dc3545", "#c82333"),
    "gris":  ("#6c757d", "#5a6268"),
}

# --- Fuentes ---
_FAMILIA = "Segoe UI"
F_H1        = (_FAMILIA, 24, "bold")
F_H2        = (_FAMILIA, 20, "bold")
F_H3        = (_FAMILIA, 16, "bold")
F_H4        = (_FAMILIA, 14, "bold")
F_BODY      = (_FAMILIA, 11)
F_BODY_BOLD = (_FAMILIA, 11, "bold")
F_SMALL     = (_FAMILIA, 10)
F_TINY      = (_FAMILIA, 9)
F_HINT      = (_FAMILIA, 9, "italic")
F_BOTON     = (_FAMILIA, 12, "bold")


def aplicar_tema(raiz: tk.Misc) -> ttk.Style:
    """Configura el fondo de la ventana y los estilos de los componentes ttk."""
    raiz.configure(bg=FONDO)
    style = ttk.Style()
    style.theme_use("clam")
    style.configure(
        "App.Treeview", background="white", foreground=TEXTO,
        fieldbackground="white", rowheight=30, font=F_BODY, borderwidth=0)
    style.configure(
        "App.Treeview.Heading", background=HEADER_BG, foreground="white",
        font=F_BODY_BOLD, relief="flat", padding=6)
    style.map(
        "App.Treeview",
        background=[("selected", ACENTO_AZUL)],
        foreground=[("selected", "white")])
    style.map("App.Treeview.Heading", background=[("active", HEADER_BG)])
    return style


def cabecera(
    parent: tk.Misc,
    titulo: str,
    subtitulo: str | None = None,
    *,
    alto: int = 70,
    franja: int = 5,
    fuente_titulo=F_H2,
) -> tk.Frame:
    """Franja verde superior + header oscuro con el título centrado."""
    tk.Frame(parent, bg=ACENTO_VERDE, height=franja).pack(fill="x")
    header = tk.Frame(parent, bg=HEADER_BG, height=alto)
    header.pack(fill="x")
    header.pack_propagate(False)
    centro = tk.Frame(header, bg=HEADER_BG)
    centro.place(relx=0.5, rely=0.5, anchor="center")
    tk.Label(centro, text=titulo, font=fuente_titulo, bg=HEADER_BG, fg="white").pack()
    if subtitulo:
        tk.Label(centro, text=subtitulo, font=F_BODY, bg=HEADER_BG,
                 fg=SUBTITULO).pack()
    return header


def pie(
    parent: tk.Misc,
    texto: str,
    *,
    alto: int = 38,
    franja: int = 5,
    version: str | None = None,
) -> tk.Frame:
    """Footer con el nombre del sistema + franja azul inferior.

    Si se pasa `version` (ej. "v1.0.0") se muestra discretamente a la derecha.
    """
    tk.Frame(parent, bg=ACENTO_AZUL, height=franja).pack(fill="x", side="bottom")
    footer = tk.Frame(parent, bg=FONDO, height=alto)
    footer.pack(fill="x", side="bottom")
    footer.pack_propagate(False)
    tk.Frame(footer, bg=BORDE, height=2).pack(fill="x", pady=(8, 6))
    # El nombre va centrado; la versión, si existe, alineada a la derecha
    contenido = tk.Frame(footer, bg=FONDO)
    contenido.pack(fill="x")
    tk.Label(contenido, text=texto, font=F_BODY_BOLD, bg=FONDO,
             fg=TEXTO_TENUE).place(relx=0.5, rely=0.5, anchor="center")
    if version:
        tk.Label(contenido, text=version, font=F_TINY, bg=FONDO,
                 fg=TEXTO_TENUE).pack(side="right", padx=14)
    return footer


def boton(parent: tk.Misc, texto: str, command, color: str = "azul",
          grande: bool = True) -> tk.Button:
    """Botón plano de color con efecto hover. `grande` para botones de acción."""
    normal, hover = BOTONES.get(color, BOTONES["azul"])
    if grande:
        padx, pady, fuente = 22, 8, F_BOTON
    else:
        padx, pady, fuente = 10, 3, F_BODY_BOLD
    btn = tk.Button(
        parent, text=texto, font=fuente, bg=normal, fg="white",
        activebackground=hover, activeforeground="white",
        relief="flat", bd=0, padx=padx, pady=pady, cursor="hand2",
        command=command)
    btn.bind("<Enter>", lambda _e: btn.configure(bg=hover))
    btn.bind("<Leave>", lambda _e: btn.configure(bg=normal))
    return btn


def entrada(parent: tk.Misc, **kw) -> tk.Entry:
    """tk.Entry plano con borde que se ilumina en verde al enfocarse."""
    return tk.Entry(
        parent, font=F_BODY, bg="white", fg=TEXTO, relief="flat",
        highlightthickness=2, highlightbackground=BORDE,
        highlightcolor=ENTRY_BORDE_ACTIVO, insertbackground=TEXTO, **kw)


def panel(parent: tk.Misc, titulo: str) -> tk.LabelFrame:
    """Sección con título, al estilo de la app."""
    return tk.LabelFrame(
        parent, text=titulo, font=F_H4, bg=FONDO, fg=TEXTO,
        padx=12, pady=10)
