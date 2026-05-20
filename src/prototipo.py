"""
Prototipo Fase 1: extracción de datos de facturas con Claude Haiku 4.5.

Uso:
    py src/prototipo.py                          # procesa todos los PDFs en tests/facturas_ejemplo/
    py src/prototipo.py ruta/a/factura.pdf       # procesa un PDF específico
"""

from __future__ import annotations

import base64
import json
import os
import sys
import time
from pathlib import Path

import fitz  # PyMuPDF
from anthropic import Anthropic
from dotenv import load_dotenv

RAIZ = Path(__file__).resolve().parent.parent
CARPETA_EJEMPLOS = RAIZ / "tests" / "facturas_ejemplo"
MODELO = "claude-haiku-4-5-20251001"

HERRAMIENTA_FACTURA = {
    "name": "registrar_factura",
    "description": "Registra los datos estructurados extraídos de una factura comercial.",
    "input_schema": {
        "type": "object",
        "properties": {
            "proveedor": {
                "type": "string",
                "description": (
                    "Nombre comercial corto del proveedor que EMITE la factura "
                    "(no el cliente). Sin tildes, puntos ni S.A./Ltda. "
                    "Ejemplos: 'CocaCola', 'Soprole', 'Nestle'."
                ),
            },
            "fecha": {
                "type": "string",
                "description": "Fecha de emisión en formato DD-MM-YYYY. Null si no es legible.",
            },
            "numero_factura": {
                "type": ["string", "null"],
                "description": "Número o folio de la factura. Null si no es legible.",
            },
            "total": {
                "type": ["number", "null"],
                "description": "Monto total a pagar, solo número sin símbolos ni puntos de miles.",
            },
            "moneda": {
                "type": ["string", "null"],
                "description": "Código de moneda: CLP, USD, EUR, etc.",
            },
            "confianza": {
                "type": "number",
                "description": "Nivel de certeza global de 0.0 a 1.0.",
            },
            "notas": {
                "type": ["string", "null"],
                "description": "Observaciones si algo es dudoso o ilegible.",
            },
        },
        "required": ["proveedor", "fecha", "confianza"],
    },
}

PROMPT = (
    "Eres un asistente experto en extraer datos de facturas comerciales chilenas. "
    "Te entrego el texto extraído del PDF (puede contener errores de OCR) y la imagen "
    "de la primera página de la factura.\n\n"
    "Identifica los datos del PROVEEDOR que emite la factura (NO del cliente que la recibe). "
    "Usa la imagen como fuente de verdad si el texto extraído es confuso o está vacío.\n\n"
    "Devuelve los datos llamando a la herramienta `registrar_factura`. "
    "Si algún campo no se puede leer con certeza, devuelve null en ese campo y "
    "menciónalo en `notas`. La confianza debe reflejar honestamente qué tan seguro estás."
)


def extraer_texto_y_imagen(ruta_pdf: Path) -> tuple[str, str]:
    """Devuelve (texto_extraido, imagen_base64_png) de la primera página."""
    doc = fitz.open(ruta_pdf)
    try:
        if doc.page_count == 0:
            raise ValueError(f"PDF sin páginas: {ruta_pdf}")
        pagina = doc.load_page(0)
        texto = pagina.get_text("text") or ""
        # Renderizar a 150 DPI — buen balance calidad/tamaño para Claude
        pix = pagina.get_pixmap(dpi=150)
        png_bytes = pix.tobytes("png")
        imagen_b64 = base64.standard_b64encode(png_bytes).decode("ascii")
        return texto, imagen_b64
    finally:
        doc.close()


def analizar_factura(cliente: Anthropic, ruta_pdf: Path) -> dict:
    texto, imagen_b64 = extraer_texto_y_imagen(ruta_pdf)

    contenido_usuario = [
        {
            "type": "image",
            "source": {
                "type": "base64",
                "media_type": "image/png",
                "data": imagen_b64,
            },
        },
        {
            "type": "text",
            "text": f"Texto extraído del PDF (puede tener errores):\n\n{texto[:8000]}",
        },
    ]

    respuesta = cliente.messages.create(
        model=MODELO,
        max_tokens=1024,
        system=PROMPT,
        tools=[HERRAMIENTA_FACTURA],
        tool_choice={"type": "tool", "name": "registrar_factura"},
        messages=[{"role": "user", "content": contenido_usuario}],
    )

    for bloque in respuesta.content:
        if bloque.type == "tool_use" and bloque.name == "registrar_factura":
            return bloque.input

    raise RuntimeError(f"El modelo no devolvió tool_use. Respuesta: {respuesta}")


def listar_pdfs(argv: list[str]) -> list[Path]:
    if len(argv) > 1:
        return [Path(argv[1]).resolve()]
    pdfs = sorted(CARPETA_EJEMPLOS.glob("*.pdf"))
    if not pdfs:
        print(f"[!] No hay PDFs en {CARPETA_EJEMPLOS}")
        print("    Copia 3-5 facturas ahí y vuelve a ejecutar.")
        sys.exit(1)
    return pdfs


def main() -> None:
    load_dotenv(RAIZ / ".env", override=True)
    if not os.getenv("ANTHROPIC_API_KEY"):
        print("[!] Falta ANTHROPIC_API_KEY en el archivo .env")
        print(f"    Copia .env.ejemplo a .env y pega tu key.")
        sys.exit(1)

    cliente = Anthropic()
    pdfs = listar_pdfs(sys.argv)

    print(f"Procesando {len(pdfs)} factura(s) con {MODELO}\n")
    resultados = []
    for ruta in pdfs:
        print(f"--- {ruta.name} ---")
        t0 = time.perf_counter()
        try:
            datos = analizar_factura(cliente, ruta)
            dt = time.perf_counter() - t0
            print(json.dumps(datos, indent=2, ensure_ascii=False))
            print(f"(tiempo: {dt:.1f}s)\n")
            resultados.append({"archivo": ruta.name, "ok": True, "datos": datos})
        except Exception as exc:
            print(f"[ERROR] {exc}\n")
            resultados.append({"archivo": ruta.name, "ok": False, "error": str(exc)})

    exitosos = sum(1 for r in resultados if r["ok"])
    print(f"=== Resumen: {exitosos}/{len(resultados)} procesadas correctamente ===")


if __name__ == "__main__":
    main()
