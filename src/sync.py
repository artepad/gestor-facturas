"""Sincronización de facturas hacia el servidor web (admin.minimark.cl).

Diseño:
- La BD local sigue siendo la fuente de verdad. El servidor es un espejo.
- Cada cambio (alta de factura) deja un item en la tabla `sync_cola`.
- Un worker en thread (arrancado desde el modo bandeja) vacía la cola:
  arma el payload, hace POST con el token de la máquina, y borra el item
  si tuvo éxito. Si falla, aplica backoff exponencial y reintenta.
- Si no hay internet o el server está caído, la cola se acumula y se
  vacía sola cuando vuelve la conexión. Nada se pierde.

La sincronización es OPCIONAL: si `config.sincronizacion.activado` es
False o falta el token, el worker no se lanza y la cola queda inerte.
"""

from __future__ import annotations

import threading
import time
from pathlib import Path

import requests

from db import Database

# Backoff por número de intentos (segundos). Tras agotar, se repite el último.
_BACKOFF = [60, 300, 900, 3600]  # 1min, 5min, 15min, 1h
_TIMEOUT = 30  # segundos por request

# User-Agent realista: muchos WAF (como Mod_Security en HostGator) bloquean
# el "python-requests/x.y" por defecto con un HTTP 406.
_HEADERS_BASE = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) GestorFacturas/1.0"
    ),
    "Accept": "application/json",
}


def _backoff_para(intentos: int) -> int:
    return _BACKOFF[min(intentos, len(_BACKOFF) - 1)]


def config_sync(config: dict) -> dict | None:
    """Devuelve la config de sincronización si está activa y completa."""
    s = config.get("sincronizacion") or {}
    if not s.get("activado"):
        return None
    if not s.get("url") or not s.get("token"):
        return None
    return s


def enviar_uno(db: Database, item: dict, cfg: dict) -> tuple[bool, str]:
    """Envía un item de la cola al servidor. Devuelve (exito, mensaje)."""
    uuid_local = item["uuid_local"]
    url = cfg["url"].rstrip("/") + "/api/facturas.php"
    headers = {**_HEADERS_BASE, "Authorization": f"Bearer {cfg['token']}"}

    if item["accion"] == "eliminar":
        # La eliminación se maneja como un campo en el payload mínimo
        try:
            r = requests.post(
                url, headers=headers,
                data={"datos": _json_eliminar(uuid_local)},
                timeout=_TIMEOUT,
            )
            return _interpretar(r)
        except requests.RequestException as exc:
            return False, f"Sin conexión: {exc}"

    # accion == "guardar"
    payload = db.factura_para_sync(uuid_local)
    if payload is None:
        # La factura ya no existe localmente: nada que enviar, damos por hecho.
        return True, "Factura inexistente, se descarta de la cola."

    import json
    datos_json = json.dumps(payload, ensure_ascii=False, default=str)
    ruta_pdf = payload.get("ruta_archivo")

    try:
        archivos = None
        pdf_handle = None
        if ruta_pdf and Path(ruta_pdf).exists():
            pdf_handle = open(ruta_pdf, "rb")
            archivos = {"pdf": (Path(ruta_pdf).name, pdf_handle, "application/pdf")}
        try:
            r = requests.post(
                url, headers=headers,
                data={"datos": datos_json},
                files=archivos,
                timeout=_TIMEOUT,
            )
        finally:
            if pdf_handle is not None:
                pdf_handle.close()
        return _interpretar(r)
    except requests.RequestException as exc:
        return False, f"Sin conexión: {exc}"


def _json_eliminar(uuid_local: str) -> str:
    import json
    return json.dumps({"uuid_local": uuid_local, "eliminar": True})


def _interpretar(r: "requests.Response") -> tuple[bool, str]:
    if r.status_code == 200:
        return True, "OK"
    if r.status_code == 401:
        return False, "Token inválido (revisa la config de sincronización)."
    return False, f"HTTP {r.status_code}: {r.text[:200]}"


def procesar_cola(db: Database, cfg: dict) -> int:
    """Procesa los items pendientes una vez. Devuelve cuántos se enviaron OK."""
    enviados = 0
    items = db.pendientes_sync()
    for i, item in enumerate(items):
        exito, msg = enviar_uno(db, item, cfg)
        if exito:
            db.marcar_sync_ok(item["id"])
            enviados += 1
        else:
            db.marcar_sync_error(
                item["id"], msg, _backoff_para(item["intentos"]))
            print(f"[sync] error enviando {item['uuid_local']}: {msg}", flush=True)
        # Pausa breve entre envíos: evita que el WAF tome la ráfaga como ataque.
        if i < len(items) - 1:
            time.sleep(0.5)
    return enviados


class SyncWorker:
    """Hilo que vacía la cola de sincronización cada cierto intervalo."""

    def __init__(self, db: Database, config: dict, intervalo: int = 30) -> None:
        self.db = db
        self.config = config
        self.intervalo = intervalo
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

    def iniciar(self) -> bool:
        """Arranca el worker si la sincronización está configurada. Devuelve
        True si se lanzó, False si está desactivada."""
        cfg = config_sync(self.config)
        if cfg is None:
            print("[sync] sincronización desactivada o sin token; worker no iniciado.", flush=True)
            return False
        self._thread = threading.Thread(target=self._loop, daemon=True)
        self._thread.start()
        print("[sync] worker de sincronización iniciado.", flush=True)
        return True

    def detener(self) -> None:
        self._stop.set()

    def _loop(self) -> None:
        while not self._stop.is_set():
            try:
                cfg = config_sync(self.config)
                if cfg is not None:
                    procesar_cola(self.db, cfg)
            except Exception as exc:  # noqa: BLE001
                print(f"[sync] error en el ciclo: {exc}", flush=True)
            self._stop.wait(self.intervalo)
