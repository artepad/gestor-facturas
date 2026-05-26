"""Orquestador principal.

Modos de uso:
    py src/main.py                # consola: vigilancia + logs en pantalla
    py src/main.py --tray         # bandeja del sistema (ideal para uso diario)
    py src/main.py --reindexar    # re-clasifica los PDFs ya archivados e inserta en BD
"""

from __future__ import annotations

import sys

# Forzar UTF-8 en stdout/stderr para evitar UnicodeEncodeError en consolas Windows
if sys.stdout is not None:
    sys.stdout.reconfigure(encoding="utf-8")
if sys.stderr is not None:
    sys.stderr.reconfigure(encoding="utf-8")

import argparse
import json
import os
import time
import traceback
from dataclasses import replace
from datetime import datetime
from pathlib import Path

import yaml
from dotenv import load_dotenv
from watchdog.observers import Observer

from classifier import Clasificador, DatosFactura
from db import Database
from estado import Estado
from extractor import extraer
from organizer import (
    archivar,
    mover_a_errores,
    mover_a_no_facturas,
    mover_a_reemplazadas,
    mover_a_revisar,
)
import respaldo
from validacion import validar_datos_factura
from watcher import ManejadorFacturas, vigilar

RAIZ = Path(__file__).resolve().parent.parent


def cargar_config(ruta: Path) -> dict:
    with open(ruta, "r", encoding="utf-8") as fh:
        return yaml.safe_load(fh)


def _resumir(datos: DatosFactura) -> str:
    partes = [datos.proveedor, datos.fecha]
    if datos.total is not None and datos.moneda:
        partes.append(f"${datos.total:,.0f} {datos.moneda}")
    return " | ".join(partes)


def _tiene_fecha_futura(errores: tuple[str, ...]) -> bool:
    return any("Fecha de emisión futura" in error for error in errores)


def _tiene_total_sospechoso(advertencias: tuple[str, ...]) -> bool:
    return any("Monto CLP sospechosamente bajo" in adv for adv in advertencias)


def _verificar_total_sospechoso(
    clasificador: Clasificador,
    contenido,
    datos: DatosFactura,
):
    validacion = validar_datos_factura(datos)
    datos = validacion.datos
    if not _tiene_total_sospechoso(validacion.advertencias):
        return validacion

    try:
        total, confianza_total, evidencia = clasificador.verificar_total(
            contenido, total_previo=datos.total
        )
        if total and confianza_total >= 0.75:
            notas = datos.notas or ""
            extra = (
                "Total corregido por segunda lectura enfocada: "
                f"{total} (confianza {confianza_total:.2f}"
                + (f", evidencia: {evidencia}" if evidencia else "")
                + ")."
            )
            datos = replace(datos, total=total, notas=f"{notas}\n{extra}".strip())
            validacion = validar_datos_factura(datos)
            print(f"[procesar] Total corregido por verificación: {total}", flush=True)
            return validacion
    except Exception as exc:
        print(f"[procesar] No se pudo verificar total sospechoso: {exc}", flush=True)

    datos = replace(
        datos,
        notas=(
            (datos.notas or "")
            + "\nValidación local: total CLP sospechosamente bajo y no se pudo verificar."
        ).strip(),
    )
    return replace(validacion, datos=datos, errores=(*validacion.errores, *validacion.advertencias))


def crear_procesador(
    config: dict,
    clasificador: Clasificador,
    db: Database,
    estado: Estado | None = None,
):
    raiz_archivo = Path(config["rutas"]["archivo"])
    carpeta_revisar = Path(config["rutas"]["revisar"])
    carpeta_errores = Path(config["rutas"]["errores"])
    carpeta_reemplazadas = Path(config["rutas"]["reemplazadas"])
    carpeta_no_facturas = Path(
        config["rutas"].get("no_facturas", str(raiz_archivo / "_no_facturas")))
    umbral = float(config["clasificacion"]["umbral_confianza"])
    umbral_defectuoso = float(config["clasificacion"]["umbral_escaneo_defectuoso"])

    def _contar(evento: str) -> None:
        if estado is not None:
            estado.incrementar(evento)

    def procesar(ruta_pdf: Path) -> None:
        if estado is not None and estado.pausado:
            print(f"[procesar] PAUSADO, dejando en _entrada: {ruta_pdf.name}", flush=True)
            return
        # Si el Administrador está exportando/importando un respaldo, no
        # tocamos la BD ni los PDFs. El archivo queda en _entrada para
        # procesarse cuando el bloqueo se levante (el siguiente evento del
        # watchdog o la próxima pasada de procesar_pendientes lo retoma).
        if respaldo.procesamiento_bloqueado(config):
            print(f"[procesar] RESPALDO EN CURSO, dejando en _entrada: "
                  f"{ruta_pdf.name}", flush=True)
            return

        t0 = time.perf_counter()
        print(f"\n[procesar] {ruta_pdf.name}", flush=True)
        try:
            contenido = extraer(ruta_pdf)
            datos = clasificador.clasificar(contenido)
        except Exception as exc:
            err = f"{exc}\n\n{traceback.format_exc()}"
            destino = mover_a_errores(ruta_pdf, carpeta_errores, err)
            _contar("error")
            print(f"[procesar] ERROR → {destino}", flush=True)
            return

        # Validación de tipo de documento: si la IA dice que NO es una factura,
        # cortar acá antes de tocar BD, carpetas de archivo o cualquier otra cosa.
        if not datos.es_factura:
            motivo = (
                "El documento NO es una factura comercial chilena.\n"
                f"Tipo detectado por la IA: {datos.tipo_documento or 'desconocido'}.\n"
                f"Confianza del clasificador: {datos.confianza:.2f}.\n"
                f"Notas: {datos.notas or '—'}\n\n"
                f"Fecha del descarte: {datetime.now():%Y-%m-%d %H:%M:%S}.\n"
                "No se registró en la base de datos ni se archivó como factura."
            )
            destino = mover_a_no_facturas(ruta_pdf, carpeta_no_facturas, motivo)
            _contar("no_factura")
            print(
                f"[procesar] → NO ES FACTURA ({datos.tipo_documento}): {destino}",
                flush=True,
            )
            return

        validacion = _verificar_total_sospechoso(clasificador, contenido, datos)
        datos = validacion.datos
        if not validacion.ok and _tiene_fecha_futura(validacion.errores):
            try:
                fecha, confianza_fecha, evidencia = clasificador.verificar_fecha(
                    contenido, fecha_previa=datos.fecha
                )
                if fecha and confianza_fecha >= 0.75:
                    notas = datos.notas or ""
                    extra = (
                        "Fecha corregida por segunda lectura enfocada: "
                        f"{fecha} (confianza {confianza_fecha:.2f}"
                        + (f", evidencia: {evidencia}" if evidencia else "")
                        + ")."
                    )
                    datos = replace(datos, fecha=fecha, notas=f"{notas}\n{extra}".strip())
                    validacion = _verificar_total_sospechoso(clasificador, contenido, datos)
                    datos = validacion.datos
                    print(
                        f"[procesar] Fecha corregida por verificación: {fecha}",
                        flush=True,
                    )
            except Exception as exc:
                print(f"[procesar] No se pudo verificar fecha futura: {exc}", flush=True)

        if not validacion.ok:
            motivo = (
                "Datos críticos inválidos detectados antes de archivar.\n"
                "La factura se deja para revisión manual para evitar archivarla "
                "con fecha o monto incorrecto.\n\n"
                "Problemas:\n"
                + "\n".join(f"- {e}" for e in validacion.errores)
                + f"\n\nDatos extraídos:\n{json.dumps(datos.__dict__, indent=2, ensure_ascii=False)}"
            )
            destino = mover_a_revisar(ruta_pdf, carpeta_revisar, motivo)
            _contar("revisar")
            print(f"[procesar] → REVISAR (validación crítica): {destino}", flush=True)
            return
        if validacion.advertencias:
            print(
                "[procesar] Advertencia de validación: "
                + " | ".join(validacion.advertencias),
                flush=True,
            )

        canonico = db.resolver_proveedor(datos.proveedor, datos.rut_emisor)
        if canonico != datos.proveedor:
            print(f"[procesar] Alias: '{datos.proveedor}' → '{canonico}'", flush=True)
            datos = replace(datos, proveedor=canonico)

        dt = time.perf_counter() - t0
        print(f"[procesar] {_resumir(datos)} (confianza={datos.confianza:.2f}, {dt:.1f}s)", flush=True)

        if datos.confianza < umbral_defectuoso:
            motivo = (
                f"POSIBLE ESCANEO DEFECTUOSO (confianza muy baja: {datos.confianza:.2f}).\n"
                f"Sugerencia: revisa el PDF y re-escanea la factura si está ilegible.\n\n"
                f"Datos parciales extraídos:\n"
                f"{json.dumps(datos.__dict__, indent=2, ensure_ascii=False)}"
            )
            destino = mover_a_revisar(ruta_pdf, carpeta_revisar, motivo)
            _contar("defectuoso")
            print(f"[procesar] → REVISAR (posible defectuoso): {destino}", flush=True)
            return

        if datos.confianza < umbral:
            motivo = (
                f"Confianza insuficiente: {datos.confianza:.2f} < {umbral}\n\n"
                f"Datos extraídos:\n{json.dumps(datos.__dict__, indent=2, ensure_ascii=False)}"
            )
            destino = mover_a_revisar(ruta_pdf, carpeta_revisar, motivo)
            _contar("revisar")
            print(f"[procesar] → REVISAR: {destino}", flush=True)
            return

        duplicado = db.buscar_duplicado(datos.numero_factura, datos.rut_emisor)
        if duplicado is not None:
            ruta_vieja = Path(duplicado.ruta_archivo)
            motivo = (
                f"Reemplazada por escaneo más reciente.\n"
                f"Factura: #{datos.numero_factura} | RUT emisor: {datos.rut_emisor}\n"
                f"Archivo nuevo: {ruta_pdf.name}\n"
                f"Fecha de reemplazo: {datetime.now():%Y-%m-%d %H:%M:%S}"
            )
            if ruta_vieja.exists():
                mover_a_reemplazadas(ruta_vieja, carpeta_reemplazadas, motivo)
                print(f"[procesar] DUPLICADO, movido a _reemplazadas: {ruta_vieja.name}", flush=True)
            db.eliminar(duplicado.id)
            _contar("duplicado")

        try:
            destino = archivar(ruta_pdf, raiz_archivo, datos)
            db.registrar_factura(datos, destino, contenido.texto)
            _contar("ok")
            print(f"[procesar] → ARCHIVADA: {destino.relative_to(raiz_archivo)}", flush=True)
        except Exception as exc:
            err = f"Error al archivar: {exc}\n\nDatos: {datos}\n\n{traceback.format_exc()}"
            destino = mover_a_errores(ruta_pdf, carpeta_errores, err)
            _contar("error")
            print(f"[procesar] ERROR al archivar → {destino}", flush=True)

    return procesar


def procesar_pendientes(
    carpeta_entrada: Path,
    extensiones: list[str],
    procesar,
) -> None:
    """Escanea la carpeta de entrada y procesa los PDFs que ya están ahí.
    Se usa al arrancar y al reanudar tras una pausa."""
    exts = {e.lower() for e in extensiones}
    pendientes = [
        p for p in carpeta_entrada.iterdir()
        if p.is_file() and p.suffix.lower() in exts
    ]
    if pendientes:
        print(f"[startup] Procesando {len(pendientes)} archivo(s) pendiente(s) en _entrada", flush=True)
        for ruta in pendientes:
            procesar(ruta)


def _redirigir_logs_a_archivo(config: dict) -> None:
    """Cuando se corre via pyw.exe (autoarranque), no hay stdout. Mandamos a archivo.

    Usa `config.rutas.logs` si está definido; si no, cae a `<programa>/data/logs/`.
    """
    ruta_logs = config.get("rutas", {}).get("logs")
    if ruta_logs:
        log_dir = Path(ruta_logs)
    else:
        log_dir = RAIZ / "data" / "logs"
    log_dir.mkdir(parents=True, exist_ok=True)
    log_path = log_dir / f"{datetime.now():%Y-%m-%d}.log"
    archivo = open(log_path, "a", encoding="utf-8", buffering=1)  # line-buffered
    sys.stdout = archivo
    sys.stderr = archivo


_MUTEX_HANDLE = None  # mantener referencia para que el mutex no se libere


def _asegurar_instancia_unica() -> bool:
    """Mutex global de Windows: solo permite UNA bandeja activa por sesion.

    Si ya hay otra instancia corriendo, devuelve False y el caller debe
    salir. Si es la primera, devuelve True y mantiene el mutex vivo
    durante toda la vida del proceso.
    """
    if os.name != "nt":
        return True  # solo aplica en Windows
    global _MUTEX_HANDLE
    try:
        import ctypes
        ERROR_ALREADY_EXISTS = 183
        nombre = "Global\\AdminFacturasBandeja_v1"
        # CreateMutexW: si ya existe, devuelve handle valido + GetLastError = 183
        _MUTEX_HANDLE = ctypes.windll.kernel32.CreateMutexW(None, False, nombre)
        if ctypes.windll.kernel32.GetLastError() == ERROR_ALREADY_EXISTS:
            return False
        return True
    except Exception:
        # Si por lo que sea no podemos crear el mutex, no bloqueamos
        return True


def modo_tray(config: dict, clasificador: Clasificador, db: Database) -> None:
    """Modo bandeja del sistema. Watcher en thread + tray en main thread."""
    if sys.stdout is None:  # arrancado via pyw.exe (sin consola)
        _redirigir_logs_a_archivo(config)

    if not _asegurar_instancia_unica():
        print("[modo_tray] Ya hay otra instancia de la bandeja corriendo. Saliendo.", flush=True)
        return

    # Importar acá para no pagar el costo en modo consola
    from tray import construir_icono

    estado = Estado()
    procesar = crear_procesador(config, clasificador, db, estado)
    carpeta_entrada = Path(config["rutas"]["entrada"])
    carpeta_entrada.mkdir(parents=True, exist_ok=True)

    # Procesar lo que ya esté en _entrada (ej: servicio estuvo apagado)
    procesar_pendientes(carpeta_entrada, config["procesamiento"]["extensiones"], procesar)

    # Arrancar observer en su propio hilo
    handler = ManejadorFacturas(
        procesar=procesar,
        extensiones=config["procesamiento"]["extensiones"],
        espera_estabilizacion=float(config["procesamiento"]["espera_estabilizacion"]),
        periodo_gracia=float(config["procesamiento"]["periodo_gracia"]),
    )
    observer = Observer()
    observer.schedule(handler, str(carpeta_entrada), recursive=False)
    observer.start()
    print(f"[tray] Vigilando {carpeta_entrada}", flush=True)

    def al_reanudar() -> None:
        # Tras reanudar la pausa, procesar lo que quedó en _entrada durante la pausa
        procesar_pendientes(carpeta_entrada, config["procesamiento"]["extensiones"], procesar)

    icono = construir_icono(
        estado=estado,
        carpetas={
            "archivo": Path(config["rutas"]["archivo"]),
            "revisar": Path(config["rutas"]["revisar"]),
            "entrada": carpeta_entrada,
            "no_facturas": Path(
                config["rutas"].get(
                    "no_facturas",
                    str(Path(config["rutas"]["archivo"]) / "_no_facturas"))),
        },
        raiz_proyecto=RAIZ,
        al_reanudar=al_reanudar,
    )

    try:
        icono.run()  # bloquea hasta que se elija "Salir"
    finally:
        print("[tray] Deteniendo watcher...", flush=True)
        observer.stop()
        observer.join()


def modo_reindexar(config: dict, clasificador: Clasificador, db: Database) -> None:
    """Recorre la carpeta de archivo y clasifica cada PDF que no esté ya en la BD."""
    raiz_archivo = Path(config["rutas"]["archivo"])
    carpetas_excluidas = {
        Path(config["rutas"]["entrada"]),
        Path(config["rutas"]["revisar"]),
        Path(config["rutas"]["errores"]),
        Path(config["rutas"]["reemplazadas"]),
    }

    pdfs: list[Path] = []
    for pdf in raiz_archivo.rglob("*.pdf"):
        if any(excluida in pdf.parents for excluida in carpetas_excluidas):
            continue
        pdfs.append(pdf)

    print(f"[reindexar] Encontrados {len(pdfs)} PDF(s) archivados.")
    nuevos, ya = 0, 0
    for pdf in pdfs:
        if db.existe_ruta(pdf):
            ya += 1
            continue
        print(f"[reindexar] {pdf.relative_to(raiz_archivo)} ...", flush=True)
        try:
            contenido = extraer(pdf)
            datos = clasificador.clasificar(contenido)
            if not datos.es_factura:
                print(
                    f"  ✗ NO ES FACTURA ({datos.tipo_documento}); se omite del reindex. "
                    "Considera moverlo manualmente fuera de la carpeta de facturas.",
                    flush=True,
                )
                continue
            validacion = _verificar_total_sospechoso(clasificador, contenido, datos)
            datos = validacion.datos
            if not validacion.ok and _tiene_fecha_futura(validacion.errores):
                try:
                    fecha, confianza_fecha, evidencia = clasificador.verificar_fecha(
                        contenido, fecha_previa=datos.fecha
                    )
                    if fecha and confianza_fecha >= 0.75:
                        notas = datos.notas or ""
                        extra = (
                            "Fecha corregida por segunda lectura enfocada: "
                            f"{fecha} (confianza {confianza_fecha:.2f}"
                            + (f", evidencia: {evidencia}" if evidencia else "")
                            + ")."
                        )
                        datos = replace(datos, fecha=fecha, notas=f"{notas}\n{extra}".strip())
                        validacion = _verificar_total_sospechoso(clasificador, contenido, datos)
                        datos = validacion.datos
                except Exception as exc:
                    print(f"  ! No se pudo verificar fecha futura: {exc}", flush=True)
            if not validacion.ok:
                print("  ✗ REVISAR: " + " | ".join(validacion.errores), flush=True)
                continue
            canonico = db.resolver_proveedor(datos.proveedor, datos.rut_emisor)
            datos = replace(datos, proveedor=canonico)
            db.registrar_factura(datos, pdf, contenido.texto)
            nuevos += 1
            print(f"  ✓ {_resumir(datos)}", flush=True)
        except Exception as exc:
            print(f"  ✗ ERROR: {exc}", flush=True)

    print(f"\n[reindexar] Listo. Nuevos: {nuevos}, ya existentes: {ya}.")


def main() -> None:
    parser = argparse.ArgumentParser(description="Sistema de organización de facturas.")
    parser.add_argument("--tray", action="store_true", help="Modo bandeja del sistema.")
    parser.add_argument("--reindexar", action="store_true", help="Re-clasifica PDFs ya archivados.")
    args = parser.parse_args()

    load_dotenv(RAIZ / ".env", override=True)
    if not os.getenv("ANTHROPIC_API_KEY"):
        print("[!] Falta ANTHROPIC_API_KEY en .env")
        sys.exit(1)

    config = cargar_config(RAIZ / "config.yaml")
    db = Database(Path(config["rutas"]["base_datos"]))
    clasificador = Clasificador(modelo=config["clasificacion"]["modelo"])

    if args.reindexar:
        modo_reindexar(config, clasificador, db)
        return

    if args.tray:
        modo_tray(config, clasificador, db)
        return

    # Modo consola por defecto (sin tray)
    procesar = crear_procesador(config, clasificador, db)
    print(f"=== Sistema de facturas iniciado {datetime.now():%Y-%m-%d %H:%M:%S} ===")
    vigilar(
        carpeta_entrada=Path(config["rutas"]["entrada"]),
        procesar=procesar,
        extensiones=config["procesamiento"]["extensiones"],
        espera_estabilizacion=float(config["procesamiento"]["espera_estabilizacion"]),
        periodo_gracia=float(config["procesamiento"]["periodo_gracia"]),
    )


if __name__ == "__main__":
    main()
