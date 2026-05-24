"""Estado compartido entre el watcher (en su thread) y el ícono de bandeja."""

from __future__ import annotations

from collections import Counter
from datetime import date, datetime
from threading import Lock


class Estado:
    """Cuenta lo procesado en el día y mantiene la bandera de pausa.

    Es seguro acceder desde múltiples hilos.
    """

    def __init__(self) -> None:
        self.pausado: bool = False
        self._contadores: Counter[str] = Counter()
        self._dia: date = datetime.now().date()
        self._lock = Lock()

    def incrementar(self, evento: str) -> None:
        """Eventos esperados: 'ok', 'revisar', 'defectuoso', 'duplicado',
        'no_factura', 'error'."""
        with self._lock:
            self._reset_si_dia_cambio()
            self._contadores[evento] += 1

    def snapshot(self) -> dict[str, int]:
        with self._lock:
            self._reset_si_dia_cambio()
            return dict(self._contadores)

    def _reset_si_dia_cambio(self) -> None:
        hoy = datetime.now().date()
        if hoy != self._dia:
            self._contadores.clear()
            self._dia = hoy
