"""Regresion del bug de precios chilenos en el detalle de productos.

Bug: la IA devolvia "18.689" como JSON number 18.689 (decimal) en vez de
18689. Despues el calculo del precio sugerido daba ~$100 en todas las
filas porque se redondeaba al multiplo de 100. Fix: la tool ahora pide
los montos como string y los pasamos por parsear_monto_chileno.
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RAIZ / "src"))

from classifier import DetalleFactura  # noqa: E402
from validacion import parsear_monto_chileno  # noqa: E402


class ParserCLPDetalleTest(unittest.TestCase):
    """Casos reales tomados de la factura MinutoVerde del screenshot."""

    def test_strings_con_punto_de_miles(self):
        self.assertEqual(parsear_monto_chileno("18.689", "CLP"), 18689)
        self.assertEqual(parsear_monto_chileno("13.724", "CLP"), 13724)
        self.assertEqual(parsear_monto_chileno("19.625", "CLP"), 19625)
        self.assertEqual(parsear_monto_chileno("37.378", "CLP"), 37378)
        self.assertEqual(parsear_monto_chileno("1.299", "CLP"), 1299)
        self.assertEqual(parsear_monto_chileno("119.990", "CLP"), 119990)

    def test_strings_con_simbolos(self):
        self.assertEqual(parsear_monto_chileno("$ 18.689", "CLP"), 18689)
        self.assertEqual(parsear_monto_chileno("$18.689", "CLP"), 18689)
        self.assertEqual(parsear_monto_chileno("  18.689  ", "CLP"), 18689)

    def test_floats_corruptos_se_corrigen(self):
        # Si por alguna razon llega un float "18.689", lo escalamos.
        self.assertEqual(parsear_monto_chileno(18.689, "CLP"), 18689)
        self.assertEqual(parsear_monto_chileno(13.724, "CLP"), 13724)
        self.assertEqual(parsear_monto_chileno(1.299, "CLP"), 1299)

    def test_valores_enteros_se_respetan(self):
        # Valores que ya vienen bien no se tocan.
        self.assertEqual(parsear_monto_chileno(584, "CLP"), 584)
        self.assertEqual(parsear_monto_chileno(18689, "CLP"), 18689)
        self.assertEqual(parsear_monto_chileno(584.0, "CLP"), 584)


class DesdeDictTest(unittest.TestCase):
    """Verifica que DetalleFactura.desde_dict normaliza los montos."""

    def test_productos_con_montos_string_chilenos(self):
        # Simula lo que devuelve la IA tras el fix (strings, no numbers)
        datos = {
            "precios_incluyen_iva": False,
            "confianza": 0.9,
            "productos": [
                {
                    "descripcion": "MOLIDA VACUNO KARMAC",
                    "afecto_iva": True,
                    "cantidad": 32,
                    "precio_unitario": "584",
                    "descuento": None,
                    "monto": "18.689",
                },
                {
                    "descripcion": "ARVEJA 30*200",
                    "afecto_iva": True,
                    "cantidad": 30,
                    "precio_unitario": "457",
                    "descuento": "0",
                    "monto": "13.724",
                },
            ],
        }
        det = DetalleFactura.desde_dict(datos)
        self.assertEqual(det.productos[0].monto, 18689)
        self.assertEqual(det.productos[0].precio_unitario, 584)
        self.assertEqual(det.productos[1].monto, 13724)
        self.assertEqual(det.productos[1].cantidad, 30)
        self.assertEqual(det.productos[1].descuento, 0)

    def test_productos_con_floats_corruptos_se_corrigen(self):
        # Si la IA aun mandara floats (datos viejos en cache, etc.), igual los rescatamos
        datos = {
            "precios_incluyen_iva": False,
            "confianza": 0.9,
            "productos": [{
                "descripcion": "X",
                "afecto_iva": True,
                "cantidad": 32,
                "precio_unitario": 0.584,    # decimal corrupto
                "descuento": None,
                "monto": 18.689,             # decimal corrupto
            }],
        }
        det = DetalleFactura.desde_dict(datos)
        self.assertEqual(det.productos[0].monto, 18689)
        # 0.584 < 1000, decimal → *1000 = 584
        self.assertEqual(det.productos[0].precio_unitario, 584)


if __name__ == "__main__":
    unittest.main()
