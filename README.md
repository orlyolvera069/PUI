# PUI — API Plataforma Única de Identidad (Manual Técnico)

Servicio PHP independiente del core **MCM Cultiva**. El código se movió desde `cultiva/backend` a este repositorio.

## Contenido

- `App/Pui/` — lógica del manual (login JWT, activar/desactivar reporte, orquestador fases 1–3, integración HTTP saliente).
- `public/api/pui/` — front controller JSON (`/api/pui/...`).
- `public/pui/` — pantallas y `pui-api.js` de prueba.
- `Core/Database.php` + `Core/App.php` — Oracle vía `App/config/database.ini` (no depende de `configuracion.ini` de Cultiva).
- `Jobs/` — `JobTableRunner` (fase 3 / cola `PUI_JOBS`).
- `database/scripts/` — DDL tablas `PUI_*`.

## Puesta en marcha

1. Copiar `App/config/database.ini.example` → `App/config/database.ini` y completar Oracle (misma instancia que Cultiva si comparten esquema `CL` y tablas PUI).
2. Ajustar `App/config/pui.ini` (no versionar secretos reales).
3. Desarrollo: `php -S localhost:8080 -t public public/router.php` → probar `GET /api/pui/salud`.
4. Jobs: desde la raíz del repo, `php Jobs/controllers/JobTableRunner.php run-once`.

## Git

```bash
git remote add origin <URL-del-repo-PUI>
git push -u origin main
```
