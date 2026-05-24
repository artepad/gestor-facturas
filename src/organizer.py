"""Organización física de archivos: mueve PDFs a la estructura año/mes/marca."""

from __future__ import annotations

import re
import shutil
from datetime import datetime, timedelta
from pathlib import Path

from classifier import DatosFactura

MESES_ES = {
    1: "Enero", 2: "Febrero", 3: "Marzo", 4: "Abril",
    5: "Mayo", 6: "Junio", 7: "Julio", 8: "Agosto",
    9: "Septiembre", 10: "Octubre", 11: "Noviembre", 12: "Diciembre",
}

# Caracteres no permitidos en nombres de carpeta/archivo en Windows
PATRON_INVALIDO = re.compile(r'[<>:"/\\|?*\x00-\x1f]')


def sanitizar(nombre: str) -> str:
    """Limpia un nombre para que sea válido como carpeta o archivo en Windows."""
    limpio = PATRON_INVALIDO.sub("", nombre).strip(" .")
    return limpio or "SinNombre"


def parsear_fecha(fecha_str: str) -> datetime:
    """Acepta DD-MM-YYYY (formato esperado) y devuelve datetime."""
    fecha = datetime.strptime(fecha_str, "%d-%m-%Y")
    limite_futuro = datetime.now() + timedelta(days=1)
    if fecha.date() > limite_futuro.date():
        raise ValueError(
            f"Fecha de emisión futura no permitida: {fecha:%d-%m-%Y}. "
            f"Revisar antes de archivar."
        )
    return fecha


def ruta_destino(raiz_archivo: Path, datos: DatosFactura) -> Path:
    """Construye la ruta final YYYY/MesEspañol/Marca/ a partir de los datos."""
    fecha = parsear_fecha(datos.fecha)
    proveedor = sanitizar(datos.proveedor)
    return raiz_archivo / str(fecha.year) / MESES_ES[fecha.month] / proveedor


def nombre_archivo(datos: DatosFactura, extension: str = ".pdf") -> str:
    """Genera el nombre base: factura_DD-MM-YYYY.pdf"""
    fecha = parsear_fecha(datos.fecha)
    return f"factura_{fecha.strftime('%d-%m-%Y')}{extension}"


def archivar(origen: Path, raiz_archivo: Path, datos: DatosFactura) -> Path:
    """Mueve `origen` a su carpeta final. Si ya existe un archivo con ese nombre,
    le agrega un sufijo numérico. Devuelve la ruta final."""
    destino_dir = ruta_destino(raiz_archivo, datos)
    destino_dir.mkdir(parents=True, exist_ok=True)

    nombre_base = nombre_archivo(datos, origen.suffix.lower())
    destino = destino_dir / nombre_base

    contador = 2
    while destino.exists():
        stem = Path(nombre_base).stem
        destino = destino_dir / f"{stem}_{contador}{origen.suffix.lower()}"
        contador += 1

    shutil.move(str(origen), str(destino))
    return destino


def mover_a_revisar(origen: Path, carpeta_revisar: Path, motivo: str) -> Path:
    """Mueve un archivo a la carpeta de revisión manual con un .txt explicativo."""
    carpeta_revisar.mkdir(parents=True, exist_ok=True)
    destino = carpeta_revisar / origen.name
    contador = 2
    while destino.exists():
        destino = carpeta_revisar / f"{origen.stem}_{contador}{origen.suffix}"
        contador += 1
    shutil.move(str(origen), str(destino))
    destino.with_suffix(destino.suffix + ".motivo.txt").write_text(
        motivo, encoding="utf-8"
    )
    return destino


def mover_a_reemplazadas(origen: Path, carpeta_reemplazadas: Path, motivo: str) -> Path:
    """Mueve una factura que fue reemplazada por una nueva versión, con motivo."""
    carpeta_reemplazadas.mkdir(parents=True, exist_ok=True)
    destino = carpeta_reemplazadas / origen.name
    contador = 2
    while destino.exists():
        destino = carpeta_reemplazadas / f"{origen.stem}_{contador}{origen.suffix}"
        contador += 1
    shutil.move(str(origen), str(destino))
    destino.with_suffix(destino.suffix + ".motivo.txt").write_text(
        motivo, encoding="utf-8"
    )
    return destino


def mover_a_no_facturas(origen: Path, carpeta_no_facturas: Path, motivo: str) -> Path:
    """Mueve un PDF que la IA descartó porque no es una factura comercial.
    El archivo queda fuera del flujo normal: no se registra en BD ni se archiva."""
    carpeta_no_facturas.mkdir(parents=True, exist_ok=True)
    destino = carpeta_no_facturas / origen.name
    contador = 2
    while destino.exists():
        destino = carpeta_no_facturas / f"{origen.stem}_{contador}{origen.suffix}"
        contador += 1
    shutil.move(str(origen), str(destino))
    destino.with_suffix(destino.suffix + ".motivo.txt").write_text(
        motivo, encoding="utf-8"
    )
    return destino


def mover_a_errores(origen: Path, carpeta_errores: Path, error: str) -> Path:
    """Mueve un archivo que falló por error técnico, con log adjunto."""
    carpeta_errores.mkdir(parents=True, exist_ok=True)
    destino = carpeta_errores / origen.name
    contador = 2
    while destino.exists():
        destino = carpeta_errores / f"{origen.stem}_{contador}{origen.suffix}"
        contador += 1
    shutil.move(str(origen), str(destino))
    destino.with_suffix(destino.suffix + ".error.txt").write_text(
        error, encoding="utf-8"
    )
    return destino
