# Flujo PUI — modelo por eventos (Manual Técnico)

Diagrama de secuencia entre **PUI** (plataforma gubernamental) e **institución** (Cultiva expone los endpoints bajo `/api/pui`).

```mermaid
sequenceDiagram
    participant PUI as PUI (gobierno)
    participant INST as Institución (API Cultiva)
    participant CL as Oracle CL

    PUI->>INST: POST /login (credenciales)
    INST-->>PUI: JWT Bearer

    PUI->>INST: POST /activar-reporte (Authorization: Bearer)
    INST->>CL: Fase 1 — búsqueda por CURP
    loop Por cada coincidencia fase 1
        INST->>PUI: POST /notificar-coincidencia
    end
    INST->>CL: Fase 2 — búsqueda histórica (nombre)
    loop Por cada coincidencia fase 2
        INST->>PUI: POST /notificar-coincidencia
    end
    INST->>PUI: POST /busqueda-finalizada
    INST->>CL: Registrar job fase 3 (PUI_JOBS)
    INST-->>PUI: 200 activación recibida

    loop Runner CLI JobTableRunner
        INST->>CL: Fase 3 — búsqueda continua
        alt Coincidencias nuevas
            INST->>PUI: POST /notificar-coincidencia (fase 3)
        end
    end

    PUI->>INST: POST /desactivar-reporte
    INST-->>PUI: 200 cierre
```

**Notas de implementación**

- `busqueda-finalizada` se invoca **después** de completar fases 1 y 2.
- La fase 3 es asíncrona mediante tabla `PUI_JOBS` y runner CLI (`backend/Jobs/controllers/JobTableRunner.php`).
- Los endpoints salientes (`notificar-coincidencia`, `busqueda-finalizada`) usan `PUI_OUTBOUND_BASE_URL` y rutas configurables en `pui.ini`.
- Modo `MOCK` evita HTTP real; modo `REAL` usa `HttpPuiOutboundClient` con reintentos.
- `GET /api/pui/persona/{curp}` y `POST /api/pui/busqueda` son endpoints auxiliares (debug/monitoreo), controlados por flags de configuración.
