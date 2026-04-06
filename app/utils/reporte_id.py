"""Validación de id de reporte: <FUB>-<UUID4> (Manual técnico PUI)."""

from __future__ import annotations

import re

# FUB (folio + segmentos con guiones) seguido de guión y UUID versión 4 estándar.
_UUID4 = r"[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}"
REPORTE_ID_PUI_PATTERN = re.compile(rf"^(.+)-({_UUID4})$")


def es_id_reporte_pui_valido(valor: str) -> bool:
    s = (valor or "").strip()
    if not s or len(s) > 75:
        return False
    return REPORTE_ID_PUI_PATTERN.fullmatch(s) is not None
