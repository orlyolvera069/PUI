<?php
/**
 * Front controller PUI — Manual Técnico (modelo por eventos / reportes).
 *
 * Institución expone (base típica /api/pui):
 *   GET  /api/pui/salud   (sin JWT — comprobación de servicio)
 *   GET  /api/pui/test-login-simulador (sin JWT — diagnóstico login saliente si PUI_ENABLE_TEST_LOGIN_SIMULADOR=1)
 *   POST /api/pui/login
 *   POST /api/pui/activar-reporte
 *   POST /api/pui/activar-reporte-prueba
 *   POST /api/pui/desactivar-reporte
 *
 * Salida hacia la PUI (cliente HTTP): notificar-coincidencia, busqueda-finalizada (ver pui.ini).
 */

declare(strict_types=1);

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('PROJECTPATH', dirname(__DIR__, 3));
define('APPPATH', PROJECTPATH . '/App');
if (!defined('PUI_API_JSON')) {
    define('PUI_API_JSON', true);
}

require_once PROJECTPATH . '/public/api/pui/autoload_pui.php';
use App\Pui\Http\PuiFrontController;
// Si Apache redirigió por un error (REDIRECT_STATUS), responder JSON legible.
\App\Pui\Http\ErrorHandler::manejar();

@header_remove('X-Powered-By');
@header_remove('Server');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
// Si el servidor web reinyecta Server/X-Powered-By, suprimirlos también en Apache (Header unset) o nginx (proxy_hide_header).
if (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function jsonResponse($data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (is_array($data) && array_key_exists('meta', $data)) {
        unset($data['meta']);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function puiErrorEnvelope(string $requestId): array
{
    return [
        'meta' => [
            'requestId' => $requestId,
            'timestamp' => gmdate('c'),
            'version' => '1.0.0',
        ],
        'error' => [
            'codigo' => 'PUI-ERR-500',
            'mensaje' => 'Error interno',
            'detalle' => null,
        ],
    ];
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (\Throwable $e): void {
    $requestId = bin2hex(random_bytes(16));
    if ($e instanceof \App\Pui\Exception\DatabaseUnavailableException) {
        \App\Pui\Http\PuiJsonResponse::sendDatabaseUnavailable($requestId);
    }
    jsonResponse(puiErrorEnvelope($requestId), 500);
});

register_shutdown_function(static function (): void {
    $last = error_get_last();
    if ($last === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($last['type'], $fatalTypes, true)) {
        return;
    }
    $requestId = bin2hex(random_bytes(16));
    jsonResponse(puiErrorEnvelope($requestId), 500);
});

try {
    $controller = new PuiFrontController();
    $controller->dispatch();
} catch (\App\Pui\Exception\DatabaseUnavailableException $e) {
    jsonResponse(\App\Pui\Http\PuiJsonResponse::databaseUnavailablePayload(bin2hex(random_bytes(16))), 503);
} catch (\Throwable $e) {
    jsonResponse(puiErrorEnvelope(bin2hex(random_bytes(16))), 500);
}
