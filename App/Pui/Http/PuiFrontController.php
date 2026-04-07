<?php

namespace App\Pui\Http;

use App\Pui\Config\PuiConfig;
use App\Pui\Exception\DatabaseUnavailableException;
use App\Pui\Integration\PuiOutboundBearerResolver;
use App\Pui\Security\PuiAuthService;
use App\Pui\Security\PuiRateLimiter;
use App\Pui\Service\PuiLoginService;
use App\Pui\Service\PuiReporteService;

/**
 * Rutas institucionales según Manual Técnico PUI (eventos: activar reporte, desactivar, login JWT).
 * GET /salud — disponible sin JWT (monitoreo).
 * GET /test-login-simulador — diagnóstico login saliente (PUI_ENABLE_TEST_LOGIN_SIMULADOR=1).
 * Base: PUI_PUBLIC_BASE (p. ej. /api/pui).
 */
class PuiFrontController
{
    private PuiLoginService $login;
    private ?PuiAuthService $auth;
    private PuiReporteService $reportes;

    public function __construct(
        ?PuiLoginService $login = null,
        ?PuiAuthService $auth = null,
        ?PuiReporteService $reportes = null
    )
    {
        $this->login = $login ?? new PuiLoginService();
        $this->auth = $auth;
        $this->reportes = $reportes ?? new PuiReporteService();
    }

    public function dispatch(): void
    {
        $requestId = $this->newRequestId();
        PuiLogger::setRequestContext($requestId);
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = $this->normalizePath();

        try {
            if ($this->isRateLimitExceeded($method, $path)) {
                PuiLogger::warning($requestId, 'rate_limit_exceeded', ['path' => $path, 'method' => $method]);
                $this->emitError($requestId, 429, 'PUI-HTTP-429', 'Too Many Requests');
                return;
            }

            if ($method === 'POST' && $path === '/login') {
                if (!$this->isJsonRequest()) {
                    $this->emitError($requestId, 400, 'PUI-VAL-400', 'Solicitud inválida.', 'Content-Type debe ser application/json.');
                    return;
                }
                $body = $this->readJsonBody();
                $unknown = $this->unknownKeys($body, ['usuario', 'clave', 'institucion_id']);
                if ($body === [] || $unknown !== []) {
                    $detalle = $unknown !== [] ? ('Campos no permitidos: ' . implode(', ', $unknown)) : 'JSON inválido o vacío.';
                    $this->emitError($requestId, 400, 'PUI-VAL-400', 'Solicitud inválida.', $detalle);
                    return;
                }
                if (!array_key_exists('clave', $body) || !is_string($body['clave'])) {
                    $this->emitError($requestId, 400, 'PUI-VAL-400', 'Solicitud inválida.', 'Falta clave o no es cadena.');
                    return;
                }
                $usuarioRaw = isset($body['usuario']) && is_string($body['usuario']) ? trim($body['usuario']) : '';
                $instRaw = isset($body['institucion_id']) && is_string($body['institucion_id']) ? trim($body['institucion_id']) : '';
                if ($usuarioRaw === '' && $instRaw === '') {
                    $this->emitError($requestId, 400, 'PUI-VAL-400', 'Solicitud inválida.', 'Indique usuario (§8.1) o institucion_id igual a INSTITUCION_RFC en pui.ini.');
                    return;
                }
                if ($usuarioRaw === '' && $instRaw !== '') {
                    $rfcCfg = strtoupper(trim((string) PuiConfig::get('INSTITUCION_RFC', '')));
                    if ($rfcCfg === '' || strtoupper($instRaw) !== $rfcCfg) {
                        $this->emitError($requestId, 400, 'PUI-VAL-400', 'Solicitud inválida.', 'institucion_id debe coincidir con INSTITUCION_RFC configurado, o use usuario "PUI" (§8.1).');
                        return;
                    }
                    $body['usuario'] = 'PUI';
                }
                if (!is_string($body['usuario'] ?? null)) {
                    $this->emitError($requestId, 400, 'PUI-VAL-400', 'Solicitud inválida.', 'usuario debe ser cadena.');
                    return;
                }
                $r = $this->login->login($body);
                $this->sendRaw($r['status'], $r['body']);
                return;
            }

            if ($method === 'GET' && $path === '/salud') {
                $this->sendRaw(200, [
                    'meta' => [
                        'requestId' => $requestId,
                        'timestamp' => gmdate('c'),
                        'version' => '1.0.0',
                    ],
                    'status' => 'ok',
                ]);
                return;
            }

            if ($method === 'GET' && $path === '/test-login-simulador') {
                $probeOn = PuiConfig::get('PUI_ENABLE_TEST_LOGIN_SIMULADOR', '0');
                $probeEnabled = $probeOn === true || $probeOn === 1 || $probeOn === '1';
                if (!$probeEnabled) {
                    $this->emitError($requestId, 403, 'PUI-HTTP-403', 'Operación no permitida.', 'Defina PUI_ENABLE_TEST_LOGIN_SIMULADOR=1 para activar este endpoint de diagnóstico.');
                    return;
                }
                try {
                    $probe = PuiOutboundBearerResolver::probeBothVariants();
                } catch (\Throwable $e) {
                    $this->emitError($requestId, 502, 'PUI-HTTP-502', 'Error al probar login saliente.', $e->getMessage());
                    return;
                }
                $this->sendRaw(200, [
                    'meta' => [
                        'requestId' => $requestId,
                        'timestamp' => gmdate('c'),
                        'version' => '1.0.0',
                    ],
                    'test_login_simulador' => $probe,
                ]);
                return;
            }

            PuiConfig::assertRealIntegrationReady();

            $jwtPayload = $this->requireJwt($requestId);
            if ($jwtPayload === null) {
                return;
            }

            if ($method === 'POST' && !$this->isJsonRequest()) {
                $this->emitError($requestId, 400, 'PUI-VAL-400', 'Solicitud inválida.', 'Content-Type debe ser application/json.');
                return;
            }

            $body = $this->readJsonBody();

            if ($method === 'POST' && $path === '/activar-reporte') {
                $missing = $this->missingKeys($body, ['id', 'curp', 'lugar_nacimiento']);
                if ($body === [] || $missing !== []) {
                    $detalle = $missing !== [] ? ('Faltan campos: ' . implode(', ', $missing)) : 'JSON inválido o vacío.';
                    $this->emitError($requestId, 400, 'PUI-VAL-400', 'Payload inválido para activar-reporte.', $detalle);
                    return;
                }
                $r = $this->reportes->activarReporte($requestId, $body, false);
                $this->sendRaw($r['status'], $r['body']);
                return;
            }
            if ($method === 'POST' && $path === '/activar-reporte-prueba') {
                $pruebaOn = PuiConfig::get('PUI_ENABLE_ACTIVAR_PRUEBA', '1');
                $pruebaEnabled = $pruebaOn === true || $pruebaOn === 1 || $pruebaOn === '1';
                if (!$pruebaEnabled) {
                    $this->emitError($requestId, 403, 'PUI-HTTP-403', 'Operación no permitida.');
                    return;
                }
                $missing = $this->missingKeys($body, ['id', 'curp', 'lugar_nacimiento']);
                if ($body === [] || $missing !== []) {
                    $detalle = $missing !== [] ? ('Faltan campos: ' . implode(', ', $missing)) : 'JSON inválido o vacío.';
                    $this->emitError($requestId, 400, 'PUI-VAL-400', 'Payload inválido para activar-reporte-prueba.', $detalle);
                    return;
                }
                $r = $this->reportes->activarReporte($requestId, $body, true);
                $this->sendRaw($r['status'], $r['body']);
                return;
            }
            if ($method === 'POST' && $path === '/desactivar-reporte') {
                $missing = $this->missingKeys($body, ['id']);
                if ($body === [] || $missing !== []) {
                    $detalle = $missing !== [] ? ('Faltan campos: ' . implode(', ', $missing)) : 'JSON inválido o vacío.';
                    $this->emitError($requestId, 400, 'PUI-VAL-400', 'Payload inválido para desactivar-reporte.', $detalle);
                    return;
                }
                $r = $this->reportes->desactivarReporte($requestId, $body);
                $this->sendRaw($r['status'], $r['body']);
                return;
            }
            if ($this->isKnownRoute($path)) {
                PuiLogger::warning($requestId, 'method_not_allowed', ['path' => $path, 'method' => $method]);
                $this->emitError($requestId, 405, 'PUI-HTTP-405', 'Method Not Allowed');
                return;
            }

            PuiLogger::warning($requestId, 'route_not_found', ['path' => $path, 'method' => $method]);
            $this->emitError($requestId, 404, 'PUI-HTTP-404', 'Ruta no definida para el módulo PUI.');
        } catch (\Throwable $e) {
            if ($e instanceof DatabaseUnavailableException) {
                throw $e;
            }
            PuiLogger::error($requestId, 'exception', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    private function requireJwt(string $requestId): ?array
    {
        if ($this->auth === null) {
            try {
                $this->auth = new PuiAuthService();
            } catch (\Throwable $e) {
                PuiLogger::error($requestId, 'auth_init_error', ['msg' => $e->getMessage()]);
                $this->emitError($requestId, 500, 'PUI-CFG-500', 'Configuración de autenticación inválida.');
                return null;
            }
        }
        $token = $this->auth->extractBearerToken($_SERVER);
        if ($token === null) {
            PuiLogger::warning($requestId, 'jwt_missing', []);
            $this->emitError($requestId, 401, 'PUI-AUTH-001', 'Se requiere Authorization: Bearer con JWT válido.');
            return null;
        }
        if (!$this->auth->validateJwt($token)) {
            PuiLogger::warning($requestId, 'jwt_invalid', []);
            $this->emitError($requestId, 401, 'PUI-AUTH-004', 'JWT inválido o expirado.');
            return null;
        }
        return $this->auth->decodeJwt($token);
    }

    private function newRequestId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return uniqid('pui_', true);
        }
    }

    private function normalizePath(): string
    {
        $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
        $base = PuiConfig::publicBase();
        $pos = stripos($uri, $base);
        if ($pos !== false) {
            $sub = substr($uri, $pos + strlen($base));
        } elseif (preg_match('#/api/pui($|/)#i', $uri, $m, PREG_OFFSET_CAPTURE)) {
            $sub = substr($uri, $m[0][1] + strlen($m[0][0]));
        } else {
            $sub = $uri;
        }
        $sub = '/' . trim($sub, '/');
        return $sub === '//' ? '/' : $sub;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        // Lectura estricta de JSON puro para REST (Postman/curl/PowerShell).
        $input = json_decode(file_get_contents("php://input"), true);
        // Log temporal de diagnóstico: sólo en debug de configuración.
        if (PuiConfig::get('PUI_DEBUG_CONFIG', '0') === '1' || PuiConfig::get('PUI_DEBUG_CONFIG', 0) === 1) {
            $n = is_array($input) ? count($input) : 0;
            error_log('[PUI] JSON body (debug): claves recibidas=' . $n);
        }

        if ($input === null) {
            return [];
        }

        return is_array($input) ? $input : [];
    }

    /**
     * @param array<string,mixed> $body
     * @param list<string> $keys
     * @return list<string>
     */
    private function missingKeys(array $body, array $keys): array
    {
        $missing = [];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $body)) {
                $missing[] = $k;
            }
        }
        return $missing;
    }

    private function unknownKeys(array $body, array $keys): array
    {
        $unknown = [];
        foreach (array_keys($body) as $k) {
            if (!in_array((string) $k, $keys, true)) {
                $unknown[] = (string) $k;
            }
        }
        return $unknown;
    }

    private function isKnownRoute(string $path): bool
    {
        if (in_array($path, [
            '/login',
            '/salud',
            '/test-login-simulador',
            '/activar-reporte',
            '/activar-reporte-prueba',
            '/desactivar-reporte',
        ], true)) {
            return true;
        }
        return false;
    }

    private function isRateLimitExceeded(string $method, string $path): bool
    {
        $enabled = PuiConfig::get('PUI_RATE_LIMIT_ENABLED', '1');
        $isEnabled = $enabled === true || $enabled === 1 || $enabled === '1';
        if (!$isEnabled) {
            return false;
        }

        $limit = (int) PuiConfig::get('PUI_RATE_LIMIT_MAX_REQUESTS', 60);
        $window = (int) PuiConfig::get('PUI_RATE_LIMIT_WINDOW_SECONDS', 60);

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = sprintf('%s|%s|%s', $ip, $method, $path);
        $limiter = new PuiRateLimiter();
        return !$limiter->allow($key, $limit, $window);
    }

    private function isJsonRequest(): bool
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if ($contentType === '') {
            return false;
        }
        if (!str_contains($contentType, 'application/json')) {
            return false;
        }

        if (preg_match('/;\s*charset\s*=\s*([a-z0-9._-]+)/i', $contentType, $m)) {
            return strtolower(trim($m[1])) === 'utf-8';
        }

        return true;
    }

    /** @param array<string,mixed> $body */
    private function sendRaw(int $status, array $body): void
    {
        if (function_exists('jsonResponse')) {
            jsonResponse($body, $status);
        }
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function emitError(string $requestId, int $http, string $codigo, string $mensaje, ?string $detalle = null): void
    {
        $verbose = PuiConfig::exposeErrorDetailInResponse();
        $m = $mensaje;
        $d = $detalle;
        if (!$verbose && $http >= 400 && $http < 500) {
            $d = null;
            if ($codigo === 'PUI-VAL-400') {
                $m = 'Solicitud inválida.';
            }
        }
        if (!$verbose && $http >= 500) {
            $d = null;
            $m = 'Error en el servicio.';
        }
        $payload = [
            'meta' => [
                'requestId' => $requestId,
                'timestamp' => gmdate('c'),
                'version' => '1.0.0',
            ],
            'error' => [
                'codigo' => $codigo,
                'mensaje' => $m,
                'detalle' => $d,
            ],
        ];
        if (function_exists('jsonResponse')) {
            jsonResponse($payload, $http);
        }
        http_response_code($http);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
