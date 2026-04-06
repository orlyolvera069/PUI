from __future__ import annotations

import asyncio
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta, timezone
from typing import Any

from app.core.logging_config import get_logger
from app.core.settings import Settings
from app.models.schemas import ActivarReporteRequest
from app.repositories.oracle_repository import (
    DatosBasicosRow,
    EventoHistoricoRow,
    OraclePersonasRepository,
)
from app.services.pui_client import PuiClient, PuiClientError
from app.utils.curp import lugar_nacimiento_desde_curp, normalizar_lugar_para_notificar

logger = get_logger(__name__)


def _parse_date(s: str | None) -> date | None:
    if s is None:
        return None
    t = s.strip()
    if not t:
        return None
    return date.fromisoformat(t)


def _hoy_cdmx() -> date:
    return datetime.now(timezone.utc).date()


def _ventana_historico(fecha_desaparicion: date) -> tuple[date, date]:
    """
    Manual 6: búsqueda histórica desde fecha de desaparición hasta hoy,
    acotada a máximo 12 años hacia atrás desde la fecha actual.
    """
    hoy = _hoy_cdmx()
    limite = hoy - timedelta(days=365 * 12)
    inicio = max(fecha_desaparicion, limite)
    return inicio, hoy


def _domicilio_desde_row(
    r: DatosBasicosRow | EventoHistoricoRow,
) -> dict[str, str] | None:
    partes: dict[str, str] = {}
    if r.direccion:
        partes["direccion"] = r.direccion
    if r.calle:
        partes["calle"] = r.calle
    if r.numero:
        partes["numero"] = r.numero
    if r.colonia:
        partes["colonia"] = r.colonia
    if r.codigo_postal:
        partes["codigo_postal"] = r.codigo_postal
    if r.municipio_o_alcaldia:
        partes["municipio_o_alcaldia"] = r.municipio_o_alcaldia
    if r.entidad_federativa:
        partes["entidad_federativa"] = r.entidad_federativa
    return partes or None


def _nombre_completo_desde_row(
    r: DatosBasicosRow | EventoHistoricoRow,
) -> dict[str, str] | None:
    if not any([r.nombre, r.primer_apellido, r.segundo_apellido]):
        return None
    out: dict[str, str] = {}
    if r.nombre:
        out["nombre"] = r.nombre
    if r.primer_apellido:
        out["primer_apellido"] = r.primer_apellido
    if r.segundo_apellido:
        out["segundo_apellido"] = r.segundo_apellido
    return out or None


def _lugar_nacimiento_final(curp: str, explicit: str | None) -> str:
    if explicit and explicit.strip():
        return normalizar_lugar_para_notificar(explicit.strip())
    return lugar_nacimiento_desde_curp(curp)


def _tiene_datos_basicos_utiles(row: DatosBasicosRow) -> bool:
    """Manual 6 fase 1: omitir notificación si no hay ningún dato útil."""
    return any(
        [
            row.nombre,
            row.primer_apellido,
            row.segundo_apellido,
            row.fecha_nacimiento,
            row.telefono,
            row.correo,
            row.direccion,
            row.calle,
            row.numero,
            row.colonia,
            row.codigo_postal,
            row.municipio_o_alcaldia,
            row.entidad_federativa,
            row.sexo_asignado,
        ]
    )


def _payload_fase1(
    settings: Settings,
    reporte_id: str,
    curp: str,
    row: DatosBasicosRow,
) -> dict[str, Any]:
    """Manual 6 / 7.2: fase 1 sin campos de evento."""
    lugar = _lugar_nacimiento_final(curp, row.lugar_nacimiento)
    payload: dict[str, Any] = {
        "curp": curp.upper(),
        "lugar_nacimiento": lugar,
        "id": reporte_id,
        "institucion_id": settings.pui_institucion_id.upper(),
        "fase_busqueda": "1",
    }
    nc = _nombre_completo_desde_row(row)
    if nc:
        payload["nombre_completo"] = nc
    if row.fecha_nacimiento:
        payload["fecha_nacimiento"] = row.fecha_nacimiento
    if row.sexo_asignado:
        payload["sexo_asignado"] = row.sexo_asignado
    if row.telefono:
        payload["telefono"] = row.telefono
    if row.correo:
        payload["correo"] = row.correo
    dom = _domicilio_desde_row(row)
    if dom:
        payload["domicilio"] = dom
    return payload


def _payload_fase2_o_3(
    settings: Settings,
    reporte_id: str,
    curp: str,
    row: EventoHistoricoRow,
    fase: str,
) -> dict[str, Any]:
    """Manual 6: fases 2 y 3 incluyen datos de evento."""
    lugar = _lugar_nacimiento_final(curp, row.lugar_nacimiento)
    payload: dict[str, Any] = {
        "curp": curp.upper(),
        "lugar_nacimiento": lugar,
        "id": reporte_id,
        "institucion_id": settings.pui_institucion_id.upper(),
        "fase_busqueda": fase,
        "tipo_evento": row.tipo_evento,
        "fecha_evento": row.fecha_evento,
    }
    if row.descripcion_lugar_evento:
        payload["descripcion_lugar_evento"] = row.descripcion_lugar_evento
    nc = _nombre_completo_desde_row(row)
    if nc:
        payload["nombre_completo"] = nc
    if row.fecha_nacimiento:
        payload["fecha_nacimiento"] = row.fecha_nacimiento
    if row.sexo_asignado:
        payload["sexo_asignado"] = row.sexo_asignado
    if row.telefono:
        payload["telefono"] = row.telefono
    if row.correo:
        payload["correo"] = row.correo
    dom = _domicilio_desde_row(row)
    if dom:
        payload["domicilio"] = dom
    de = row.direccion_evento or {}
    ev_obj: dict[str, str] = {}
    for k in (
        "direccion",
        "calle",
        "numero",
        "colonia",
        "codigo_postal",
        "municipio_o_alcaldia",
        "entidad_federativa",
    ):
        v = de.get(k)
        if v:
            ev_obj[k] = str(v)
    if ev_obj:
        payload["direccion_evento"] = ev_obj
    return payload


@dataclass
class ActiveContinuousReport:
    reporte_id: str
    curp: str
    ultima_revision: datetime


@dataclass
class ActiveReportRegistry:
    """Persistencia en memoria de reportes en búsqueda continua (fase 3)."""

    _lock: asyncio.Lock = field(default_factory=asyncio.Lock)
    _by_id: dict[str, ActiveContinuousReport] = field(default_factory=dict)

    async def registrar(self, rep: ActiveContinuousReport) -> None:
        async with self._lock:
            self._by_id[rep.reporte_id] = rep

    async def quitar(self, reporte_id: str) -> None:
        async with self._lock:
            self._by_id.pop(reporte_id, None)

    async def listar(self) -> list[ActiveContinuousReport]:
        async with self._lock:
            return list(self._by_id.values())

    async def actualizar_revision(self, reporte_id: str, ts: datetime) -> None:
        async with self._lock:
            if reporte_id in self._by_id:
                self._by_id[reporte_id].ultima_revision = ts


class ReporteService:
    def __init__(
        self,
        settings: Settings,
        repo: OraclePersonasRepository,
        pui: PuiClient,
        registry: ActiveReportRegistry,
    ) -> None:
        self._settings = settings
        self._repo = repo
        self._pui = pui
        self._registry = registry

    async def procesar_activar_reporte(self, req: ActivarReporteRequest) -> None:
        """
        Flujo obligatorio manual 6:
        1) Fase 1 → /notificar-coincidencia (sin evento) si aplica
        2) Fase 2 → por cada coincidencia con evento; omitir fase 2 si no hay fecha_desaparicion
        3) /busqueda-finalizada siempre
        4) Registrar para fase 3 (búsqueda continua)
        """
        curp = req.curp.upper()
        reporte_id = req.id

        # --- Fase 1
        basicos = self._repo.buscar_datos_basicos_recientes(curp)
        if basicos is not None and _tiene_datos_basicos_utiles(basicos):
            try:
                payload = _payload_fase1(self._settings, reporte_id, curp, basicos)
                await self._pui.notificar_coincidencia(payload)
                logger.info("fase1_notificada", id=reporte_id, curp=curp)
            except PuiClientError as exc:
                logger.error("fase1_error_pui", error=str(exc), id=reporte_id)
                raise

        # --- Fase 2
        fd = _parse_date(req.fecha_desaparicion)
        if fd is not None:
            ini, fin = _ventana_historico(fd)
            rows = self._repo.buscar_historico(curp, ini, fin)
            for row in rows:
                try:
                    pl = _payload_fase2_o_3(self._settings, reporte_id, curp, row, "2")
                    await self._pui.notificar_coincidencia(pl)
                    logger.info("fase2_notificada", id=reporte_id, curp=curp)
                except PuiClientError as exc:
                    logger.error("fase2_error_pui", error=str(exc), id=reporte_id)
                    raise
        else:
            logger.info("fase2_omitida_sin_fecha_desaparicion", id=reporte_id, curp=curp)

        # --- Cierre búsqueda histórica (siempre)
        try:
            await self._pui.busqueda_finalizada(reporte_id)
            logger.info("busqueda_finalizada_ok", id=reporte_id)
        except PuiClientError as exc:
            logger.error("busqueda_finalizada_error", error=str(exc), id=reporte_id)
            raise

        # --- Fase 3: registro para búsqueda continua hasta /desactivar-reporte
        await self._registry.registrar(
            ActiveContinuousReport(
                reporte_id=reporte_id,
                curp=curp,
                ultima_revision=datetime.now(timezone.utc),
            )
        )
        logger.info("fase3_registrada", id=reporte_id, curp=curp)

    async def desactivar(self, reporte_id: str) -> None:
        await self._registry.quitar(reporte_id)
        logger.info("reporte_desactivado_local", id=reporte_id)

    async def ejecutar_ciclo_busqueda_continua(self) -> None:
        """Un ciclo de fase 3 para todos los reportes activos."""
        activos = await self._registry.listar()
        ahora = datetime.now(timezone.utc)
        for ar in activos:
            try:
                rows = self._repo.buscar_nuevos_o_modificados(ar.curp, ar.ultima_revision)
                for row in rows:
                    pl = _payload_fase2_o_3(self._settings, ar.reporte_id, ar.curp, row, "3")
                    await self._pui.notificar_coincidencia(pl)
                    logger.info("fase3_notificada", id=ar.reporte_id, curp=ar.curp)
                await self._registry.actualizar_revision(ar.reporte_id, ahora)
            except PuiClientError as exc:
                logger.error(
                    "fase3_error_pui",
                    error=str(exc),
                    id=ar.reporte_id,
                    curp=ar.curp,
                )
            except Exception as exc:
                logger.exception("fase3_error", error=str(exc), id=ar.reporte_id)
