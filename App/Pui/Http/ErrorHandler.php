<?php

namespace App\Pui\Http;

class ErrorHandler
{
    private const MENSAJES = [
        400 => 'Solicitud incorrecta',
        401 => 'No autenticado',
        403 => 'Acceso no autorizado',
        404 => 'Endpoint no encontrado',
        405 => 'Método no permitido',
        500 => 'Error interno del servidor',
    ];

    /**
     * Si Apache redirigió aquí por un error, responde JSON y termina.
     * Si es una petición normal, no hace nada y deja continuar el routing.
     */
    public static function manejar(): void
    {
        // Apache pone el código original en REDIRECT_STATUS
        $codigoApache = (int) ($_SERVER['REDIRECT_STATUS'] ?? 0);

        if ($codigoApache === 0 || $codigoApache === 200) {
            // Petición normal, no hay error de Apache
            return;
        }

        $mensaje = self::MENSAJES[$codigoApache]
            ?? 'Error inesperado';

        http_response_code($codigoApache);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode([
            'error'  => $mensaje,
            'codigo' => $codigoApache,
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

