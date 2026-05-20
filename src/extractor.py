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


@dataclass(frozen=True)
class ContenidoCompleto:
    """Contenido de TODAS las páginas de un PDF, para extraer el detalle.

    `imagenes_b64` puede tener más elementos que páginas: una página muy alta
    (escaneo largo) se divide en franjas para no perder resolución."""
    texto: str
    imagenes_b64: list[str]
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


def _bandas(rect: "fitz.Rect") -> list["fitz.Rect"]:
    """Divide una página alta en franjas horizontales con leve traslape.

    Las facturas chilenas suelen ser escaneos largos: enviarlas como una sola
    imagen haría que la IA la reduzca y el texto quede ilegible. Cada franja
    queda con una proporción cercana a una hoja normal."""
    ancho, alto = rect.width, rect.height
    if ancho <= 0 or alto <= 0:
        return [rect]
    n = max(1, round((alto / ancho) / 1.3))
    if n == 1:
        return [rect]
    paso = alto / n
    traslape = paso * 0.06
    franjas = []
    for k in range(n):
        y0 = max(rect.y0, rect.y0 + k * paso - traslape)
        y1 = min(rect.y1, rect.y0 + (k + 1) * paso + traslape)
        franjas.append(fitz.Rect(rect.x0, y0, rect.x1, y1))
    return franjas


def extraer_completo(
    ruta_pdf: Path, dpi: int = 170, max_imagenes: int = 24
) -> ContenidoCompleto:
    """Extrae el texto y las imágenes de todas las páginas de un PDF.

    Las páginas altas se dividen en franjas. `max_imagenes` acota el total de
    imágenes generadas (controla el costo de la llamada a la API)."""
    doc = fitz.open(ruta_pdf)
    try:
        if doc.page_count == 0:
            raise ValueError(f"PDF sin páginas: {ruta_pdf}")
        textos: list[str] = []
        imagenes: list[str] = []
        for i in range(doc.page_count):
            pagina = doc.load_page(i)
            textos.append(pagina.get_text("text") or "")
            for clip in _bandas(pagina.rect):
                if len(imagenes) >= max_imagenes:
                    break
                pix = pagina.get_pixmap(dpi=dpi, clip=clip)
                imagenes.append(base64.standard_b64encode(pix.tobytes("png")).decode("ascii"))
            if len(imagenes) >= max_imagenes:
                break
        return ContenidoCompleto(
            texto="\n\n".join(textos), imagenes_b64=imagenes, paginas=doc.page_count
        )
    finally:
        doc.close()
