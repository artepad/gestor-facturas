"""Tests del módulo de respaldo: exportar, verificar e importar.

Crea un entorno temporal con BD + PDFs falsos, hace un ciclo completo
(exportar → importar en otra ubicación) y verifica integridad.
"""

from __future__ import annotations

import sqlite3
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RAIZ / "src"))

from respaldo import (  # noqa: E402
    TIPO_RESPALDO,
    VERSION_ESQUEMA_RESPALDO,
    bloquear_procesamiento,
    carpeta_respaldos_automaticos,
    exportar,
    importar,
    leer_manifiesto,
    limpiar_respaldos_automaticos,
    procesamiento_bloqueado,
    ruta_marcador,
    verificar_zip,
)


def _crear_entorno(base: Path) -> tuple[dict, Path, Path]:
    """Arma un proyecto falso con BD, PDFs, config y .env. Devuelve
    (config_dict, ruta_config_yaml, ruta_env)."""
    archivo = base / "Facturas"
    archivo.mkdir()
    (archivo / "_entrada").mkdir()
    (archivo / "_revisar").mkdir()
    (archivo / "2026" / "Mayo" / "CCU").mkdir(parents=True)

    # PDFs falsos: solo bytes, no importa que no sean PDFs reales
    (archivo / "2026" / "Mayo" / "CCU" / "factura_01-05-2026.pdf").write_bytes(b"%PDF-1\n1")
    (archivo / "2026" / "Mayo" / "CCU" / "factura_02-05-2026.pdf").write_bytes(b"%PDF-1\n2")
    (archivo / "_revisar" / "rara.pdf").write_bytes(b"%PDF-1\n3")
    # PDF en _entrada NO se debe respaldar
    (archivo / "_entrada" / "transitoria.pdf").write_bytes(b"%PDF-1\nX")

    # BD mínima con la tabla facturas y un par de filas
    data_dir = base / "data"
    data_dir.mkdir()
    ruta_bd = data_dir / "facturas.db"
    cnx = sqlite3.connect(str(ruta_bd))
    try:
        cnx.execute("CREATE TABLE facturas (id INTEGER PRIMARY KEY, n TEXT)")
        cnx.execute("CREATE TABLE alias_proveedor (a TEXT PRIMARY KEY, b TEXT)")
        cnx.executemany("INSERT INTO facturas (n) VALUES (?)",
                        [("uno",), ("dos",), ("tres",)])
        cnx.commit()
    finally:
        cnx.close()

    ruta_config = base / "config.yaml"
    ruta_config.write_text("config: original\n", encoding="utf-8")
    ruta_env = base / ".env"
    ruta_env.write_text("ANTHROPIC_API_KEY=sk-fake\n", encoding="utf-8")

    config = {
        "rutas": {
            "archivo": str(archivo),
            "entrada": str(archivo / "_entrada"),
            "revisar": str(archivo / "_revisar"),
            "base_datos": str(ruta_bd),
        }
    }
    return config, ruta_config, ruta_env


class RespaldoTests(unittest.TestCase):

    def test_exportar_genera_zip_valido(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            config, ruta_config, ruta_env = _crear_entorno(base)
            destino = base / "respaldos"

            res = exportar(config, destino,
                           incluir_api_key=False,
                           nombre_negocio="Negocio Test",
                           ruta_config_yaml=ruta_config,
                           ruta_env=ruta_env)

            self.assertTrue(res.ruta_zip.exists())
            self.assertGreater(res.tamano_bytes, 0)
            self.assertEqual(res.conteos["pdfs"], 3)  # _entrada excluido
            self.assertEqual(res.conteos["facturas"], 3)
            self.assertFalse(res.incluye_api_key)

            # Verificar contenido del zip
            with zipfile.ZipFile(res.ruta_zip) as zf:
                nombres = zf.namelist()
            self.assertIn("manifiesto.json", nombres)
            self.assertIn("data/facturas.db", nombres)
            self.assertIn("config/config.yaml", nombres)
            self.assertNotIn("config/.env", nombres)
            # _entrada excluida del archivo
            self.assertFalse(any("_entrada/" in n for n in nombres))

    def test_exportar_con_api_key_la_incluye(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            config, ruta_config, ruta_env = _crear_entorno(base)
            destino = base / "respaldos"

            res = exportar(config, destino,
                           incluir_api_key=True,
                           ruta_config_yaml=ruta_config,
                           ruta_env=ruta_env)

            self.assertTrue(res.incluye_api_key)
            self.assertIn("_con_apikey", res.ruta_zip.name)
            with zipfile.ZipFile(res.ruta_zip) as zf:
                self.assertIn("config/.env", zf.namelist())

    def test_manifiesto_tiene_campos_esperados(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            config, ruta_config, ruta_env = _crear_entorno(base)
            res = exportar(config, base, nombre_negocio="X",
                           ruta_config_yaml=ruta_config, ruta_env=ruta_env)
            m = leer_manifiesto(res.ruta_zip)
            self.assertEqual(m.tipo, TIPO_RESPALDO)
            self.assertEqual(m.version_esquema_respaldo, VERSION_ESQUEMA_RESPALDO)
            self.assertEqual(m.negocio, "X")
            self.assertIn("data/facturas.db", m.hashes_sha256)

    def test_verificar_detecta_zip_corrupto(self):
        with tempfile.TemporaryDirectory() as tmp:
            ruta = Path(tmp) / "malo.zip"
            ruta.write_bytes(b"esto no es un zip")
            with self.assertRaises(ValueError):
                verificar_zip(ruta)

    def test_verificar_detecta_hash_alterado(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            config, ruta_config, ruta_env = _crear_entorno(base)
            res = exportar(config, base,
                           ruta_config_yaml=ruta_config, ruta_env=ruta_env)
            # Alterar la BD dentro del zip → reescribir el zip con BD modificada
            # pero manifiesto original. zipfile no permite editar, así que
            # creamos uno nuevo copiando y reemplazando una entrada.
            zip_alterado = base / "alterado.zip"
            with zipfile.ZipFile(res.ruta_zip, "r") as src, \
                 zipfile.ZipFile(zip_alterado, "w", zipfile.ZIP_DEFLATED) as dst:
                for item in src.namelist():
                    if item == "data/facturas.db":
                        dst.writestr(item, b"BD FALSA")
                    else:
                        dst.writestr(item, src.read(item))
            with self.assertRaises(ValueError):
                verificar_zip(zip_alterado)

    def test_ciclo_completo_exportar_importar(self):
        with tempfile.TemporaryDirectory() as tmp:
            origen = Path(tmp) / "origen"
            origen.mkdir()
            config_origen, cfg_o, env_o = _crear_entorno(origen)

            destino_zip = origen / "respaldos"
            res = exportar(config_origen, destino_zip,
                           ruta_config_yaml=cfg_o, ruta_env=env_o)

            # PC destino con rutas distintas
            destino = Path(tmp) / "destino"
            destino.mkdir()
            archivo_dest = destino / "MisFacturas"
            bd_dest = destino / "data" / "facturas.db"
            config_dest = {"rutas": {
                "archivo": str(archivo_dest),
                "base_datos": str(bd_dest),
            }}
            cfg_d = destino / "config.yaml"
            env_d = destino / ".env"
            cfg_d.write_text("config: destino\n", encoding="utf-8")

            m = importar(res.ruta_zip, config_dest,
                         ruta_config_yaml_local=cfg_d, ruta_env_local=env_d)

            # BD restaurada en la ubicación del destino
            self.assertTrue(bd_dest.exists())
            cnx_d = sqlite3.connect(str(bd_dest))
            try:
                n = cnx_d.execute("SELECT COUNT(*) FROM facturas").fetchone()[0]
            finally:
                cnx_d.close()
            self.assertEqual(n, 3)

            # PDFs restaurados respetando estructura
            self.assertTrue((archivo_dest / "2026" / "Mayo" / "CCU" /
                             "factura_01-05-2026.pdf").exists())
            self.assertTrue((archivo_dest / "_revisar" / "rara.pdf").exists())

            # config.yaml local NO fue pisado por default
            self.assertEqual(cfg_d.read_text(encoding="utf-8"),
                             "config: destino\n")
            self.assertEqual(m.conteos["facturas"], 3)


class MarcadorTests(unittest.TestCase):

    def test_marcador_se_crea_y_se_borra(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            config, _, _ = _crear_entorno(base)
            self.assertFalse(procesamiento_bloqueado(config))
            with bloquear_procesamiento(config):
                self.assertTrue(ruta_marcador(config).exists())
                self.assertTrue(procesamiento_bloqueado(config))
            self.assertFalse(ruta_marcador(config).exists())
            self.assertFalse(procesamiento_bloqueado(config))

    def test_limpiar_respaldos_automaticos_conserva_los_recientes(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            config, _, _ = _crear_entorno(base)
            carpeta = carpeta_respaldos_automaticos(config)
            carpeta.mkdir(parents=True)
            # Crea 7 zips con mtimes distintos
            for i in range(7):
                z = carpeta / f"respaldo_AUTO_neg_2026-01-0{i}_120000.zip"
                z.write_bytes(b"x")
                import os
                os.utime(z, (1000 + i, 1000 + i))
            borrados = limpiar_respaldos_automaticos(config, conservar=5)
            self.assertEqual(borrados, 2)
            quedan = sorted(carpeta.glob("respaldo_AUTO_*.zip"))
            self.assertEqual(len(quedan), 5)

    def test_marcador_huerfano_se_ignora(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            config, _, _ = _crear_entorno(base)
            ruta_marcador(config).touch()
            # max_segundos=0 → cualquier marcador se considera huérfano
            self.assertFalse(procesamiento_bloqueado(config, max_segundos=0))
            self.assertFalse(ruta_marcador(config).exists())


if __name__ == "__main__":
    unittest.main()
