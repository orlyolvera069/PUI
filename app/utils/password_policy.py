"""Manual 8.1 — política de contraseña del usuario PUI."""

from __future__ import annotations

import re


def clave_cumple_politica_manual_81(clave: str) -> bool:
    if not (16 <= len(clave) <= 20):
        return False
    if not re.search(r"[A-Z]", clave):
        return False
    if not re.search(r"[0-9]", clave):
        return False
    if not re.search(r"[!@#$%^&*()\-_.+]", clave):
        return False
    return True
