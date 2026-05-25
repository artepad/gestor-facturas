"""Fuente única de la versión del programa.

Para liberar una nueva versión basta con actualizar `__version__` aquí: el
footer del Administrador y de la ventana de factura la leen automáticamente.

Versionado SemVer (MAYOR.MENOR.PARCHE):
- PARCHE: arreglos de bugs y ajustes visuales menores.
- MENOR: funcionalidades nuevas que no rompen lo existente.
- MAYOR: cambios incompatibles (ej. esquema de BD que requiere migración manual).
"""

from __future__ import annotations

__version__ = "1.0.0"
NOMBRE = "Gestor de Facturas"
