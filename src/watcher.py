"""Vigilancia de la carpeta de entrada. Detecta PDFs nuevos y los pasa a un procesador."""

from __future__ import annotations

import time
from collections.abc import Callable
from pathlib import Path

from watchdog.events import FileCreatedEvent, FileSystemEventHandler
from watchdog.observers import Observer


class ManejadorFacturas(FileSystemEventHandler):
    def __init__(
        self,
        procesar: Callable[[Path], None],
        extensiones: list[str],
        espera_estabilizacion: float,
        periodo_gracia: float,
    ) -> None:
        self.procesar = procesar
        self.extensiones = {e.lower() for e in extensiones}
        self.espera = espera_estabilizacion
        self.gracia = periodo_gracia

    def on_created(self, event: FileCreatedEvent) -> None:
        if event.is_directory:
            return
        ruta = Path(event.src_path)
        if ruta.suffix.lower() not in self.extensiones:
            return
        if not self._esperar_archivo_estable(ruta):
            print(f"[watcher] Archivo no se estabilizó, ignorando: {ruta.name}", flush=True)
            return
        if not self._esperar_periodo_gracia(ruta):
            print(f"[watcher] Archivo eliminado durante período de gracia: {ruta.name}", flush=True)
            return
        try:
            self.procesar(ruta)
        except Exception as exc:
            print(f"[watcher] Error procesando {ruta.name}: {exc}", flush=True)

    def _esperar_archivo_estable(self, ruta: Path, intentos: int = 10) -> bool:
        """Espera a que el tamaño del archivo deje de cambiar (el escáner terminó)."""
        tamano_previo = -1
        for _ in range(intentos):
            time.sleep(self.espera)
            if not ruta.exists():
                return False
            tamano_actual = ruta.stat().st_size
            if tamano_actual == tamano_previo and tamano_actual > 0:
                return True
            tamano_previo = tamano_actual
        return False

    def _esperar_periodo_gracia(self, ruta: Path) -> bool:
        """Espera el período de gracia. Si el archivo desaparece, devuelve False."""
        if self.gracia <= 0:
            return ruta.exists()
        print(
            f"[watcher] {ruta.name}: período de gracia de {self.gracia:.0f}s "
            f"(borra el archivo de la carpeta de entrada si quieres cancelarlo).",
            flush=True,
        )
        # Verificar cada segundo si el archivo sigue ahí — así detectamos el borrado rápido
        for _ in range(int(self.gracia)):
            time.sleep(1)
            if not ruta.exists():
                return False
        return ruta.exists()


def vigilar(
    carpeta_entrada: Path,
    procesar: Callable[[Path], None],
    extensiones: list[str],
    espera_estabilizacion: float,
    periodo_gracia: float,
) -> None:
    """Bloquea ejecutando el observer hasta Ctrl+C."""
    carpeta_entrada.mkdir(parents=True, exist_ok=True)
    handler = ManejadorFacturas(procesar, extensiones, espera_estabilizacion, periodo_gracia)
    observer = Observer()
    observer.schedule(handler, str(carpeta_entrada), recursive=False)
    observer.start()
    print(f"[watcher] Vigilando: {carpeta_entrada}")
    print(f"[watcher] Extensiones: {extensiones}")
    print(f"[watcher] Período de gracia: {periodo_gracia:.0f}s")
    print("[watcher] Ctrl+C para detener.\n")
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print("\n[watcher] Deteniendo...")
        observer.stop()
    observer.join()
