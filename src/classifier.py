"""Clasificación de facturas usando Claude Haiku 4.5 vía API."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from anthropic import Anthropic

from extractor import ContenidoCompleto, ContenidoPDF

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


HERRAMIENTA_DETALLE = {
    "name": "registrar_detalle_factura",
    "description": "Registra el detalle, línea por línea, de los productos de una factura.",
    "input_schema": {
        "type": "object",
        "properties": {
            "precios_incluyen_iva": {
                "type": "boolean",
                "description": (
                    "true si los precios unitarios del detalle YA incluyen el IVA. "
                    "false si son precios netos (sin IVA), que es lo habitual en las "
                    "facturas chilenas, donde el IVA se suma como una línea aparte."
                ),
            },
            "productos": {
                "type": "array",
                "description": "Un elemento por cada producto o ítem facturado, en orden.",
                "items": {
                    "type": "object",
                    "properties": {
                        "descripcion": {
                            "type": "string",
                            "description": "Nombre o descripción del producto.",
                        },
                        "cantidad": {
                            "type": ["number", "null"],
                            "description": "Unidades facturadas. Null si no aparece.",
                        },
                        "precio_unitario": {
                            "type": ["number", "null"],
                            "description": (
                                "Precio por unidad, solo el número sin símbolos ni "
                                "puntos de miles. Null si no aparece."
                            ),
                        },
                        "descuento": {
                            "type": ["number", "null"],
                            "description": (
                                "Monto del descuento de la línea EN PESOS (no porcentaje). "
                                "0 o null si la línea no tiene descuento."
                            ),
                        },
                        "monto": {
                            "type": ["number", "null"],
                            "description": "Monto total de la línea (cantidad x precio - descuento).",
                        },
                        "afecto_iva": {
                            "type": "boolean",
                            "description": "false solo si el producto es exento de IVA; true en el resto.",
                        },
                    },
                    "required": ["descripcion", "afecto_iva"],
                },
            },
            "confianza": {
                "type": "number",
                "description": "Certeza global de la extracción del detalle, de 0.0 a 1.0.",
            },
            "notas": {
                "type": ["string", "null"],
                "description": "Observaciones si el formato es ambiguo o algo quedó dudoso.",
            },
        },
        "required": ["precios_incluyen_iva", "productos", "confianza"],
    },
}

PROMPT_DETALLE = (
    "Eres un asistente experto en leer facturas comerciales chilenas. "
    "Te entrego las imágenes de una factura (una factura larga puede venir "
    "dividida en varias franjas) y el texto extraído del PDF.\n\n"
    "Extrae el DETALLE línea por línea: cada producto o ítem facturado con su "
    "descripción, cantidad, precio unitario, descuento y monto de la línea.\n\n"
    "Cada proveedor usa un formato distinto: interpreta la estructura de esta "
    "factura en particular y ubica bien las columnas antes de extraer los valores.\n\n"
    "Determina si los precios del detalle vienen SIN IVA (netos, lo habitual en "
    "las facturas chilenas) o si YA INCLUYEN el IVA, y repórtalo en "
    "`precios_incluyen_iva`. Marca `afecto_iva` en false solo para los productos "
    "exentos de IVA.\n\n"
    "Incluye SOLO productos o ítems reales. NO incluyas filas de subtotal, "
    "neto, IVA, total, ni datos de despacho o transporte. "
    "Si una factura larga viene en franjas, no repitas un producto que aparezca "
    "en el traslape entre dos franjas.\n\n"
    "Devuelve los datos llamando a la herramienta `registrar_detalle_factura`. "
    "La confianza debe reflejar honestamente qué tan seguro estás de la extracción."
)


@dataclass(frozen=True)
class ProductoFactura:
    descripcion: str
    afecto_iva: bool = True
    cantidad: float | None = None
    precio_unitario: float | None = None
    descuento: float | None = None
    monto: float | None = None


@dataclass(frozen=True)
class DetalleFactura:
    productos: tuple[ProductoFactura, ...]
    precios_incluyen_iva: bool
    confianza: float
    notas: str | None = None

    @classmethod
    def desde_dict(cls, datos: dict[str, Any]) -> "DetalleFactura":
        productos = tuple(
            ProductoFactura(
                descripcion=(p.get("descripcion") or "").strip(),
                afecto_iva=bool(p.get("afecto_iva", True)),
                cantidad=p.get("cantidad"),
                precio_unitario=p.get("precio_unitario"),
                descuento=p.get("descuento"),
                monto=p.get("monto"),
            )
            for p in datos.get("productos", [])
        )
        return cls(
            productos=productos,
            precios_incluyen_iva=bool(datos.get("precios_incluyen_iva", False)),
            confianza=float(datos.get("confianza", 0.0)),
            notas=datos.get("notas"),
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

    def extraer_detalle(
        self, contenido: ContenidoCompleto, instrucciones: str | None = None
    ) -> DetalleFactura:
        """Extrae el detalle de productos de una factura (todas sus páginas).

        `instrucciones` es una pista opcional del usuario para facturas de
        formato complejo o ambiguo."""
        bloques: list[dict[str, Any]] = [
            {
                "type": "image",
                "source": {"type": "base64", "media_type": "image/png", "data": img},
            }
            for img in contenido.imagenes_b64
        ]
        texto = contenido.texto.strip()
        if texto:
            bloques.append({
                "type": "text",
                "text": f"Texto extraído del PDF (puede tener errores):\n\n{texto[:12000]}",
            })
        if instrucciones:
            bloques.append({
                "type": "text",
                "text": f"Instrucciones adicionales del usuario para esta factura:\n{instrucciones}",
            })

        respuesta = self.cliente.messages.create(
            model=self.modelo,
            max_tokens=8192,
            system=PROMPT_DETALLE,
            tools=[HERRAMIENTA_DETALLE],
            tool_choice={"type": "tool", "name": "registrar_detalle_factura"},
            messages=[{"role": "user", "content": bloques}],
        )

        for bloque in respuesta.content:
            if bloque.type == "tool_use" and bloque.name == "registrar_detalle_factura":
                return DetalleFactura.desde_dict(bloque.input)

        raise RuntimeError(f"El modelo no devolvió tool_use. Respuesta: {respuesta}")
