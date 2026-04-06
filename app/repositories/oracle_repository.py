from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime
from typing import Any

import oracledb

from app.core.logging_config import get_logger
from app.core.settings import Settings

logger = get_logger(__name__)


@dataclass
class DatosBasicosRow:
    """Datos recientes para completar ficha (fase 1)."""

    curp: str
    nombre: str | None
    primer_apellido: str | None
    segundo_apellido: str | None
    fecha_nacimiento: str | None
    lugar_nacimiento: str | None
    sexo_asignado: str | None
    telefono: str | None
    correo: str | None
    direccion: str | None
    calle: str | None
    numero: str | None
    colonia: str | None
    codigo_postal: str | None
    municipio_o_alcaldia: str | None
    entidad_federativa: str | None


@dataclass
class EventoHistoricoRow:
    """Coincidencia histórica (fase 2) con contexto de evento."""

    curp: str
    nombre: str | None
    primer_apellido: str | None
    segundo_apellido: str | None
    fecha_nacimiento: str | None
    lugar_nacimiento: str | None
    sexo_asignado: str | None
    telefono: str | None
    correo: str | None
    direccion: str | None
    calle: str | None
    numero: str | None
    colonia: str | None
    codigo_postal: str | None
    municipio_o_alcaldia: str | None
    entidad_federativa: str | None
    tipo_evento: str
    fecha_evento: str
    descripcion_lugar_evento: str | None
    direccion_evento: dict[str, str | None]


class OraclePersonasRepository:
    """
    Consultas simuladas / reales a Oracle.
    Ajuste los nombres de tablas/columnas según el esquema ESIACOM real.
    """

    def __init__(self, settings: Settings, pool: oracledb.ConnectionPool | None) -> None:
        self._settings = settings
        self._pool = pool

    def _acquire(self) -> oracledb.Connection:
        if self._pool is None:
            raise RuntimeError("Pool Oracle no disponible")
        conn = self._pool.acquire()
        try:
            with conn.cursor() as cur:
                sch = self._settings.oracle_schema.upper()
                if not sch.replace("_", "").isalnum():
                    raise ValueError("oracle_schema inválido")
                cur.execute(f"ALTER SESSION SET CURRENT_SCHEMA = {sch}")
        except Exception:
            self._pool.release(conn)
            raise
        return conn

    def _row_to_basicos(self, row: dict[str, Any]) -> DatosBasicosRow:
        return DatosBasicosRow(
            curp=str(row["curp"]),
            nombre=row.get("nombre"),
            primer_apellido=row.get("primer_apellido"),
            segundo_apellido=row.get("segundo_apellido"),
            fecha_nacimiento=row.get("fecha_nacimiento"),
            lugar_nacimiento=row.get("lugar_nacimiento"),
            sexo_asignado=row.get("sexo_asignado"),
            telefono=row.get("telefono"),
            correo=row.get("correo"),
            direccion=row.get("direccion"),
            calle=row.get("calle"),
            numero=row.get("numero"),
            colonia=row.get("colonia"),
            codigo_postal=row.get("codigo_postal"),
            municipio_o_alcaldia=row.get("municipio_o_alcaldia"),
            entidad_federativa=row.get("entidad_federativa"),
        )

    def buscar_datos_basicos_recientes(self, curp: str) -> DatosBasicosRow | None:
        """
        Fase 1: valores más recientes disponibles para completar datos básicos.
        Simulación: si CURP termina en 0, no hay datos; si no, devuelve un registro de ejemplo.
        """
        if self._settings.oracle_simulate or self._pool is None:
            return self._simular_basicos(curp)

        sql = """
            SELECT curp, nombre, primer_apellido, segundo_apellido,
                   TO_CHAR(fecha_nacimiento, 'YYYY-MM-DD') AS fecha_nacimiento,
                   lugar_nacimiento, sexo_asignado, telefono, correo,
                   direccion, calle, numero, colonia, codigo_postal,
                   municipio_o_alcaldia, entidad_federativa
            FROM pui_v_persona_reciente
            WHERE curp = :curp
            FETCH FIRST 1 ROWS ONLY
        """
        try:
            conn = self._acquire()
        except Exception as exc:
            logger.warning("oracle_pool_acquire_failed", error=str(exc))
            return self._simular_basicos(curp)

        try:
            with conn.cursor() as cur:
                cur.execute(sql, {"curp": curp.upper()})
                cols = [d[0].lower() for d in cur.description]
                row = cur.fetchone()
                if not row:
                    return None
                data = dict(zip(cols, row))
                return self._row_to_basicos(data)
        except Exception as exc:
            logger.warning("oracle_query_basicos_failed", error=str(exc), curp=curp)
            return self._simular_basicos(curp)
        finally:
            self._pool.release(conn)

    def _simular_basicos(self, curp: str) -> DatosBasicosRow | None:
        """
        Simulación determinista: sin datos si la última cifra es 0; con datos en otro caso.
        """
        if curp[-1:] == "0":
            return None
        return DatosBasicosRow(
            curp=curp.upper(),
            nombre="JUAN",
            primer_apellido="PEREZ",
            segundo_apellido="LOPEZ",
            fecha_nacimiento="1990-01-01",
            lugar_nacimiento="CDMX",
            sexo_asignado="H",
            telefono="5512345678",
            correo="juan.perez@example.com",
            direccion="CALLE FICTICIA 123",
            calle="CALLE FICTICIA",
            numero="123",
            colonia="CENTRO",
            codigo_postal="06000",
            municipio_o_alcaldia="CUAUHTÉMOC",
            entidad_federativa="CDMX",
        )

    def buscar_historico(
        self,
        curp: str,
        fecha_inicio: date,
        fecha_fin: date,
    ) -> list[EventoHistoricoRow]:
        """Fase 2: eventos administrativos entre fechas (inclusive)."""
        if self._settings.oracle_simulate or self._pool is None:
            return self._simular_historico(curp, fecha_inicio, fecha_fin)

        sql = """
            SELECT curp, nombre, primer_apellido, segundo_apellido,
                   TO_CHAR(fecha_nacimiento, 'YYYY-MM-DD') AS fecha_nacimiento,
                   lugar_nacimiento, sexo_asignado, telefono, correo,
                   direccion, calle, numero, colonia, codigo_postal,
                   municipio_o_alcaldia, entidad_federativa,
                   tipo_evento,
                   TO_CHAR(fecha_evento, 'YYYY-MM-DD') AS fecha_evento,
                   descripcion_lugar_evento,
                   direccion_evento, calle_evento, numero_evento, colonia_evento,
                   codigo_postal_evento, municipio_evento, entidad_evento
            FROM pui_v_eventos_historico
            WHERE curp = :curp
              AND fecha_evento BETWEEN :ini AND :fin
            ORDER BY fecha_evento ASC
        """
        try:
            conn = self._acquire()
        except Exception as exc:
            logger.warning("oracle_acquire_failed_historico", error=str(exc))
            return self._simular_historico(curp, fecha_inicio, fecha_fin)

        try:
            with conn.cursor() as cur:
                cur.execute(
                    sql,
                    {
                        "curp": curp.upper(),
                        "ini": fecha_inicio,
                        "fin": fecha_fin,
                    },
                )
                cols = [d[0].lower() for d in cur.description]
                out: list[EventoHistoricoRow] = []
                for row in cur.fetchall():
                    d = dict(zip(cols, row))
                    direccion_evento = {
                        "direccion": d.get("direccion_evento"),
                        "calle": d.get("calle_evento"),
                        "numero": d.get("numero_evento"),
                        "colonia": d.get("colonia_evento"),
                        "codigo_postal": d.get("codigo_postal_evento"),
                        "municipio_o_alcaldia": d.get("municipio_evento"),
                        "entidad_federativa": d.get("entidad_evento"),
                    }
                    out.append(
                        EventoHistoricoRow(
                            curp=str(d["curp"]),
                            nombre=d.get("nombre"),
                            primer_apellido=d.get("primer_apellido"),
                            segundo_apellido=d.get("segundo_apellido"),
                            fecha_nacimiento=d.get("fecha_nacimiento"),
                            lugar_nacimiento=d.get("lugar_nacimiento"),
                            sexo_asignado=d.get("sexo_asignado"),
                            telefono=d.get("telefono"),
                            correo=d.get("correo"),
                            direccion=d.get("direccion"),
                            calle=d.get("calle"),
                            numero=d.get("numero"),
                            colonia=d.get("colonia"),
                            codigo_postal=d.get("codigo_postal"),
                            municipio_o_alcaldia=d.get("municipio_o_alcaldia"),
                            entidad_federativa=d.get("entidad_federativa"),
                            tipo_evento=str(d.get("tipo_evento") or ""),
                            fecha_evento=str(d.get("fecha_evento") or ""),
                            descripcion_lugar_evento=d.get("descripcion_lugar_evento"),
                            direccion_evento=direccion_evento,
                        )
                    )
                return out
        except Exception as exc:
            logger.warning("oracle_query_historico_failed", error=str(exc), curp=curp)
            return self._simular_historico(curp, fecha_inicio, fecha_fin)
        finally:
            self._pool.release(conn)

    def _simular_historico(
        self,
        curp: str,
        fecha_inicio: date,
        fecha_fin: date,
    ) -> list[EventoHistoricoRow]:
        """Un evento de ejemplo si el rango es válido y no termina en 0."""
        if curp[-1:] == "0":
            return []
        if fecha_fin < fecha_inicio:
            return []
        return [
            EventoHistoricoRow(
                curp=curp.upper(),
                nombre="JUAN",
                primer_apellido="PEREZ",
                segundo_apellido="LOPEZ",
                fecha_nacimiento="1990-01-01",
                lugar_nacimiento="CDMX",
                sexo_asignado="H",
                telefono="5512345678",
                correo="juan.perez@example.com",
                direccion="CALLE FICTICIA 123",
                calle="CALLE FICTICIA",
                numero="123",
                colonia="CENTRO",
                codigo_postal="06000",
                municipio_o_alcaldia="CUAUHTÉMOC",
                entidad_federativa="CDMX",
                tipo_evento="Apertura de cuenta bancaria",
                fecha_evento="2025-05-21",
                descripcion_lugar_evento="Sucursal 22 Lomas Estrella",
                direccion_evento={
                    "direccion": "Cerrada Zacarías Topete 1562 Int. B",
                    "calle": "Cerrada Zacarías Topete",
                    "numero": "1562 Int. B",
                    "colonia": "De los Ángeles",
                    "codigo_postal": "01245",
                    "municipio_o_alcaldia": "San Luis Potosí",
                    "entidad_federativa": "SAN LUIS POTOSÍ",
                },
            )
        ]

    def buscar_nuevos_o_modificados(
        self,
        curp: str,
        desde: datetime,
    ) -> list[EventoHistoricoRow]:
        """
        Fase 3: registros nuevos o modificados posteriores a `desde`.
        Simulación: devuelve lista vacía salvo si minuto actual es múltiplo de 7 (demo).
        """
        if self._settings.oracle_simulate or self._pool is None:
            return self._simular_continuo(curp, desde)

        sql = """
            SELECT curp, nombre, primer_apellido, segundo_apellido,
                   TO_CHAR(fecha_nacimiento, 'YYYY-MM-DD') AS fecha_nacimiento,
                   lugar_nacimiento, sexo_asignado, telefono, correo,
                   direccion, calle, numero, colonia, codigo_postal,
                   municipio_o_alcaldia, entidad_federativa,
                   tipo_evento,
                   TO_CHAR(fecha_evento, 'YYYY-MM-DD') AS fecha_evento,
                   descripcion_lugar_evento,
                   direccion_evento, calle_evento, numero_evento, colonia_evento,
                   codigo_postal_evento, municipio_evento, entidad_evento
            FROM pui_v_eventos_cambios
            WHERE curp = :curp
              AND fecha_modificacion > :desde
            ORDER BY fecha_modificacion ASC
        """
        try:
            conn = self._acquire()
        except Exception as exc:
            logger.warning("oracle_acquire_failed_continuo", error=str(exc))
            return self._simular_continuo(curp, desde)

        try:
            with conn.cursor() as cur:
                cur.execute(sql, {"curp": curp.upper(), "desde": desde})
                cols = [d[0].lower() for d in cur.description]
                out: list[EventoHistoricoRow] = []
                for row in cur.fetchall():
                    d = dict(zip(cols, row))
                    direccion_evento = {
                        "direccion": d.get("direccion_evento"),
                        "calle": d.get("calle_evento"),
                        "numero": d.get("numero_evento"),
                        "colonia": d.get("colonia_evento"),
                        "codigo_postal": d.get("codigo_postal_evento"),
                        "municipio_o_alcaldia": d.get("municipio_evento"),
                        "entidad_federativa": d.get("entidad_evento"),
                    }
                    out.append(
                        EventoHistoricoRow(
                            curp=str(d["curp"]),
                            nombre=d.get("nombre"),
                            primer_apellido=d.get("primer_apellido"),
                            segundo_apellido=d.get("segundo_apellido"),
                            fecha_nacimiento=d.get("fecha_nacimiento"),
                            lugar_nacimiento=d.get("lugar_nacimiento"),
                            sexo_asignado=d.get("sexo_asignado"),
                            telefono=d.get("telefono"),
                            correo=d.get("correo"),
                            direccion=d.get("direccion"),
                            calle=d.get("calle"),
                            numero=d.get("numero"),
                            colonia=d.get("colonia"),
                            codigo_postal=d.get("codigo_postal"),
                            municipio_o_alcaldia=d.get("municipio_o_alcaldia"),
                            entidad_federativa=d.get("entidad_federativa"),
                            tipo_evento=str(d.get("tipo_evento") or ""),
                            fecha_evento=str(d.get("fecha_evento") or ""),
                            descripcion_lugar_evento=d.get("descripcion_lugar_evento"),
                            direccion_evento=direccion_evento,
                        )
                    )
                return out
        except Exception as exc:
            logger.warning("oracle_query_continuo_failed", error=str(exc), curp=curp)
            return self._simular_continuo(curp, desde)
        finally:
            self._pool.release(conn)

    def _simular_continuo(self, curp: str, desde: datetime) -> list[EventoHistoricoRow]:
        """Demo: sin coincidencias para no saturar; ajuste según pruebas."""
        _ = (curp, desde)
        return []
