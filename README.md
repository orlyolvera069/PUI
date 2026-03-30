# PUI — API Plataforma Única de Identidad (Manual Técnico)

Servicio PHP independiente del core **MCM Cultiva**. El código se movió desde `cultiva/backend` a este repositorio.

## Contenido

- `App/Pui/` — lógica del manual (login JWT, activar/desactivar reporte, orquestador fases 1–3, integración HTTP saliente).
- `public/api/pui/` — front controller JSON (`/api/pui/...`).
- `public/pui/` — pantallas y `pui-api.js` de prueba.
- `Core/Database.php` + `Core/App.php` — Oracle vía `App/config/database.ini` (no depende de `configuracion.ini` de Cultiva). Las tablas `PUI_*` y credenciales del módulo deben vivir en el usuario/esquema Oracle dedicado (p. ej. `PUI`), separado del esquema productivo principal, para facilitar auditorías. Lectura del padrón (`CL`, `EF`, `COL`) puede exigir `SELECT` concedido desde el esquema de negocio o sinónimos; si no hay sinónimos, usar `PUI_PADRON_SCHEMA` en `pui.ini` (ver comentario en el archivo).
- `Jobs/` — `JobTableRunner` (fase 3 / cola `PUI_JOBS`).
- `database/scripts/` — DDL tablas `PUI_*`.

## Puesta en marcha

1. Copiar `App/config/database.ini.example` → `App/config/database.ini` y completar Oracle: usuario del servicio PUI, `ESQUEMA` = servicio del listener, contraseña solo local. Misma instancia que Cultiva si el DBA otorga permisos o sinónimos hacia el padrón.
2. Ajustar `App/config/pui.ini` (no versionar secretos reales).
3. Desarrollo: `php -S localhost:8080 -t public public/router.php` → probar `GET /api/pui/salud`.
4. Jobs: desde la raíz del repo, `php Jobs/controllers/JobTableRunner.php run-once`.

## Git

```bash
git remote add origin <URL-del-repo-PUI>
git push -u origin main
```
