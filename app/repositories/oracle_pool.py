from __future__ import annotations

import oracledb

from app.core.logging_config import get_logger
from app.core.settings import Settings

logger = get_logger(__name__)


def create_pool(settings: Settings) -> oracledb.ConnectionPool | None:
    if settings.oracle_simulate:
        logger.info("oracle_pool_simulate_enabled", simulate=True)
        return None

    dsn = oracledb.makedsn(
        settings.oracle_host,
        settings.oracle_port,
        service_name=settings.oracle_service_name,
    )

    pool = oracledb.create_pool(
        user=settings.oracle_user,
        password=settings.oracle_password,
        dsn=dsn,
        min=settings.oracle_pool_min,
        max=settings.oracle_pool_max,
        increment=1,
    )

    logger.info(
        "oracle_pool_created",
        host=settings.oracle_host,
        port=settings.oracle_port,
        service_name=settings.oracle_service_name,
    )

    return pool
