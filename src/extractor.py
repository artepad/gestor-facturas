"""Extracción de texto e imagen de la primera página de un PDF."""

from __future__ import annotations

import base64
from dataclasses import dataclass
from pathlib import Path

import fitz  # PyMuPDF


@dataclass(frozen=True)
class ContenidoPDF:
    texto: str
    imagen_b64: str
    paginas: int


def extraer(ruta_pdf: Path, dpi: int = 150) -> ContenidoPDF:
    """Devuelve el texto y la primera página renderizada como PNG en base64."""
    doc = fitz.open(ruta_pdf)
    try:
        if doc.page_count == 0:
            raise ValueError(f"PDF sin páginas: {ruta_pdf}")
        pagina = doc.load_page(0)
        texto = pagina.get_text("text") or ""
        pix = pagina.get_pixmap(dpi=dpi)
        imagen_b64 = base64.standard_b64encode(pix.tobytes("png")).decode("ascii")
        return ContenidoPDF(texto=texto, imagen_b64=imagen_b64, paginas=doc.page_count)
    finally:
        doc.close()
