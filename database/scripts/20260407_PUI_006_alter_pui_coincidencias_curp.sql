-- PUI: columna explícita CURP por coincidencia notificada (§7.2), alineada a auditoría y conteos.
ALTER TABLE PUI_COINCIDENCIAS ADD (CURP VARCHAR2(18));
