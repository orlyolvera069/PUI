from __future__ import annotations

from app.core.security import create_access_token
from app.core.settings import Settings
from app.models.schemas import LoginRequest
from app.utils.password_policy import clave_cumple_politica_manual_81


def autenticar_pui(req: LoginRequest, settings: Settings) -> str:
    if req.usuario != "PUI":
        raise PermissionError("Usuario no autorizado")
    if not clave_cumple_politica_manual_81(req.clave):
        raise ValueError("La clave no cumple la política del manual 8.1")
    if req.clave != settings.pui_webhook_password:
        raise PermissionError("Credenciales inválidas")
    return create_access_token(subject="PUI", settings=settings, extra_claims={"rol": "pui"})
