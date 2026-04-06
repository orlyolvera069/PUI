from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Request, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.core.logging_config import get_logger
from app.core.security import verify_bearer_token
from app.core.settings import Settings, get_settings
from app.models.schemas import (
    ActivarReporteRequest,
    ActivarReporteResponse,
    DesactivarReporteRequest,
    DesactivarReporteResponse,
    LoginRequest,
    LoginResponse,
)
from app.services.auth_service import autenticar_pui
from app.services.pui_client import PuiClient, PuiClientError
from app.services.reporte_service import ReporteService

logger = get_logger(__name__)
router = APIRouter()
bearer_scheme = HTTPBearer(auto_error=False)


def _get_reporte_service(request: Request) -> ReporteService:
    svc = getattr(request.app.state, "reporte_service", None)
    if svc is None:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Servicio no inicializado",
        )
    return svc


async def requiere_jwt_pui(
    creds: HTTPAuthorizationCredentials | None = Depends(bearer_scheme),
    settings: Settings = Depends(get_settings),
) -> dict:
    if creds is None or not creds.credentials:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token no proporcionado",
            headers={"WWW-Authenticate": "Bearer"},
        )
    try:
        return verify_bearer_token(creds.credentials, settings)
    except ValueError as exc:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail=str(exc),
            headers={"WWW-Authenticate": "Bearer"},
        ) from exc


@router.post(
    "/login",
    response_model=LoginResponse,
    summary="Autenticación JWT (manual 8.1)",
)
def login(
    body: LoginRequest,
    settings: Settings = Depends(get_settings),
) -> LoginResponse:
    try:
        token = autenticar_pui(body, settings)
        return LoginResponse(token=token)
    except ValueError as exc:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)) from exc
    except PermissionError as exc:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail=str(exc)) from exc


@router.post(
    "/activar-reporte",
    response_model=ActivarReporteResponse,
    summary="Activar reporte de búsqueda (manual 8.2)",
)
async def activar_reporte(
    body: ActivarReporteRequest,
    _: dict = Depends(requiere_jwt_pui),
    svc: ReporteService = Depends(_get_reporte_service),
) -> ActivarReporteResponse:
    try:
        await svc.procesar_activar_reporte(body)
        return ActivarReporteResponse()
    except PuiClientError as exc:
        logger.error("activar_reporte_pui_error", error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Error al comunicarse con la Plataforma Única de Identidad",
        ) from exc


@router.post(
    "/activar-reporte-prueba",
    summary="Activar reporte de prueba (manual 8.3)",
)
async def activar_reporte_prueba(
    body: ActivarReporteRequest,
    request: Request,
    _: dict = Depends(requiere_jwt_pui),
) -> dict[str, str]:
    """
    Valida payload y verifica conectividad con la PUI (login).
    No ejecuta el flujo completo para no enviar datos de prueba a producción.
    """
    _ = body
    pui: PuiClient | None = getattr(request.app.state, "pui_client", None)
    if pui is None:
        raise HTTPException(status_code=503, detail="Cliente PUI no inicializado")
    try:
        await pui.obtener_token()
    except PuiClientError as exc:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail=f"No hay conectividad con la PUI: {exc}",
        ) from exc
    return {"message": "Prueba recibida y conectividad con la PUI verificada correctamente."}


@router.post(
    "/desactivar-reporte",
    response_model=DesactivarReporteResponse,
    summary="Desactivar reporte (manual 8.4)",
)
async def desactivar_reporte(
    body: DesactivarReporteRequest,
    _: dict = Depends(requiere_jwt_pui),
    svc: ReporteService = Depends(_get_reporte_service),
) -> DesactivarReporteResponse:
    await svc.desactivar(body.id)
    return DesactivarReporteResponse()
