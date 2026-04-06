from __future__ import annotations

import time
from typing import Any

import httpx
from jose import jwt as jose_jwt

from app.core.logging_config import get_logger
from app.core.settings import Settings

logger = get_logger(__name__)


class PuiClientError(Exception):
    """Error al consumir la API de la PUI."""


class PuiClient:
    """Cliente HTTP hacia los endpoints de la PUI (sección 7)."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._base = settings.pui_base_url.rstrip("/")
        self._token: str | None = None
        self._token_exp: float | None = None

    def _should_refresh_token(self) -> bool:
        if not self._token or not self._token_exp:
            return True
        # Manual 7.1: renovar antes del 80 % de vigencia (1 h → ~48 min)
        now = time.time()
        return now >= self._token_exp - 12 * 60

    async def obtener_token(self) -> str:
        if not self._should_refresh_token():
            assert self._token is not None
            return self._token

        url = f"{self._base}/login"
        body = {
            "institucion_id": self._settings.pui_institucion_id,
            "clave": self._settings.pui_clave,
        }
        async with httpx.AsyncClient(timeout=60.0) as client:
            r = await client.post(
                url,
                json=body,
                headers={"Content-Type": "application/json; charset=utf-8"},
            )
        if r.status_code == 403:
            raise PuiClientError("Credenciales inválidas en PUI")
        if r.status_code != 200:
            logger.error("pui_login_failed", status=r.status_code, body=r.text[:500])
            raise PuiClientError("No fue posible autenticarse en la PUI")
        data = r.json()
        token = data.get("token")
        if not token:
            raise PuiClientError("Respuesta de login sin token")
        self._token = token
        try:
            claims = jose_jwt.get_unverified_claims(token)
            self._token_exp = float(claims.get("exp", time.time() + 3600))
        except Exception:
            self._token_exp = time.time() + 3600
        return token

    async def notificar_coincidencia(self, payload: dict[str, Any]) -> None:
        token = await self.obtener_token()
        url = f"{self._base}/notificar-coincidencia"
        async with httpx.AsyncClient(timeout=120.0) as client:
            r = await client.post(
                url,
                json=payload,
                headers={
                    "Authorization": f"Bearer {token}",
                    "Content-Type": "application/json; charset=utf-8",
                },
            )
        if r.status_code == 401:
            self._token = None
            self._token_exp = None
            token = await self.obtener_token()
            async with httpx.AsyncClient(timeout=120.0) as client:
                r = await client.post(
                    url,
                    json=payload,
                    headers={
                        "Authorization": f"Bearer {token}",
                        "Content-Type": "application/json; charset=utf-8",
                    },
                )
        if r.status_code == 300:
            logger.warning("pui_notificar_multiples", id=payload.get("id"))
            return
        if r.status_code != 200:
            logger.error(
                "pui_notificar_error",
                status=r.status_code,
                body=r.text[:1000],
                fase=payload.get("fase_busqueda"),
            )
            raise PuiClientError(f"notificar-coincidencia falló: HTTP {r.status_code}")

    async def busqueda_finalizada(self, reporte_id: str) -> None:
        token = await self.obtener_token()
        url = f"{self._base}/busqueda-finalizada"
        body = {
            "id": reporte_id,
            "institucion_id": self._settings.pui_institucion_id,
        }
        async with httpx.AsyncClient(timeout=60.0) as client:
            r = await client.post(
                url,
                json=body,
                headers={
                    "Authorization": f"Bearer {token}",
                    "Content-Type": "application/json; charset=utf-8",
                },
            )
        if r.status_code == 401:
            self._token = None
            self._token_exp = None
            token = await self.obtener_token()
            async with httpx.AsyncClient(timeout=60.0) as client:
                r = await client.post(
                    url,
                    json=body,
                    headers={
                        "Authorization": f"Bearer {token}",
                        "Content-Type": "application/json; charset=utf-8",
                    },
                )
        if r.status_code != 200:
            logger.error("pui_busqueda_finalizada_error", status=r.status_code, body=r.text[:1000])
            raise PuiClientError(f"busqueda-finalizada falló: HTTP {r.status_code}")
