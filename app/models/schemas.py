"""Esquemas alineados al Manual Técnico PUI (sección 8)."""

from __future__ import annotations

from datetime import date
from typing import Literal

from pydantic import BaseModel, Field, field_validator


class LoginRequest(BaseModel):
    """Manual 8.1 — autenticación de la PUI hacia la institución diversa."""

    usuario: Literal["PUI"] = Field(
        ...,
        description='Valor fijo "PUI" (3 caracteres)',
        min_length=3,
        max_length=3,
    )
    clave: str = Field(..., min_length=16, max_length=20)

    @field_validator("usuario")
    @classmethod
    def usuario_fijo(cls, v: str) -> str:
        if v != "PUI":
            raise ValueError('El usuario debe ser exactamente "PUI"')
        return v


class LoginResponse(BaseModel):
    token: str


class ActivarReporteRequest(BaseModel):
    """Manual 8.2 — activar reporte de búsqueda."""

    id: str = Field(..., min_length=36, max_length=75)
    curp: str = Field(
        ...,
        min_length=18,
        max_length=18,
        pattern=r"^[A-Za-z0-9]{18}$",
        description="CURP (se normaliza a mayúsculas)",
    )
    nombre: str | None = Field(None, max_length=50)
    primer_apellido: str | None = Field(None, max_length=50)
    segundo_apellido: str | None = Field(None, max_length=50)
    fecha_nacimiento: str | None = Field(None, min_length=10, max_length=10)
    fecha_desaparicion: str | None = Field(None, min_length=10, max_length=10)
    lugar_nacimiento: str = Field(..., max_length=20)
    sexo_asignado: str | None = Field(None, min_length=1, max_length=1)
    telefono: str | None = Field(None, max_length=15)
    correo: str | None = Field(None, max_length=50)
    direccion: str | None = Field(None, max_length=500)
    calle: str | None = Field(None, max_length=50)
    numero: str | None = Field(None, max_length=20)
    colonia: str | None = Field(None, max_length=50)
    codigo_postal: str | None = Field(None, max_length=5)
    municipio_o_alcaldia: str | None = Field(None, max_length=100)
    entidad_federativa: str | None = Field(None, max_length=40)

    @field_validator("curp", mode="before")
    @classmethod
    def _curp_mayusculas(cls, v: object) -> object:
        if isinstance(v, str):
            return v.strip().upper()
        return v

    @field_validator("fecha_nacimiento", "fecha_desaparicion", mode="before")
    @classmethod
    def _fechas_vacio_a_none(cls, v: object) -> object:
        if v is None:
            return None
        if isinstance(v, str) and not v.strip():
            return None
        return v

    @field_validator("fecha_nacimiento", "fecha_desaparicion")
    @classmethod
    def _fechas_iso8601(cls, v: str | None) -> str | None:
        if v is None:
            return None
        try:
            date.fromisoformat(v)
        except ValueError as exc:
            raise ValueError("La fecha debe estar en formato YYYY-MM-DD (ISO 8601)") from exc
        return v


class ActivarReporteResponse(BaseModel):
    message: str = "La solicitud de activación del reporte de búsqueda se recibió correctamente."


class DesactivarReporteRequest(BaseModel):
    """Manual 8.4."""

    id: str = Field(..., min_length=36, max_length=75)


class DesactivarReporteResponse(BaseModel):
    message: str = "Registro de finalización de búsqueda histórica guardado correctamente"
