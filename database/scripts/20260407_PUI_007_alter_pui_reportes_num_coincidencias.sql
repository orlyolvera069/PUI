-- PUI: total de notificaciones §7.2 exitosas (notificar-coincidencia HTTP 2xx) por reporte.
ALTER TABLE PUI_REPORTES_ACTIVOS ADD (NUM_COINCIDENCIAS NUMBER(10) DEFAULT 0 NOT NULL);
