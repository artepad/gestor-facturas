"""Cálculo del precio de venta sugerido a partir del costo de la factura.

Fórmula: valor unitario → (+ IVA si corresponde) → + margen → redondeo.
La IA determina si los precios de la factura ya incluyen IVA y si cada
producto es afecto o exento; este módulo solo hace la aritmética.
"""

from __future__ import annotations

import math


def valor_unitario(
    monto: float | None, cantidad: float | None, precio_unitario: float | None
) -> float | None:
    """Costo neto por unidad.

    Usa el monto de la línea dividido por la cantidad (así los descuentos ya
    quedan incluidos); si no hay monto, cae al precio unitario."""
    if monto is not None and cantidad is not None and cantidad > 0:
        return monto / cantidad
    return precio_unitario


def precio_sugerido(
    monto: float | None,
    cantidad: float | None,
    precio_unitario: float | None,
    afecto_iva: bool,
    precios_incluyen_iva: bool,
    iva: float,
    margen: float,
    redondear_a: int,
) -> float | None:
    """Precio de venta sugerido para una unidad del producto.

    - Si el producto es afecto a IVA y el precio venía neto, se le suma el IVA.
    - Sobre el costo con IVA se aplica el margen de ganancia.
    - El resultado se redondea hacia arriba al múltiplo indicado.

    Devuelve None si no hay un valor de costo con el que calcular."""
    base = valor_unitario(monto, cantidad, precio_unitario)
    if base is None:
        return None
    costo = base
    if afecto_iva and not precios_incluyen_iva:
        costo *= 1.0 + iva
    bruto = costo * (1.0 + margen)
    if redondear_a and redondear_a > 0:
        return float(math.ceil(bruto / redondear_a) * redondear_a)
    return float(round(bruto))
