<?php

namespace App\Pui\Http;

/**
 * Respuestas JSON del contrato PUI (errores de infraestructura).
 */
class PuiJsonResponse
{
    /**
     * @return array<string,mixed>
     */
    public static function databaseUnavailablePayload(string $requestId): array
    {
        return [
            'meta' => [
                'requestId' => $requestId,
                'timestamp' => gmdate('c'),
                'version' => '1.0.0',
            ],
            'error' => [
                'codigo' => 'PUI-DB-503',
                'mensaje' => 'Servicio de base de datos no disponible.',
                'detalle' => null,
            ],
        ];
    }

    public static function databaseUnavailable503(): void
    {
        self::sendDatabaseUnavailable(bin2hex(random_bytes(16)));
    }

    public static function sendDatabaseUnavailable(string $requestId): void
    {
        if (\function_exists('jsonResponse')) {
            \jsonResponse(self::databaseUnavailablePayload($requestId), 503);
        }
        while (\ob_get_level() > 0) {
            \ob_end_clean();
        }
        \http_response_code(503);
        \header('Content-Type: application/json; charset=UTF-8');
        echo \json_encode(self::databaseUnavailablePayload($requestId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Errores típicos de conexión / enlace (sin filtrar mensaje al cliente).
     */
    public static function isConnectionOrLinkFailure(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (\preg_match('/ORA-(\d{5})/', $msg, $m)) {
            $ora = $m[1];
            return \in_array($ora, [
                '12514', '12541', '12154', '12170', '12535', '12537', '12545', '12547',
                '03113', '03114', '03135', '28547', '01017', '01033', '01034', '12152',
                '12500', '12560',
            ], true);
        }
        $lower = \strtolower($msg);
        if (\str_contains($lower, 'timeout') || \str_contains($lower, 'timed out')) {
            return true;
        }
        if (\str_contains($lower, 'connection') && (\str_contains($lower, 'refused') || \str_contains($lower, 'reset') || \str_contains($lower, 'lost'))) {
            return true;
        }
        return false;
    }
}
