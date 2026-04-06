"""Anexo 5 — mapeo código CURP (posiciones 12–13) a lugar de nacimiento."""

from __future__ import annotations

_MAP: dict[str, str] = {
    "AS": "AGUASCALIENTES",
    "BC": "BAJA CALIFORNIA",
    "BS": "BAJA CALIFORNIA SUR",
    "CC": "CAMPECHE",
    "CS": "CHIAPAS",
    "CH": "CHIHUAHUA",
    "DF": "CDMX",
    "CL": "COAHUILA",
    "CM": "COLIMA",
    "DG": "DURANGO",
    "GT": "GUANAJUATO",
    "GR": "GUERRERO",
    "HG": "HIDALGO",
    "JC": "JALISCO",
    "MC": "MÉXICO",
    "MN": "MICHOACÁN",
    "MS": "MORELOS",
    "NT": "NAYARIT",
    "NL": "NUEVO LEÓN",
    "OC": "OAXACA",
    "PL": "PUEBLA",
    "QO": "QUERÉTARO",
    "QR": "QUINTANA ROO",
    "SP": "SAN LUIS POTOSÍ",
    "SL": "SINALOA",
    "SR": "SONORA",
    "TC": "TABASCO",
    "TS": "TAMAULIPAS",
    "TL": "TLAXCALA",
    "VZ": "VERACRUZ",
    "YN": "YUCATÁN",
    "ZS": "ZACATECAS",
    "NE": "FORANEO",
    "XX": "DESCONOCIDO",
}


def lugar_nacimiento_desde_curp(curp: str) -> str:
    """
    Si la CURP es inválida o el código no corresponde, DESCONOCIDO (manual 7.2 / 8.2).
    NE -> FORANEO (manual 7.2 indica FORANEO para notificar-coincidencia).
    """
    c = (curp or "").strip().upper()
    if len(c) != 18 or not c.isalnum():
        return "DESCONOCIDO"
    code = c[11:13]
    return _MAP.get(code, "DESCONOCIDO")


def normalizar_lugar_para_notificar(valor: str) -> str:
    """Manual 7.2: código NE -> FORANEO; se acepta variación de acentos."""
    v = (valor or "").strip().upper()
    if v in ("FORÁNEO", "FORANEO"):
        return "FORANEO"
    return valor
