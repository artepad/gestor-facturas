"""Ícono en la bandeja del sistema con menú interactivo."""

from __future__ import annotations

import os
import subprocess
import sys
from collections.abc import Callable
from pathlib import Path

import pystray
from PIL import Image, ImageDraw

from estado import Estado


def _crear_imagen(activo: bool) -> Image.Image:
    """Genera un ícono PNG: círculo verde si activo, gris si pausado."""
    img = Image.new("RGBA", (64, 64), (255, 255, 255, 0))
    draw = ImageDraw.Draw(img)
    color = (40, 160, 60, 255) if activo else (150, 150, 150, 255)
    draw.ellipse([6, 6, 58, 58], fill=color, outline=(20, 20, 20, 255), width=2)
    # Una "F" simple en el centro para identificar (Facturas)
    draw.text((23, 18), "F", fill=(255, 255, 255, 255))
    return img


def _abrir_carpeta(carpeta: Path) -> None:
    carpeta.mkdir(parents=True, exist_ok=True)
    os.startfile(str(carpeta))  # type: ignore[attr-defined]


def _abrir_buscador(raiz_proyecto: Path) -> None:
    """Abre el buscador en un proceso nuevo (no bloquea)."""
    buscador = raiz_proyecto / "src" / "buscador.py"
    # pythonw para que no muestre consola
    ejecutable = Path(sys.executable).with_name("pythonw.exe")
    if not ejecutable.exists():
        ejecutable = Path(sys.executable)
    subprocess.Popen(
        [str(ejecutable), str(buscador)],
        cwd=str(raiz_proyecto),
        creationflags=subprocess.CREATE_NEW_PROCESS_GROUP if os.name == "nt" else 0,
    )


def construir_icono(
    estado: Estado,
    carpetas: dict[str, Path],
    raiz_proyecto: Path,
    al_reanudar: Callable[[], None],
) -> pystray.Icon:
    """Crea el ícono con menú. `al_reanudar` se llama cuando el usuario quita la pausa
    (sirve para que el orquestador procese los archivos pendientes en _entrada)."""

    def texto_estado(_item) -> str:
        snap = estado.snapshot()
        return (
            f"Hoy: {snap.get('ok', 0)} OK  ·  "
            f"{snap.get('revisar', 0) + snap.get('defectuoso', 0)} revisar  ·  "
            f"{snap.get('duplicado', 0)} dup  ·  "
            f"{snap.get('no_factura', 0)} no factura  ·  "
            f"{snap.get('error', 0)} err"
        )

    def texto_pausa(_item) -> str:
        return "Reanudar vigilancia" if estado.pausado else "Pausar vigilancia"

    def alternar_pausa(icon: pystray.Icon, _item) -> None:
        estado.pausado = not estado.pausado
        icon.icon = _crear_imagen(not estado.pausado)
        icon.title = "Facturas: PAUSADO" if estado.pausado else "Facturas: activo"
        if not estado.pausado:
            al_reanudar()

    def salir(icon: pystray.Icon, _item) -> None:
        icon.stop()

    menu = pystray.Menu(
        pystray.MenuItem(texto_estado, None, enabled=False),
        pystray.Menu.SEPARATOR,
        pystray.MenuItem("Abrir Administrador", lambda _i, _it: _abrir_buscador(raiz_proyecto)),
        pystray.MenuItem(
            "Abrir carpeta de facturas",
            lambda _i, _it: _abrir_carpeta(carpetas["archivo"]),
        ),
        pystray.MenuItem(
            "Abrir _revisar",
            lambda _i, _it: _abrir_carpeta(carpetas["revisar"]),
        ),
        pystray.MenuItem(
            "Abrir _entrada",
            lambda _i, _it: _abrir_carpeta(carpetas["entrada"]),
        ),
        pystray.MenuItem(
            "Abrir _no_facturas",
            lambda _i, _it: _abrir_carpeta(carpetas["no_facturas"]),
        ),
        pystray.Menu.SEPARATOR,
        pystray.MenuItem(texto_pausa, alternar_pausa),
        pystray.MenuItem("Salir", salir),
    )

    return pystray.Icon(
        "facturas",
        _crear_imagen(activo=True),
        "Facturas: activo",
        menu,
    )
