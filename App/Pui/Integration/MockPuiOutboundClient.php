<?php

namespace App\Pui\Integration;

/**
 * Modo MOCK: no llama red; devuelve 200 simulado para pruebas E2E sin sandbox PUI.
 */
class MockPuiOutboundClient implements PuiOutboundClientInterface
{
    public function notificarCoincidencia(array $payload): array
    {
        $raw = json_encode(['message' => 'Coincidencia recibida correctamente'], JSON_UNESCAPED_UNICODE);
        return ['http_status' => 200, 'body' => json_decode($raw, true), 'raw' => $raw];
    }

    public function busquedaFinalizada(array $payload): array
    {
        $raw = json_encode(['message' => 'Registro de finalización de búsqueda histórica guardado correctamente.'], JSON_UNESCAPED_UNICODE);
        return ['http_status' => 200, 'body' => json_decode($raw, true), 'raw' => $raw];
    }
}
