from __future__ import annotations

from functools import lru_cache

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

from app.utils.password_policy import clave_cumple_politica_manual_81


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    app_name: str = "pui-webhook"
    debug: bool = False

    jwt_secret_key: str = Field(..., description="Secreto para firmar JWT del webhook")
    jwt_algorithm: str = "HS256"
    jwt_expire_minutes: int = 60

    pui_webhook_password: str = Field(
        ...,
        min_length=16,
        max_length=20,
        description="Contraseña que valida el usuario PUI en /login (manual 8.1)",
    )

    pui_base_url: str = Field(..., description="URL base de la PUI sin barra final")
    pui_institucion_id: str = Field(..., min_length=4, max_length=13)
    pui_clave: str = Field(..., min_length=1, max_length=50)

    oracle_host: str = "localhost"
    oracle_port: int = 1521
    oracle_user: str = "PUI"
    oracle_password: str = ""
    oracle_service_name: str = "ORCL"
    oracle_schema: str = "ESIACOM"
    oracle_pool_min: int = 1
    oracle_pool_max: int = 5
    oracle_simulate: bool = False

    continuous_search_interval_seconds: int = 3600

    @field_validator("pui_webhook_password")
    @classmethod
    def _validar_clave_webhook(cls, v: str) -> str:
        if not clave_cumple_politica_manual_81(v):
            raise ValueError(
                "PUI_WEBHOOK_PASSWORD debe cumplir la política del manual 8.1 (16–20 caracteres, "
                "mayúscula, dígito y carácter especial permitido)."
            )
        return v


@lru_cache
def get_settings() -> "Settings":
    return Settings()
