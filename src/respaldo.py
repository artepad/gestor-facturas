"""Respaldo y restauración del sistema en un único archivo .zip.

Empaqueta en un ZIP toda la información necesaria para restaurar el programa
en otro PC: base de datos SQLite (copia atómica), config.yaml, opcionalmente
el .env con la API key, y todos los PDFs del archivo de facturas.

Diseño:
- Sin dependencias de Tkinter ni del watcher: este módulo es lógica pura.
- El llamador (UI) es responsable de pausar el watcher antes de exportar e
  importar, y de mostrar progreso/errores al usuario.
- El manifiesto.json es la fuente de verdad: versión, hashes, conteos, fecha.
- La restauración usa el config.yaml LOCAL del PC destino para saber dónde
  poner los PDFs (la letra de unidad puede ser distinta entre PCs).
"""

from __future__ import annotations

import hashlib
import json
import os
import shutil
import sqlite3
import tempfile
import time
import zipfile
from collections.abc import Callable, Iterable
from contextlib import contextmanager
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path

import yaml

from version import __version__


# Versión del esquema del RESPALDO (no del programa). Incrementar cuando
# cambie la estructura del .zip o el manifiesto de forma incompatible.
VERSION_ESQUEMA_RESPALDO = 1

# Versión del esquema de la BASE DE DATOS. Incrementar cuando se haga una
# migración no compatible hacia atrás en db.py.
VERSION_ESQUEMA_BD = 1

TIPO_RESPALDO = "gestor_facturas_respaldo"

# Carpetas dentro del archivo que SÍ se respaldan, además de la estructura
# AÑO/Mes/Marca. _entrada se excluye (cola transitoria); logs nunca se
# incluyen (no aportan a la restauración).
CARPETAS_ESPECIALES = ("_revisar", "_no_facturas", "_reemplazadas", "_errores")

# Nombre del archivo marcador usado para que el watcher (otro proceso) se
# entere de que hay un respaldo/restauración en curso y NO procese facturas
# mientras tanto. Vive junto a la base de datos para que ambos procesos lo
# encuentren sin configuración adicional.
NOMBRE_MARCADOR = ".respaldo_en_progreso"


def ruta_marcador(config: dict) -> Path:
    """Ubicación del archivo marcador para este proyecto."""
    return Path(config["rutas"]["base_datos"]).parent / NOMBRE_MARCADOR


def procesamiento_bloqueado(config: dict, max_segundos: int = 3600) -> bool:
    """True si el marcador está presente y vigente.

    Si el marcador es muy viejo (> max_segundos) lo considera huérfano y lo
    ignora — evita que un crash deje el watcher bloqueado para siempre.
    """
    ruta = ruta_marcador(config)
    if not ruta.exists():
        return False
    try:
        edad = time.time() - ruta.stat().st_mtime
    except OSError:
        return False
    if edad > max_segundos:
        try:
            ruta.unlink()
        except OSError:
            pass
        return False
    return True


def carpeta_respaldos_automaticos(config: dict) -> Path:
    """Carpeta donde van los respaldos AUTO generados antes de cada importación.

    Vive junto a la BD para que sea independiente del config local — al
    restaurar en otro PC con rutas distintas, igual queda en un lugar
    encontrable.
    """
    return Path(config["rutas"]["base_datos"]).parent / "respaldos_automaticos"


def limpiar_respaldos_automaticos(config: dict, conservar: int = 5) -> int:
    """Conserva los `conservar` zips más recientes en la carpeta auto, borra
    el resto. Devuelve cuántos archivos se borraron."""
    carpeta = carpeta_respaldos_automaticos(config)
    if not carpeta.exists():
        return 0
    zips = sorted(carpeta.glob("respaldo_AUTO_*.zip"),
                  key=lambda p: p.stat().st_mtime, reverse=True)
    borrados = 0
    for viejo in zips[conservar:]:
        try:
            viejo.unlink()
            borrados += 1
        except OSError:
            pass
    return borrados


@contextmanager
def bloquear_procesamiento(config: dict):
    """Context manager: crea el marcador al entrar y lo borra al salir.

    Úsalo alrededor de exportar/importar para que el watcher (que corre en
    otro proceso en modo bandeja) sepa que no debe tocar la BD ni los PDFs.
    """
    ruta = ruta_marcador(config)
    ruta.parent.mkdir(parents=True, exist_ok=True)
    try:
        with open(ruta, "w", encoding="utf-8") as f:
            f.write(f"pid={os.getpid()}\nts={datetime.now().isoformat()}\n")
        yield ruta
    finally:
        try:
            ruta.unlink()
        except OSError:
            pass


@dataclass
class ProgresoRespaldo:
    """Estado de progreso reportado durante exportar/importar."""

    paso: str = ""
    actual: int = 0
    total: int = 0


@dataclass
class ResultadoExportacion:
    ruta_zip: Path
    tamano_bytes: int
    conteos: dict[str, int]
    incluye_api_key: bool


@dataclass
class Manifiesto:
    tipo: str = TIPO_RESPALDO
    version_programa: str = __version__
    version_esquema_respaldo: int = VERSION_ESQUEMA_RESPALDO
    version_esquema_bd: int = VERSION_ESQUEMA_BD
    fecha_respaldo: str = ""
    negocio: str = ""
    incluye_api_key: bool = False
    conteos: dict[str, int] = field(default_factory=dict)
    hashes_sha256: dict[str, str] = field(default_factory=dict)
    tamano_mb: float = 0.0

    def to_json(self) -> str:
        return json.dumps(self.__dict__, indent=2, ensure_ascii=False)

    @classmethod
    def from_json(cls, datos: str) -> "Manifiesto":
        d = json.loads(datos)
        return cls(**d)


# --- utilidades internas ---

def _sha256(ruta: Path) -> str:
    h = hashlib.sha256()
    with open(ruta, "rb") as f:
        for bloque in iter(lambda: f.read(65536), b""):
            h.update(bloque)
    return h.hexdigest()


def _copiar_bd_atomica(origen: Path, destino: Path) -> None:
    """Copia segura de SQLite usando la API oficial de backup.

    `shutil.copy` puede capturar la BD a mitad de una transacción y dejar
    un archivo inválido. `sqlite3.backup` espera a un punto consistente.
    """
    src = sqlite3.connect(str(origen))
    try:
        dst = sqlite3.connect(str(destino))
        try:
            src.backup(dst)
        finally:
            dst.close()
    finally:
        src.close()


def _listar_pdfs_archivo(raiz: Path) -> list[Path]:
    """Todos los .pdf bajo la raíz del archivo de facturas, recursivo."""
    if not raiz.exists():
        return []
    return sorted(raiz.rglob("*.pdf"))


def _ruta_relativa_facturas(pdf: Path, raiz_archivo: Path) -> str:
    """Ruta del PDF relativa a la raíz, usando barras /, lista para ZIP."""
    return pdf.relative_to(raiz_archivo).as_posix()


def _reportar(callback: Callable[[ProgresoRespaldo], None] | None,
              paso: str, actual: int = 0, total: int = 0) -> None:
    if callback is not None:
        callback(ProgresoRespaldo(paso=paso, actual=actual, total=total))


# --- API pública ---

def exportar(
    config: dict,
    ruta_destino: Path,
    *,
    incluir_api_key: bool = False,
    nombre_negocio: str = "",
    ruta_env: Path | None = None,
    ruta_config_yaml: Path | None = None,
    progreso: Callable[[ProgresoRespaldo], None] | None = None,
) -> ResultadoExportacion:
    """Genera un .zip de respaldo en `ruta_destino` (carpeta) y lo verifica.

    Parámetros:
      config: dict ya parseado de config.yaml (necesitamos las rutas).
      ruta_destino: carpeta donde crear el .zip.
      incluir_api_key: si True, copia el .env adentro.
      nombre_negocio: etiqueta libre para el manifiesto y nombre del archivo.
      ruta_env / ruta_config_yaml: ubicaciones de esos archivos en este PC.
        Si no se pasan, se intenta inferir desde el cwd.
      progreso: callback opcional para reportar avance a la UI.

    Devuelve el ResultadoExportacion. Lanza ValueError/IOError si algo falla.
    """
    ruta_destino = Path(ruta_destino)
    ruta_destino.mkdir(parents=True, exist_ok=True)

    rutas = config.get("rutas", {})
    raiz_archivo = Path(rutas.get("archivo", ""))
    ruta_bd = Path(rutas.get("base_datos", ""))
    if not ruta_bd.exists():
        raise ValueError(f"Base de datos no encontrada: {ruta_bd}")
    if not raiz_archivo.exists():
        raise ValueError(f"Carpeta de facturas no encontrada: {raiz_archivo}")

    if ruta_config_yaml is None:
        ruta_config_yaml = Path.cwd() / "config.yaml"
    if ruta_env is None:
        ruta_env = Path.cwd() / ".env"

    timestamp = datetime.now().strftime("%Y-%m-%d_%H%M%S")
    sufijo = "_con_apikey" if incluir_api_key else ""
    slug = (nombre_negocio or "respaldo").strip().replace(" ", "_") or "respaldo"
    nombre_zip = f"respaldo_{slug}_{timestamp}{sufijo}.zip"
    ruta_zip = ruta_destino / nombre_zip

    # 1. Copiar BD a un temporal (atomic) y calcular hash
    _reportar(progreso, "Copiando base de datos…")
    with tempfile.TemporaryDirectory() as tmp:
        tmp_dir = Path(tmp)
        bd_copia = tmp_dir / "facturas.db"
        _copiar_bd_atomica(ruta_bd, bd_copia)

        # 2. Reunir lista de PDFs a respaldar
        _reportar(progreso, "Buscando PDFs…")
        pdfs = _listar_pdfs_archivo(raiz_archivo)
        # _entrada se excluye explícitamente (cola transitoria)
        ruta_entrada = Path(rutas.get("entrada", ""))
        if ruta_entrada.exists():
            try:
                ruta_entrada_resuelta = ruta_entrada.resolve()
                pdfs = [p for p in pdfs
                        if ruta_entrada_resuelta not in p.resolve().parents]
            except OSError:
                pass

        # 3. Construir manifiesto
        hashes: dict[str, str] = {"data/facturas.db": _sha256(bd_copia)}
        if ruta_config_yaml.exists():
            hashes["config/config.yaml"] = _sha256(ruta_config_yaml)
        if incluir_api_key and ruta_env.exists():
            hashes["config/.env"] = _sha256(ruta_env)

        conteos = {"pdfs": len(pdfs)}
        # Conteo de facturas en la BD (informativo, no es validación dura).
        # Importante en Windows: cerrar la conexión explícitamente para que
        # se libere el archivo antes de moverlo al ZIP.
        cnx = sqlite3.connect(str(bd_copia))
        try:
            conteos["facturas"] = cnx.execute(
                "SELECT COUNT(*) FROM facturas").fetchone()[0]
            try:
                conteos["alias"] = cnx.execute(
                    "SELECT COUNT(*) FROM alias_proveedor").fetchone()[0]
            except sqlite3.Error:
                pass
        except sqlite3.Error:
            pass
        finally:
            cnx.close()

        manifiesto = Manifiesto(
            fecha_respaldo=datetime.now().isoformat(timespec="seconds"),
            negocio=nombre_negocio,
            incluye_api_key=incluir_api_key,
            conteos=conteos,
            hashes_sha256=hashes,
        )

        # 4. Escribir el ZIP
        _reportar(progreso, "Empaquetando archivos…", 0, len(pdfs))
        with zipfile.ZipFile(ruta_zip, "w", zipfile.ZIP_DEFLATED) as zf:
            zf.writestr("manifiesto.json", manifiesto.to_json())
            zf.write(bd_copia, "data/facturas.db")
            if ruta_config_yaml.exists():
                zf.write(ruta_config_yaml, "config/config.yaml")
            if incluir_api_key and ruta_env.exists():
                zf.write(ruta_env, "config/.env")
            for i, pdf in enumerate(pdfs, start=1):
                rel = _ruta_relativa_facturas(pdf, raiz_archivo)
                zf.write(pdf, f"facturas/{rel}")
                if i % 10 == 0 or i == len(pdfs):
                    _reportar(progreso, "Empaquetando archivos…", i, len(pdfs))

    # 5. Actualizar tamaño en el manifiesto del zip (re-empaquetar la entrada
    # sería costoso; lo dejamos como informativo en el ResultadoExportacion).
    tamano = ruta_zip.stat().st_size

    # 6. Verificar el ZIP recién creado
    _reportar(progreso, "Verificando respaldo…")
    verificar_zip(ruta_zip)

    return ResultadoExportacion(
        ruta_zip=ruta_zip,
        tamano_bytes=tamano,
        conteos=conteos,
        incluye_api_key=incluir_api_key,
    )


def leer_manifiesto(ruta_zip: Path) -> Manifiesto:
    """Abre el ZIP y devuelve el manifiesto. Lanza ValueError si no es válido."""
    try:
        with zipfile.ZipFile(ruta_zip, "r") as zf:
            if "manifiesto.json" not in zf.namelist():
                raise ValueError("El archivo no contiene manifiesto.json — "
                                 "no es un respaldo válido del gestor.")
            datos = zf.read("manifiesto.json").decode("utf-8")
    except zipfile.BadZipFile as exc:
        raise ValueError(f"El archivo no es un ZIP válido: {exc}") from exc
    m = Manifiesto.from_json(datos)
    if m.tipo != TIPO_RESPALDO:
        raise ValueError(f"Tipo de respaldo desconocido: {m.tipo!r}")
    return m


def verificar_zip(ruta_zip: Path) -> Manifiesto:
    """Lee el manifiesto y comprueba los hashes de los archivos clave."""
    m = leer_manifiesto(ruta_zip)
    with zipfile.ZipFile(ruta_zip, "r") as zf:
        for nombre_arch, hash_esperado in m.hashes_sha256.items():
            if nombre_arch not in zf.namelist():
                raise ValueError(
                    f"Falta el archivo {nombre_arch!r} en el respaldo.")
            h = hashlib.sha256()
            with zf.open(nombre_arch) as f:
                for bloque in iter(lambda: f.read(65536), b""):
                    h.update(bloque)
            if h.hexdigest() != hash_esperado:
                raise ValueError(
                    f"Hash no coincide para {nombre_arch}: el respaldo está "
                    "corrupto o fue modificado.")
    return m


def importar(
    ruta_zip: Path,
    config_local: dict,
    *,
    ruta_env_local: Path | None = None,
    ruta_config_yaml_local: Path | None = None,
    sobrescribir_config: bool = False,
    sobrescribir_env: bool = False,
    progreso: Callable[[ProgresoRespaldo], None] | None = None,
) -> Manifiesto:
    """Restaura un respaldo sobre este PC.

    - Usa el `config_local` (no el del zip) para saber DÓNDE poner los archivos.
    - La BD del zip reemplaza a la BD local.
    - Los PDFs del zip se copian bajo `config_local['rutas']['archivo']`,
      preservando su estructura AÑO/Mes/Marca/_revisar/etc.
    - `sobrescribir_config` y `sobrescribir_env` controlan si pisamos esos
      archivos locales (default: NO; los del zip se ignoran salvo opt-in).

    El llamador debe haber: (a) pausado el watcher, (b) generado un respaldo
    de seguridad de los datos actuales antes de llamar aquí.
    """
    m = verificar_zip(ruta_zip)
    if m.version_esquema_respaldo > VERSION_ESQUEMA_RESPALDO:
        raise ValueError(
            f"Este respaldo requiere una versión más nueva del programa "
            f"(esquema de respaldo {m.version_esquema_respaldo}, "
            f"esta versión soporta hasta {VERSION_ESQUEMA_RESPALDO}).")

    rutas = config_local.get("rutas", {})
    raiz_archivo = Path(rutas.get("archivo", ""))
    ruta_bd = Path(rutas.get("base_datos", ""))
    if not raiz_archivo or not ruta_bd:
        raise ValueError("config local no tiene rutas.archivo o rutas.base_datos.")

    if ruta_config_yaml_local is None:
        ruta_config_yaml_local = Path.cwd() / "config.yaml"
    if ruta_env_local is None:
        ruta_env_local = Path.cwd() / ".env"

    raiz_archivo.mkdir(parents=True, exist_ok=True)
    ruta_bd.parent.mkdir(parents=True, exist_ok=True)

    with zipfile.ZipFile(ruta_zip, "r") as zf:
        nombres = zf.namelist()
        pdfs_zip = [n for n in nombres if n.startswith("facturas/")]
        total = len(pdfs_zip) + 1  # +1 por la BD
        _reportar(progreso, "Restaurando base de datos…", 0, total)
        with zf.open("data/facturas.db") as src, open(ruta_bd, "wb") as dst:
            shutil.copyfileobj(src, dst)
        hechos = 1
        _reportar(progreso, "Restaurando PDFs…", hechos, total)

        for i, nombre in enumerate(pdfs_zip, start=1):
            rel = nombre[len("facturas/"):]
            if not rel:
                continue
            destino = raiz_archivo / rel
            destino.parent.mkdir(parents=True, exist_ok=True)
            with zf.open(nombre) as src, open(destino, "wb") as dst:
                shutil.copyfileobj(src, dst)
            hechos += 1
            if i % 10 == 0 or i == len(pdfs_zip):
                _reportar(progreso, "Restaurando PDFs…", hechos, total)

        if sobrescribir_config and "config/config.yaml" in nombres:
            with zf.open("config/config.yaml") as src, \
                 open(ruta_config_yaml_local, "wb") as dst:
                shutil.copyfileobj(src, dst)
        if sobrescribir_env and "config/.env" in nombres:
            with zf.open("config/.env") as src, \
                 open(ruta_env_local, "wb") as dst:
                shutil.copyfileobj(src, dst)

    _reportar(progreso, "Validando restauración…", total, total)
    _validar_post_importacion(ruta_bd, raiz_archivo, m)
    return m


def _validar_post_importacion(ruta_bd: Path, raiz_archivo: Path,
                              m: Manifiesto) -> None:
    """Comprueba que los conteos del manifiesto coinciden con lo restaurado."""
    cnx = sqlite3.connect(str(ruta_bd))
    try:
        n_fact = cnx.execute("SELECT COUNT(*) FROM facturas").fetchone()[0]
    except sqlite3.Error as exc:
        raise ValueError(f"La base de datos restaurada está dañada: {exc}") from exc
    finally:
        cnx.close()

    esperado_fact = m.conteos.get("facturas")
    if esperado_fact is not None and n_fact != esperado_fact:
        raise ValueError(
            f"Conteo de facturas no coincide: esperado {esperado_fact}, "
            f"restaurado {n_fact}.")

    n_pdfs = sum(1 for _ in raiz_archivo.rglob("*.pdf"))
    esperado_pdfs = m.conteos.get("pdfs")
    if esperado_pdfs is not None and n_pdfs < esperado_pdfs:
        raise ValueError(
            f"Conteo de PDFs no coincide: esperado al menos {esperado_pdfs}, "
            f"encontrado {n_pdfs}.")
