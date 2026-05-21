from __future__ import annotations

import sys
import unittest
from datetime import date
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RAIZ / "src"))

from classifier import DatosFactura
from organizer import parsear_fecha
from validacion import parsear_monto_chileno, validar_datos_factura


class ValidacionFacturaTest(unittest.TestCase):
    def test_parsea_monto_chileno_con_punto_de_miles(self) -> None:
        self.assertEqual(parsear_monto_chileno("221.713", "CLP"), 221713)
        self.assertEqual(parsear_monto_chileno("$ 1.234.567", "CLP"), 1234567)
        self.assertEqual(parsear_monto_chileno("221.713,00", "CLP"), 221713)

    def test_corrige_numero_decimal_que_debia_ser_miles(self) -> None:
        self.assertEqual(parsear_monto_chileno(221.713, "CLP"), 221713)
        self.assertEqual(parsear_monto_chileno(12.8, "CLP"), 12800)
        self.assertEqual(parsear_monto_chileno(178.51, "CLP"), 178510)

    def test_advierte_monto_clp_entero_sospechosamente_bajo(self) -> None:
        datos = DatosFactura(
            proveedor="Proveedor",
            fecha="13-01-2026",
            confianza=0.95,
            total=13,
            moneda="CLP",
        )

        resultado = validar_datos_factura(datos, hoy=date(2026, 5, 21))

        self.assertTrue(resultado.ok)
        self.assertIn("Monto CLP sospechosamente bajo", resultado.advertencias[0])

    def test_rechaza_fecha_futura(self) -> None:
        datos = DatosFactura(
            proveedor="Proveedor",
            fecha="27-02-2028",
            confianza=0.95,
            total="221.713",
            moneda="CLP",
        )

        resultado = validar_datos_factura(datos, hoy=date(2026, 5, 21))

        self.assertFalse(resultado.ok)
        self.assertIn("Fecha de emisión futura", resultado.errores[0])
        self.assertEqual(resultado.datos.total, 221713)

    def test_acepta_fecha_real_y_normaliza_total(self) -> None:
        datos = DatosFactura(
            proveedor="Proveedor",
            fecha="27/02/2026",
            confianza=0.95,
            total="221.713",
            moneda="CLP",
        )

        resultado = validar_datos_factura(datos, hoy=date(2026, 5, 21))

        self.assertTrue(resultado.ok)
        self.assertEqual(resultado.datos.fecha, "27-02-2026")
        self.assertEqual(resultado.datos.total, 221713)

    def test_archivador_no_acepta_fecha_futura(self) -> None:
        with self.assertRaises(ValueError):
            parsear_fecha("27-02-2028")


if __name__ == "__main__":
    unittest.main()
