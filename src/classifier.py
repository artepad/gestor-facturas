"""Clasificación de facturas usando Claude Haiku 4.5 vía API."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from anthropic import Anthropic

from extractor import ContenidoPDF

HERRAMIENTA_FACTURA = {
    "name": "registrar_factura",
    "description": "Registra los datos estructurados extraídos de una factura comercial chilena.",
    "input_schema": {
        "type": "object",
        "properties": {
            "proveedor": {
                "type": "string",
                "description": (
                    "Nombre comercial corto de la MARCA visible en el logo de la factura. "
                    "Usa solo el nombre núcleo de la marca: omite palabras genéricas como "
                    "'Comercial', 'Compañía', 'Distribuidora' o 'Sociedad' "
                    "(ej: si el logo dice 'Comercial CCU', responde 'CCU'). "
                    "Sin tildes, puntos ni sufijos legales (S.A., Ltda., EIRL). "
                    "Sin espacios al inicio/fin. Ejemplos: 'CocaCola', 'Soprole', 'MinutoVerde'. "
                    "Esto se usará como nombre de carpeta, así que evita caracteres especiales."
                ),
            },
            "razon_social": {
                "type": ["string", "null"],
                "description": (
                    "Razón social legal completa del emisor según el SII "
                    "(ej: 'Comercial Santa Elena S.A.'). Null si no es legible."
                ),
            },
            "rut_emisor": {
                "type": ["string", "null"],
                "description": (
                    "RUT del emisor con formato XX.XXX.XXX-X. Es el identificador legal "
                    "único de la empresa: extráelo con máxima precisión, dígito por dígito. "
                    "Null solo si es realmente ilegible."
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
    "Identifica los datos del EMISOR de la factura (NO del cliente que la recibe). "
    "Devuelve TANTO la marca comercial visible en el logo (campo `proveedor`) COMO "
    "la razón social legal y RUT del emisor (campos `razon_social` y `rut_emisor`). "
    "Si la marca y la razón social son la misma empresa, igual repite el nombre en ambos campos.\n\n"
    "El RUT del emisor es el dato MÁS importante: es lo que permite identificar a la "
    "empresa de forma única aunque la marca aparezca escrita de distintas maneras. "
    "Léelo con cuidado dígito por dígito.\n\n"
    "Usa la imagen como fuente de verdad si el texto extraído es confuso o está vacío. "
    "Devuelve los datos llamando a la herramienta `registrar_factura`. "
    "Si algún campo no se puede leer con certeza, devuelve null en ese campo y "
    "menciónalo en `notas`. La confianza debe reflejar honestamente qué tan seguro estás."
)


@dataclass(frozen=True)
class DatosFactura:
    proveedor: str
    fecha: str
    confianza: float
    razon_social: str | None = None
    rut_emisor: str | None = None
    numero_factura: str | None = None
    total: float | None = None
    moneda: str | None = None
    notas: str | None = None

    @classmethod
    def desde_dict(cls, datos: dict[str, Any]) -> "DatosFactura":
        return cls(
            proveedor=datos["proveedor"],
            fecha=datos["fecha"],
            confianza=float(datos["confianza"]),
            razon_social=datos.get("razon_social"),
            rut_emisor=datos.get("rut_emisor"),
            numero_factura=datos.get("numero_factura"),
            total=datos.get("total"),
            moneda=datos.get("moneda"),
            notas=datos.get("notas"),
        )


class Clasificador:
    def __init__(self, modelo: str, cliente: Anthropic | None = None) -> None:
        self.modelo = modelo
        self.cliente = cliente or Anthropic()

    def clasificar(self, contenido: ContenidoPDF) -> DatosFactura:
        contenido_usuario = [
            {
                "type": "image",
                "source": {
                    "type": "base64",
                    "media_type": "image/png",
                    "data": contenido.imagen_b64,
                },
            },
            {
                "type": "text",
                "text": f"Texto extraído del PDF (puede tener errores):\n\n{contenido.texto[:8000]}",
            },
        ]

        respuesta = self.cliente.messages.create(
            model=self.modelo,
            max_tokens=1024,
            system=PROMPT,
            tools=[HERRAMIENTA_FACTURA],
            tool_choice={"type": "tool", "name": "registrar_factura"},
            messages=[{"role": "user", "content": contenido_usuario}],
        )

        for bloque in respuesta.content:
            if bloque.type == "tool_use" and bloque.name == "registrar_factura":
                return DatosFactura.desde_dict(bloque.input)

        raise RuntimeError(f"El modelo no devolvió tool_use. Respuesta: {respuesta}")
