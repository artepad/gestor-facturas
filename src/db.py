"""Capa de base de datos SQLite: facturas, aliases de proveedor y búsqueda full-text."""

from __future__ import annotations

import re
import sqlite3
import unicodedata
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Iterator

from classifier import DatosFactura, DetalleFactura
from precios import precio_sugerido
from validacion import validar_datos_factura

ESQUEMA = """
CREATE TABLE IF NOT EXISTS facturas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  proveedor       TEXT NOT NULL,
  razon_social    TEXT,
  rut_emisor      TEXT,
  fecha           TEXT NOT NULL,          -- YYYY-MM-DD
  numero_factura  TEXT,
  total           REAL,
  moneda          TEXT,
  ruta_archivo    TEXT NOT NULL UNIQUE,
  texto_completo  TEXT,
  confianza       REAL,
  notas           TEXT,
  procesado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_proveedor ON facturas(proveedor);
CREATE INDEX IF NOT EXISTS idx_fecha ON facturas(fecha);
CREATE INDEX IF NOT EXISTS idx_rut ON facturas(rut_emisor);

CREATE TABLE IF NOT EXISTS alias_proveedor (
  alias_normalizado  TEXT PRIMARY KEY,    -- nombre normalizado (sin acentos ni sufijos legales)
  proveedor_canonico TEXT NOT NULL        -- nombre de carpeta de esa empresa
);

CREATE TABLE IF NOT EXISTS empresa_rut (
  rut                TEXT PRIMARY KEY,    -- RUT normalizado: sin puntos, con guion, DV en mayúscula
  proveedor_canonico TEXT NOT NULL        -- nombre de carpeta de esa empresa
);

-- Instrucciones aprendidas para interpretar el detalle de las facturas de un
-- proveedor. Se reutilizan automáticamente en futuras facturas del mismo RUT.
CREATE TABLE IF NOT EXISTS instruccion_proveedor (
  rut            TEXT PRIMARY KEY,        -- RUT normalizado del emisor
  instrucciones  TEXT NOT NULL,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Metadatos del análisis de detalle de una factura (un registro por factura analizada)
CREATE TABLE IF NOT EXISTS detalle_factura (
  factura_id           INTEGER PRIMARY KEY REFERENCES facturas(id) ON DELETE CASCADE,
  precios_incluyen_iva INTEGER NOT NULL DEFAULT 0,   -- 0/1
  confianza            REAL,
  notas                TEXT,
  analizado_en         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Productos extraídos de una factura (línea por línea)
CREATE TABLE IF NOT EXISTS producto (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  factura_id      INTEGER NOT NULL REFERENCES facturas(id) ON DELETE CASCADE,
  orden           INTEGER NOT NULL DEFAULT 0,       -- orden de aparición en la factura
  descripcion     TEXT NOT NULL,
  cantidad        REAL,
  precio_unitario REAL,
  descuento       REAL,
  monto           REAL,
  afecto_iva      INTEGER NOT NULL DEFAULT 1,       -- 0/1
  precio_sugerido REAL,                             -- se calcula en la fase de precios
  editado_manual  INTEGER NOT NULL DEFAULT 0        -- 0/1
);
CREATE INDEX IF NOT EXISTS idx_producto_factura ON producto(factura_id);

CREATE VIRTUAL TABLE IF NOT EXISTS facturas_fts USING fts5(
  proveedor, razon_social, numero_factura, texto_completo,
  content='facturas', content_rowid='id'
);

CREATE TRIGGER IF NOT EXISTS facturas_ai AFTER INSERT ON facturas BEGIN
  INSERT INTO facturas_fts(rowid, proveedor, razon_social, numero_factura, texto_completo)
  VALUES (new.id, new.proveedor, new.razon_social, new.numero_factura, new.texto_completo);
END;

CREATE TRIGGER IF NOT EXISTS facturas_ad AFTER DELETE ON facturas BEGIN
  INSERT INTO facturas_fts(facturas_fts, rowid, proveedor, razon_social, numero_factura, texto_completo)
  VALUES ('delete', old.id, old.proveedor, old.razon_social, old.numero_factura, old.texto_completo);
END;
"""


@dataclass(frozen=True)
class FilaFactura:
    id: int
    proveedor: str
    razon_social: str | None
    rut_emisor: str | None
    fecha: str
    numero_factura: str | None
    total: float | None
    moneda: str | None
    ruta_archivo: str
    confianza: float | None
    notas: str | None


# Palabras genéricas de razón social chilena que NO identifican a la empresa.
# Se ignoran al comparar nombres: "Comercial CCU S.A." y "CCU" deben coincidir.
_RE_PALABRAS_GENERICAS = re.compile(
    r"\b("
    r"s\.?\s*a\.?|s\.?\s*p\.?\s*a\.?|ltda\.?|limitada|ltd|"
    r"e\.?\s*i\.?\s*r\.?\s*l\.?|cia\.?|"
    r"comercial(?:izadora)?|compania|distribuidora?|sociedad|"
    r"importadora|exportadora|industrial|servicios"
    r")\b",
    re.IGNORECASE,
)


def _sin_acentos(texto: str) -> str:
    return "".join(
        c for c in unicodedata.normalize("NFKD", texto)
        if not unicodedata.combining(c)
    )


def _normalizar_nombre(nombre: str) -> str:
    """'Comercial CCU S.A.' → 'ccu'. Quita acentos, sufijos/palabras legales
    y todo lo que no sea alfanumérico, para comparar nombres comerciales."""
    base = _RE_PALABRAS_GENERICAS.sub(" ", _sin_acentos(nombre).lower())
    return re.sub(r"[^a-z0-9]", "", base)


@dataclass(frozen=True)
class FilaProducto:
    id: int
    descripcion: str
    cantidad: float | None
    precio_unitario: float | None
    descuento: float | None
    monto: float | None
    afecto_iva: bool
    precio_sugerido: float | None
    editado_manual: bool


def _normalizar_rut(rut: str | None) -> str | None:
    """'99.554.560-8' → '99554560-8'. Devuelve None si no parece un RUT válido.
    El RUT es el identificador legal único de la empresa emisora."""
    if not rut:
        return None
    limpio = re.sub(r"[^0-9kK]", "", rut).upper()
    if len(limpio) < 7:
        return None
    return f"{limpio[:-1]}-{limpio[-1]}"


class Database:
    def __init__(self, ruta: Path) -> None:
        self.ruta = ruta
        ruta.parent.mkdir(parents=True, exist_ok=True)
        with self._conexion() as cnx:
            cnx.executescript(ESQUEMA)

    @contextmanager
    def _conexion(self) -> Iterator[sqlite3.Connection]:
        cnx = sqlite3.connect(self.ruta)
        cnx.row_factory = sqlite3.Row
        cnx.execute("PRAGMA foreign_keys = ON")  # activa el borrado en cascada
        try:
            yield cnx
            cnx.commit()
        finally:
            cnx.close()

    def resolver_proveedor(self, nombre_crudo: str, rut_emisor: str | None = None) -> str:
        """Devuelve el nombre canónico (nombre de carpeta) de la empresa emisora.

        Identidad por orden de prioridad:
          1. RUT del emisor — idéntico en todas las facturas de la misma empresa,
             aunque el modelo escriba la marca distinta ('CCU' vs 'Comercial CCU').
          2. Nombre comercial normalizado (sin acentos ni sufijos legales) — respaldo
             cuando el RUT no es legible.

        La primera factura de una empresa fija su nombre de carpeta. Las siguientes
        se vinculan a ese mismo nombre, y cada variante nueva de la marca queda
        aprendida como alias para no volver a fallar."""
        rut = _normalizar_rut(rut_emisor)
        nombre_norm = _normalizar_nombre(nombre_crudo)
        with self._conexion() as cnx:
            canonico: str | None = None

            if rut is not None:
                row = cnx.execute(
                    "SELECT proveedor_canonico FROM empresa_rut WHERE rut = ?", (rut,)
                ).fetchone()
                if row:
                    canonico = row["proveedor_canonico"]

            if canonico is None and nombre_norm:
                row = cnx.execute(
                    "SELECT proveedor_canonico FROM alias_proveedor WHERE alias_normalizado = ?",
                    (nombre_norm,),
                ).fetchone()
                if row:
                    canonico = row["proveedor_canonico"]

            if canonico is None:
                canonico = nombre_crudo  # empresa nueva: su nombre fija la carpeta

            # Aprender los vínculos: futuras facturas de esta empresa caerán en
            # la misma carpeta por su RUT o por cualquier variante del nombre ya vista.
            if rut is not None:
                cnx.execute(
                    "INSERT OR IGNORE INTO empresa_rut (rut, proveedor_canonico) VALUES (?, ?)",
                    (rut, canonico),
                )
            if nombre_norm:
                cnx.execute(
                    "INSERT OR IGNORE INTO alias_proveedor (alias_normalizado, proveedor_canonico) VALUES (?, ?)",
                    (nombre_norm, canonico),
                )
            return canonico

    def registrar_factura(
        self,
        datos: DatosFactura,
        ruta_archivo: Path,
        texto_completo: str,
    ) -> int:
        """Inserta una factura. Devuelve el id. Si la ruta ya existe, no hace nada."""
        validacion = validar_datos_factura(datos)
        advertencias_bloqueantes = [
            adv for adv in validacion.advertencias
            if "Monto CLP sospechosamente bajo" in adv
        ]
        if not validacion.ok or advertencias_bloqueantes:
            raise ValueError(
                "Datos críticos inválidos; no se registró la factura: "
                + " | ".join([*validacion.errores, *advertencias_bloqueantes])
            )
        datos = validacion.datos
        fecha_iso = datetime.strptime(datos.fecha, "%d-%m-%Y").strftime("%Y-%m-%d")
        with self._conexion() as cnx:
            cur = cnx.execute(
                """
                INSERT OR IGNORE INTO facturas
                  (proveedor, razon_social, rut_emisor, fecha, numero_factura,
                   total, moneda, ruta_archivo, texto_completo, confianza, notas)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    datos.proveedor, datos.razon_social, datos.rut_emisor,
                    fecha_iso, datos.numero_factura, datos.total, datos.moneda,
                    str(ruta_archivo), texto_completo, datos.confianza, datos.notas,
                ),
            )
            return cur.lastrowid or 0

    def listar_proveedores(self) -> list[str]:
        with self._conexion() as cnx:
            rows = cnx.execute(
                "SELECT DISTINCT proveedor FROM facturas ORDER BY proveedor COLLATE NOCASE"
            ).fetchall()
            return [r["proveedor"] for r in rows]

    def buscar(
        self,
        texto: str | None = None,
        proveedor: str | None = None,
        fecha_inicio: str | None = None,   # YYYY-MM-DD
        fecha_fin: str | None = None,
        limite: int = 500,
    ) -> list[FilaFactura]:
        clausulas = ["1=1"]
        params: list = []

        if proveedor:
            clausulas.append("proveedor = ?")
            params.append(proveedor)
        if fecha_inicio:
            clausulas.append("fecha >= ?")
            params.append(fecha_inicio)
        if fecha_fin:
            clausulas.append("fecha <= ?")
            params.append(fecha_fin)

        if texto:
            sql = f"""
                SELECT f.* FROM facturas f
                JOIN facturas_fts fts ON fts.rowid = f.id
                WHERE facturas_fts MATCH ? AND {' AND '.join(clausulas)}
                ORDER BY f.fecha DESC LIMIT ?
            """
            params = [texto, *params, limite]
        else:
            sql = f"""
                SELECT * FROM facturas
                WHERE {' AND '.join(clausulas)}
                ORDER BY fecha DESC LIMIT ?
            """
            params = [*params, limite]

        with self._conexion() as cnx:
            rows = cnx.execute(sql, params).fetchall()
            return [
                FilaFactura(
                    id=r["id"], proveedor=r["proveedor"],
                    razon_social=r["razon_social"], rut_emisor=r["rut_emisor"],
                    fecha=r["fecha"], numero_factura=r["numero_factura"],
                    total=r["total"], moneda=r["moneda"],
                    ruta_archivo=r["ruta_archivo"],
                    confianza=r["confianza"], notas=r["notas"],
                )
                for r in rows
            ]

    def existe_ruta(self, ruta: Path) -> bool:
        with self._conexion() as cnx:
            row = cnx.execute(
                "SELECT 1 FROM facturas WHERE ruta_archivo = ?", (str(ruta),)
            ).fetchone()
            return row is not None

    def buscar_duplicado(
        self, numero_factura: str | None, rut_emisor: str | None
    ) -> FilaFactura | None:
        """Busca una factura ya registrada con el mismo número + RUT.
        Devuelve None si no hay match o si faltan datos para identificar duplicados."""
        if not numero_factura or not rut_emisor:
            return None
        with self._conexion() as cnx:
            row = cnx.execute(
                """
                SELECT * FROM facturas
                WHERE numero_factura = ? AND rut_emisor = ?
                LIMIT 1
                """,
                (numero_factura, rut_emisor),
            ).fetchone()
            if not row:
                return None
            return FilaFactura(
                id=row["id"], proveedor=row["proveedor"],
                razon_social=row["razon_social"], rut_emisor=row["rut_emisor"],
                fecha=row["fecha"], numero_factura=row["numero_factura"],
                total=row["total"], moneda=row["moneda"],
                ruta_archivo=row["ruta_archivo"],
                confianza=row["confianza"], notas=row["notas"],
            )

    def eliminar(self, id_factura: int) -> None:
        with self._conexion() as cnx:
            cnx.execute("DELETE FROM facturas WHERE id = ?", (id_factura,))

    # --- Detalle de productos ---

    def guardar_detalle(self, factura_id: int, detalle: DetalleFactura) -> None:
        """Guarda el detalle de productos de una factura, reemplazando lo anterior."""
        with self._conexion() as cnx:
            cnx.execute("DELETE FROM producto WHERE factura_id = ?", (factura_id,))
            cnx.execute(
                """
                INSERT OR REPLACE INTO detalle_factura
                  (factura_id, precios_incluyen_iva, confianza, notas, analizado_en)
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                """,
                (
                    factura_id, int(detalle.precios_incluyen_iva),
                    detalle.confianza, detalle.notas,
                ),
            )
            for orden, p in enumerate(detalle.productos):
                cnx.execute(
                    """
                    INSERT INTO producto
                      (factura_id, orden, descripcion, cantidad, precio_unitario,
                       descuento, monto, afecto_iva)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        factura_id, orden, p.descripcion, p.cantidad,
                        p.precio_unitario, p.descuento, p.monto, int(p.afecto_iva),
                    ),
                )

    def tiene_detalle(self, factura_id: int) -> bool:
        with self._conexion() as cnx:
            row = cnx.execute(
                "SELECT 1 FROM detalle_factura WHERE factura_id = ?", (factura_id,)
            ).fetchone()
            return row is not None

    def obtener_meta_detalle(self, factura_id: int) -> dict | None:
        """Devuelve los metadatos del análisis de detalle, o None si no se ha analizado."""
        with self._conexion() as cnx:
            row = cnx.execute(
                "SELECT * FROM detalle_factura WHERE factura_id = ?", (factura_id,)
            ).fetchone()
            if not row:
                return None
            return {
                "precios_incluyen_iva": bool(row["precios_incluyen_iva"]),
                "confianza": row["confianza"],
                "notas": row["notas"],
                "analizado_en": row["analizado_en"],
            }

    def actualizar_producto(self, producto_id: int, **campos: object) -> None:
        """Actualiza columnas de un producto. Las claves deben ser nombres de
        columna válidos de la tabla `producto` (controlados por el código)."""
        if not campos:
            return
        asignaciones = ", ".join(f"{col} = ?" for col in campos)
        valores = [*campos.values(), producto_id]
        with self._conexion() as cnx:
            cnx.execute(f"UPDATE producto SET {asignaciones} WHERE id = ?", valores)

    def recalcular_precios(
        self, factura_id: int, iva: float, margen: float, redondear_a: int
    ) -> None:
        """Recalcula y guarda el precio sugerido de cada producto de la factura."""
        meta = self.obtener_meta_detalle(factura_id)
        incluyen_iva = bool(meta["precios_incluyen_iva"]) if meta else False
        with self._conexion() as cnx:
            rows = cnx.execute(
                "SELECT id, cantidad, precio_unitario, monto, afecto_iva "
                "FROM producto WHERE factura_id = ?",
                (factura_id,),
            ).fetchall()
            for r in rows:
                sugerido = precio_sugerido(
                    monto=r["monto"], cantidad=r["cantidad"],
                    precio_unitario=r["precio_unitario"],
                    afecto_iva=bool(r["afecto_iva"]),
                    precios_incluyen_iva=incluyen_iva,
                    iva=iva, margen=margen, redondear_a=redondear_a,
                )
                cnx.execute(
                    "UPDATE producto SET precio_sugerido = ? WHERE id = ?",
                    (sugerido, r["id"]),
                )

    # --- Memoria de instrucciones por proveedor ---

    def guardar_instrucciones(self, rut_emisor: str | None, instrucciones: str) -> None:
        """Recuerda las instrucciones de extracción de un proveedor (por RUT).
        Si el texto viene vacío, borra lo que hubiera guardado."""
        rut = _normalizar_rut(rut_emisor)
        if rut is None:
            return
        texto = instrucciones.strip()
        with self._conexion() as cnx:
            if texto:
                cnx.execute(
                    """
                    INSERT INTO instruccion_proveedor (rut, instrucciones, actualizado_en)
                    VALUES (?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(rut) DO UPDATE SET
                      instrucciones = excluded.instrucciones,
                      actualizado_en = CURRENT_TIMESTAMP
                    """,
                    (rut, texto),
                )
            else:
                cnx.execute("DELETE FROM instruccion_proveedor WHERE rut = ?", (rut,))

    def obtener_instrucciones(self, rut_emisor: str | None) -> str | None:
        """Devuelve las instrucciones aprendidas para el proveedor, o None."""
        rut = _normalizar_rut(rut_emisor)
        if rut is None:
            return None
        with self._conexion() as cnx:
            row = cnx.execute(
                "SELECT instrucciones FROM instruccion_proveedor WHERE rut = ?", (rut,)
            ).fetchone()
            return row["instrucciones"] if row else None

    def obtener_productos(self, factura_id: int) -> list[FilaProducto]:
        with self._conexion() as cnx:
            rows = cnx.execute(
                "SELECT * FROM producto WHERE factura_id = ? ORDER BY orden, id",
                (factura_id,),
            ).fetchall()
            return [
                FilaProducto(
                    id=r["id"], descripcion=r["descripcion"],
                    cantidad=r["cantidad"], precio_unitario=r["precio_unitario"],
                    descuento=r["descuento"], monto=r["monto"],
                    afecto_iva=bool(r["afecto_iva"]),
                    precio_sugerido=r["precio_sugerido"],
                    editado_manual=bool(r["editado_manual"]),
                )
                for r in rows
            ]
