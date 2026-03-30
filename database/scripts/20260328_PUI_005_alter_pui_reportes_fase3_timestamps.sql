-- Marca temporal de fin de fase 2 (inicio incremental para primera ejecución de fase 3)
-- y última ejecución completada de fase 3 (búsqueda continua).

ALTER TABLE PUI_REPORTES_ACTIVOS ADD (
    FECHA_FIN_FASE2          TIMESTAMP,
    ULTIMA_EJECUCION_FASE3   TIMESTAMP
);
