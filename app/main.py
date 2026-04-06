from __future__ import annotations

import asyncio
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.controllers.webhook_controller import router as webhook_router
from app.core.logging_config import configure_logging, get_logger
from app.core.settings import get_settings
from app.repositories.oracle_pool import create_pool
from app.repositories.oracle_repository import OraclePersonasRepository
from app.services.pui_client import PuiClient
from app.services.reporte_service import ActiveReportRegistry, ReporteService

logger = get_logger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    configure_logging(settings.debug)

    pool = create_pool(settings)
    repo = OraclePersonasRepository(settings, pool)
    registry = ActiveReportRegistry()
    pui = PuiClient(settings)
    reporte = ReporteService(settings, repo, pui, registry)

    app.state.settings = settings
    app.state.oracle_pool = pool
    app.state.reporte_service = reporte
    app.state.pui_client = pui

    async def ciclo_fase3() -> None:
        while True:
            await asyncio.sleep(settings.continuous_search_interval_seconds)
            try:
                await reporte.ejecutar_ciclo_busqueda_continua()
            except Exception:
                logger.exception("error_en_ciclo_busqueda_continua")

    task = asyncio.create_task(ciclo_fase3())

    yield

    task.cancel()
    try:
        await task
    except asyncio.CancelledError:
        pass
    if pool is not None:
        try:
            pool.close()
        except Exception as exc:
            logger.warning("oracle_pool_close_error", error=str(exc))


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(
        title=settings.app_name,
        lifespan=lifespan,
        openapi_tags=[{"name": "PUI", "description": "Integración institución diversa — Manual Técnico PUI"}],
    )

    @app.exception_handler(RequestValidationError)
    async def validation_handler(_request: Request, exc: RequestValidationError):
        return JSONResponse(status_code=422, content={"detail": exc.errors()})

    @app.get("/health", tags=["ops"])
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.get("/api/pui/salud", tags=["PUI"])
    def pui_salud() -> dict[str, str]:
        return {"status": "ok"}

    app.include_router(webhook_router)
    return app


app = create_app()
