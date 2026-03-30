<?php

namespace App\Pui\Integration;

/**
 * Cliente hacia los endpoints que la PUI expone para recibir respuestas de la institución:
 * POST /notificar-coincidencia y POST /busqueda-finalizada (rutas relativas a URL base del entorno PUI).
 */
interface PuiOutboundClientInterface
{
    /**
     * @param array<string,mixed> $payload Cuerpo JSON conforme al manual (reporte_id, institucion_id, fase_busqueda, tipo_evento, coincidencia, …)
     * @return array{http_status:int, body:mixed, raw:string}
     */
    public function notificarCoincidencia(array $payload): array;

    /**
     * @param array<string,mixed> $payload
     * @return array{http_status:int, body:mixed, raw:string}
     */
    public function busquedaFinalizada(array $payload): array;
}
