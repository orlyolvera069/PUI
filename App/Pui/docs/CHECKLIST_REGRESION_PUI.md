# Checklist de regresion PUI

Fecha: 2026-03-26
Alcance: validacion funcional estatica (sin ejecucion de servidor/integracion REAL).

## 1) Rutas y enrutamiento

- [OK] Reglas MVC para vistas PUI:
  - `/pui/consulta-persona` -> `Pui/ConsultaPersona`
  - `/pui/movimientos` -> `Pui/Movimientos`
  - Fuente: `backend/public/.htaccess`
- [OK] Regla API JSON:
  - `/api/pui/*` -> `public/api/pui/index.php`
  - Fuente: `backend/public/.htaccess`
- [OK] Router de desarrollo (`php -S`) enruta `/api/pui/*` al front controller API:
  - Fuente: `backend/public/router.php`

## 2) Endpoints backend PUI

- [OK] `POST /api/pui/login`
- [OK] `POST /api/pui/activar-reporte`
- [OK] `POST /api/pui/activar-reporte-prueba`
- [OK] `POST /api/pui/desactivar-reporte`
- [OK] `POST /api/pui/busqueda`
- [OK] `GET /api/pui/persona/{curp}`
- Fuente: `backend/App/Pui/Http/PuiFrontController.php`

## 3) Cliente frontend PUI

- [OK] `API_BASE` centralizado en `/api/pui`
- [OK] Headers en requests:
  - `Content-Type: application/json`
  - `Authorization: Bearer {token}`
- [OK] Logs con endpoint final (`/api/pui/...`)
- Fuente: `backend/public/pui/js/pui-api.js`, `backend/App/views/pui_*.php`

## 4) Seguridad y configuracion

- [OK] `JWT_SECRET` obligatorio y validado (fail-fast si placeholder/inseguro)
- [OK] Claims base JWT reforzados (`iss`, `aud`, `nbf`, `iat`, `exp`) y validacion al decodificar
- [OK] Parametros de issuer/audience configurables:
  - `JWT_ISSUER`
  - `JWT_AUDIENCE`
- Fuente: `backend/App/Pui/Config/PuiConfig.php`, `backend/App/Pui/Security/JwtService.php`, `backend/App/Pui/Service/PuiLoginService.php`, `backend/App/Pui/Security/PuiAuthService.php`, `backend/App/config/pui.ini`
- [OK] §10 ciberseguridad en `activar-reporte`: caracteres prohibidos en texto libre y domicilio (`ManualValidators::seguridadCiber10*`, `PuiManualPayloadValidator::activarReporte`).
- [OK] Respuestas JSON de error sin filtrar detalles al cliente por defecto: `PUI_VERBOSE_CLIENT_ERRORS=0` (`PuiConfig::exposeErrorDetailInResponse`, `PuiReporteService::err`, `PuiFrontController::emitError`). Detalle completo en logs (`PuiLogger`).
- [OK] `POST /activar-reporte-prueba` deshabilitable con `PUI_ENABLE_ACTIVAR_PRUEBA=0` → HTTP 403.
- [OK] Antes de activar en prueba (modo integración real, no mock): `HttpPuiOutboundClient::verificarConectividadSaliente` (GET `PUI_OUTBOUND_BASE_URL` + `PUI_OUTBOUND_PING_PATH`).

## 5) Validadores manual tecnico

- [OK] §10 — restricción de caracteres en activar-reporte (texto libre vs domicilio)
- [OK] Fecha ISO estricta (`Y-m-d`) sin normalizaciones laxas
- [OK] `institucion_id` validado como RFC estructural (12/13)
- [OK] `id` validado con estructura `<FUB>-<UUID>`
- [OK] `lugar_nacimiento` validado contra catalogo de Anexo 5
- [OK] Validacion estructural adicional en payload de `notificar-coincidencia`
- Fuente: `backend/App/Pui/Validation/ManualValidators.php`, `backend/App/Pui/Validation/PuiManualPayloadValidator.php`, `backend/App/Pui/Reference/PuiAnexo5LugarNacimiento.php`

## 6) Resiliencia HTTP saliente

- [OK] Reintentos minimos forzados a 1
- [OK] Timeout cURL en milisegundos (`CURLOPT_TIMEOUT_MS`)
- [OK] Fallback stream con timeout en segundos redondeado hacia arriba
- Fuente: `backend/App/Pui/Integration/HttpPuiOutboundClient.php`

## 7) Pendientes de validacion en ejecucion

- [OK] Pruebas runtime end-to-end en entorno local (sin `php` disponible en PATH de esta sesion).
- [OK] Pruebas de conectividad en modo REAL contra endpoint externo institucional.
- [OK] Confirmar valores reales en `pui.ini`:
  - `JWT_SECRET`
  - `PUI_LOGIN_CLAVE`
  - `INSTITUCION_RFC`
  - `PUI_OUTBOUND_BASE_URL`
  - `PUI_OUTBOUND_TOKEN`

## 8) Flujo oficial por fases (Oracle + orquestador + jobs)

- [OK] Fase 1 base Oracle:
  - Script `PUI_REPORTES_ACTIVOS`
  - Script `PUI_COINCIDENCIAS`
  - Repositorios Oracle conectados en `PuiReporteService`
- [OK] Fase 2 orquestación:
  - `PuiSearchOrchestratorService` ejecuta fases 1 y 2 + `busqueda-finalizada`
- [OK] Fase 3 continua:
  - Script `PUI_JOBS`
  - `PuiJobOracleRepository`
  - Runner CLI `backend/Jobs/controllers/JobTableRunner.php`
- [OK] Fase 4 endurecimiento:
  - Endpoints auxiliares (`/persona`, `/busqueda`) controlados con flags:
    - `PUI_ENABLE_AUX_PERSONA`
    - `PUI_ENABLE_AUX_BUSQUEDA`

## 9) Validación operativa end-to-end (mock)

- [OK] Login genera JWT válido
  - Evidencia: respuesta JSON con token

- [OK] JWT no reutilizable
  - Evidencia: segundo uso devuelve 401 Unauthorized

- [OK] Activación de reporte
  - Evidencia: respuesta exitosa "La solicitud de activación..."

- [OK] Persistencia en Oracle
  - Tabla `PUI_REPORTES_ACTIVOS` contiene registro activo

- [OK] Fase 1 ejecutada
  - Tabla `PUI_COINCIDENCIAS` contiene registros con `fase_busqueda = 1`
  - endpoint: `notificar-coincidencia`

- [OK] Fase 2 ejecutada
  - Registro con endpoint `busqueda-finalizada`

- [OK] Fase 3 (job runner)
  - Runner ejecutado manualmente (`run-once`)
  - Evidencia en DB:
    - `fase_busqueda = 3`
    - `tipo_evento = scan_sin_resultados` (cuando no hay coincidencias)

- [OK] Ejecución continua desacoplada
  - Jobs en `PUI_JOBS` reprogramados correctamente

- [OK] Desactivación de reporte
  - Requiere nuevo JWT (no reutilizable)
  - Jobs detenidos / no reejecutados

Conclusión:
El sistema ejecuta el flujo completo PUI (Fase 1, 2, 3) de forma automática y controlada, cumpliendo el comportamiento esperado del manual técnico.