"""Validaciones locales para datos críticos extraídos de facturas chilenas."""

from __future__ import annotations

import math
import re
from dataclasses import dataclass, replace
from datetime import date, datetime, timedelta
from typing import Any

from classifier import DatosFactura


_RE_NUMERO = re.compile(r"[-+]?\d[\d\s.,]*")


@dataclass(frozen=True)
class ResultadoValidacion:
    datos: DatosFactura
    errores: tuple[str, ...] = ()
    advertencias: tuple[str, ...] = ()

    @property
    def ok(self) -> bool:
        return not self.errores


def parsear_monto_chileno(valor: Any, moneda: str | None = "CLP") -> float | None:
    """Convierte montos chilenos a número.

    En facturas chilenas, `.` suele separar miles y `,` decimales:
    `221.713` significa 221713, no 221.713.
    """
    if valor is None or valor == "":
        return None

    if isinstance(valor, bool):
        return None

    moneda_norm = (moneda or "CLP").upper()
    if isinstance(valor, int):
        return float(valor)
    if isinstance(valor, float):
        if not math.isfinite(valor):
            return None
        if moneda_norm == "CLP" and 0 < valor < 1000 and not valor.is_integer():
            return float(round(valor * 1000))
        return float(round(valor)) if moneda_norm == "CLP" else float(valor)

    texto = str(valor).strip()
    match = _RE_NUMERO.search(texto.replace("\u00a0", " "))
    if not match:
        return None

    numero = re.sub(r"\s+", "", match.group(0))
    if not numero:
        return None

    tiene_punto = "." in numero
    tiene_coma = "," in numero

    if tiene_punto and tiene_coma:
        if numero.rfind(",") > numero.rfind("."):
            normalizado = numero.replace(".", "").replace(",", ".")
        else:
            normalizado = numero.replace(",", "")
    elif tiene_punto:
        partes = numero.split(".")
        if moneda_norm == "CLP" or all(len(p) == 3 for p in partes[1:]):
            normalizado = "".join(partes)
        else:
            normalizado = numero
    elif tiene_coma:
        partes = numero.split(",")
        if moneda_norm == "CLP" and len(partes[-1]) == 3:
            normalizado = "".join(partes)
        else:
            normalizado = numero.replace(",", ".")
    else:
        normalizado = numero

    try:
        monto = float(normalizado)
    except ValueError:
        return None

    return float(round(monto)) if moneda_norm == "CLP" else monto


def parsear_fecha_factura(fecha: str | None) -> date | None:
    """Acepta fechas usuales de factura y devuelve una fecha real."""
    if not fecha:
        return None
    texto = str(fecha).strip()
    for formato in ("%d-%m-%Y", "%d/%m/%Y", "%d.%m.%Y", "%Y-%m-%d", "%Y/%m/%d"):
        try:
            return datetime.strptime(texto, formato).date()
        except ValueError:
            pass

    match = re.search(r"\b(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})\b", texto)
    if not match:
        return None
    dia, mes, anio = (int(p) for p in match.groups())
    if anio < 100:
        anio += 2000
    try:
        return date(anio, mes, dia)
    except ValueError:
        return None


def validar_datos_factura(
    datos: DatosFactura,
    *,
    hoy: date | None = None,
    dias_futuro_permitidos: int = 1,
) -> ResultadoValidacion:
    """Normaliza fecha/monto y rechaza datos críticos imposibles."""
    hoy = hoy or date.today()
    errores: list[str] = []
    advertencias: list[str] = []

    fecha = parsear_fecha_factura(datos.fecha)
    if fecha is None:
        errores.append(f"Fecha ilegible o inválida: {datos.fecha!r}.")
        fecha_texto = datos.fecha
    else:
        limite_futuro = hoy + timedelta(days=dias_futuro_permitidos)
        if fecha > limite_futuro:
            errores.append(
                "Fecha de emisión futura: "
                f"{fecha.strftime('%d-%m-%Y')} (hoy es {hoy.strftime('%d-%m-%Y')})."
            )
        if fecha.year < 2000:
            errores.append(f"Fecha demasiado antigua para una factura actual: {fecha:%d-%m-%Y}.")
        fecha_texto = fecha.strftime("%d-%m-%Y")

    total = parsear_monto_chileno(datos.total, datos.moneda)
    if datos.total is not None and total is None:
        errores.append(f"Monto total ilegible o inválido: {datos.total!r}.")
    elif total is not None:
        if total < 0:
            errores.append(f"Monto total negativo: {total:g}.")
        if (datos.moneda or "CLP").upper() == "CLP" and 0 < total < 1000:
            advertencias.append(
                f"Monto CLP sospechosamente bajo: {total:g}. Revisar separador de miles."
            )
        if (datos.moneda or "CLP").upper() == "CLP" and not float(total).is_integer():
            advertencias.append(f"Monto CLP con decimales: {total:g}.")

    datos_normalizados = replace(datos, fecha=fecha_texto, total=total)
    if errores or advertencias:
        notas = datos_normalizados.notas or ""
        extra = "Validación local: " + " ".join([*errores, *advertencias])
        datos_normalizados = replace(
            datos_normalizados,
            notas=f"{notas}\n{extra}".strip(),
        )

    return ResultadoValidacion(
        datos=datos_normalizados,
        errores=tuple(errores),
        advertencias=tuple(advertencias),
    )
