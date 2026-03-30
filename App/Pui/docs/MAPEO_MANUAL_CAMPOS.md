# Tabla comparativa — implementación anterior vs Manual Técnico oficial (PUI v1.0)

| Área | Campo / comportamiento anterior | Manual oficial (sección) | Ajuste aplicado |
|------|--------------------------------|----------------------------|-----------------|
| `/notificar-coincidencia` | Objeto anidado `coincidencia` | Cuerpo plano; campos en raíz (§7.2) | Eliminado wrapper; `NotificarCoincidenciaPayloadFactory` genera JSON plano |
| `/notificar-coincidencia` | `fase_busqueda` numérico (1/2/3) | `String` `"1"`, `"2"` o `"3"`; regex `^[1-3]$` (§7.2) | Se envía siempre como cadena |
| `/notificar-coincidencia` | `nombre_completo` plano o claves distintas | `Object` con `nombre`, `primer_apellido`, `segundo_apellido` (§7.2) | Objeto anidado según manual |
| `/notificar-coincidencia` | `institucion_id` omitido o distinto | Obligatorio; `^[A-Z0-9]{4,13}$` (§7.2) | Tomado de `INSTITUCION_RFC` en `pui.ini` |
| `/notificar-coincidencia` | `lugar_nacimiento` opcional | Obligatorio; enum/Anexo 5 o `DESCONOCIDO` (§7.2) | Derivado de CURP vía `PuiAnexo5LugarNacimiento` |
| `/notificar-coincidencia` | `id` formato `FUB-UUID` libre | `<FUB>-<UUID4>`, longitud 36–75 (§7.2) | Regex `[A-Za-z0-9\-]{36,75}` + longitud |
| `/busqueda-finalizada` | Campos extra (`fases_completadas`, totales, etc.) | Solo `id` e `institucion_id` (§7.3) | Payload reducido a dos propiedades |
| `/activar-reporte` | `institucion_id` en cuerpo | No figura en §8.2 (solo `id`, `curp`, `lugar_nacimiento` obligatorios + opcionales listados) | Eliminado del cuerpo; RFC solo en salida vía config |
| `/activar-reporte` | Respuesta con `meta`/`datos`/resumen | `"message"` único de éxito (§8.2) | Respuesta 200: solo `message` oficial |
| `/desactivar-reporte` | `institucion_id` requerido | Solo `id` (§8.4) | Validación solo `id` |
| `/login` institución | `usuario`/`password` libres | `usuario` fijo `"PUI"` (3 caracteres); `clave` 16–20 con reglas (§8.1) | `PuiLoginService` valida formato y credenciales en `pui.ini` |
| `/login` respuesta | `access_token` | Ejemplo §7.1 usa `token` | Respuesta `{ "token": "..." }` |
| Anexo 5 | No aplicado | CURP pos. 12–13 → `lugar_nacimiento` | Clase `PuiAnexo5LugarNacimiento` |

Referencias: *Manual Técnico de la Solución Tecnológica para Instituciones Diversas — PUI*, versión 1.0, 13/01/2026 (DOF edición vespertina 23/01/2026).
