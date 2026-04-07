<?php

namespace App\Pui\Integration;

use App\Pui\Config\PuiConfig;
use App\Pui\Validation\PuiManualPayloadValidator;

/**
 * Cliente HTTP real hacia la PUI (TLS según despliegue institucional).
 *
 * Configuración (pui.ini / env):
 * - PUI_OUTBOUND_BASE_URL: URL base del servicio PUI (ej. https://pui.ejemplo.gob.mx/api/v1)
 * - Simulador típico: exige POST /login para obtener JWT; no acepta la clave de login como Bearer directo.
 * - PUI_OUTBOUND_AUTH_MODE: static | login — con login, este cliente obtiene JWT vía POST PUI_OUTBOUND_LOGIN_PATH y lo usa como Bearer.
 * - PUI_OUTBOUND_TOKEN: Bearer estático solo en modo static (acordado con la PUI real).
 * - PUI_OUTBOUND_TOKEN_NOTIFICAR / PUI_OUTBOUND_TOKEN_BUSQUEDA_FINALIZADA: opcionales (modo static); vacío = PUI_OUTBOUND_TOKEN
 * - PUI_PATH_NOTIFICAR_COINCIDENCIA: ruta, default /notificar-coincidencia
 * - PUI_PATH_BUSQUEDA_FINALIZADA: ruta, default /busqueda-finalizada
 * - PUI_HTTP_RETRIES: reintentos (default 3)
 * - PUI_HTTP_RETRY_MS: base ms backoff exponencial (default 100)
 */
class HttpPuiOutboundClient implements PuiOutboundClientInterface
{
    public function notificarCoincidencia(array $payload): array
    {
        $path = (string) PuiConfig::get('PUI_PATH_NOTIFICAR_COINCIDENCIA', '/notificar-coincidencia');
        $wire = PuiManualPayloadValidator::normalizarNotificarCoincidencia($payload);

        return $this->post($path, $wire, 'notificar');
    }

    public function busquedaFinalizada(array $payload): array
    {
        $path = (string) PuiConfig::get('PUI_PATH_BUSQUEDA_FINALIZADA', '/busqueda-finalizada');
        $wire = PuiManualPayloadValidator::normalizarBusquedaFinalizada($payload);

        return $this->post($path, $wire, 'busqueda_finalizada');
    }

    private function usesJwtOutboundAuth(): bool
    {
        return PuiOutboundBearerResolver::mustUseJwtLogin();
    }

    /**
     * Bearer para salientes: JWT vía login del simulador o token estático por perfil.
     *
     * @throws \RuntimeException
     */
    private function resolveBearerForOutbound(string $perfil): string
    {
        if ($this->usesJwtOutboundAuth()) {
            $resolver = new PuiOutboundBearerResolver();

            return $resolver->resolveBearer();
        }

        return $this->tokenSalientePara($perfil);
    }

    /**
     * Token Bearer saliente (solo modo static): trim; perfiles opcionales si el simulador usa tokens distintos.
     */
    private function tokenSalientePara(string $perfil): string
    {
        $especifico = match ($perfil) {
            'notificar' => trim((string) PuiConfig::get('PUI_OUTBOUND_TOKEN_NOTIFICAR', '')),
            'busqueda_finalizada' => trim((string) PuiConfig::get('PUI_OUTBOUND_TOKEN_BUSQUEDA_FINALIZADA', '')),
            default => '',
        };
        if ($especifico !== '') {
            return $especifico;
        }

        return trim((string) PuiConfig::get('PUI_OUTBOUND_TOKEN', ''));
    }

    /**
     * GET opcional hacia PUI_OUTBOUND_BASE_URL + PUI_OUTBOUND_PING_PATH (vacío = raíz base).
     * Usado antes de activar-reporte-prueba para comprobar TLS/red (manual: conectividad en prueba).
     *
     * @throws \RuntimeException si cURL no está disponible, URL/token vacíos o timeout/red
     */
    public function verificarConectividadSaliente(): void
    {
        $base = rtrim((string) PuiConfig::get('PUI_OUTBOUND_BASE_URL', ''), '/');
        if ($base === '') {
            throw new \RuntimeException('HttpPuiOutboundClient: defina PUI_OUTBOUND_BASE_URL.');
        }
        if ($this->usesJwtOutboundAuth()) {
            $token = $this->resolveBearerForOutbound('default');
        } else {
            $token = $this->tokenSalientePara('default');
            if ($token === '') {
                throw new \RuntimeException('HttpPuiOutboundClient: defina PUI_OUTBOUND_TOKEN o use PUI_OUTBOUND_AUTH_MODE=login.');
            }
        }

        $pingPath = trim((string) PuiConfig::get('PUI_OUTBOUND_PING_PATH', ''));
        $suffix = '';
        if ($pingPath !== '') {
            $suffix = $pingPath[0] === '/' ? $pingPath : '/' . $pingPath;
        }
        $url = $base . $suffix;

        $timeoutMs = max(2000, min(30000, (int) PuiConfig::get('PUI_OUTBOUND_PING_TIMEOUT_MS', 5000)));

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('HttpPuiOutboundClient: se requiere ext-curl para verificar conectividad.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, */*',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($raw === false && ($errno === 28 || (defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT))) {
            throw new \RuntimeException('Ping PUI: tiempo de espera agotado.');
        }
        if ($raw === false) {
            throw new \RuntimeException('Ping PUI: error de conexión (código ' . $errno . ').');
        }
    }

    /** @return array{http_status:int, body:mixed, raw:string} */
    private function post(string $path, array $payload, string $tokenPerfil = 'default'): array
    {
        $base = rtrim((string) PuiConfig::get('PUI_OUTBOUND_BASE_URL', ''), '/');
        if ($base === '') {
            throw new \RuntimeException('HttpPuiOutboundClient: defina PUI_OUTBOUND_BASE_URL para modo REAL.');
        }
        $url = $base . ($path !== '' && $path[0] === '/' ? $path : '/' . $path);
        $token = $this->resolveBearerForOutbound($tokenPerfil);
        if ($token === '') {
            throw new \RuntimeException('HttpPuiOutboundClient: defina PUI_OUTBOUND_TOKEN (o login saliente) para modo REAL.');
        }
        $retries = max(1, (int) PuiConfig::get('PUI_HTTP_RETRIES', 3));
        $baseMs = (int) PuiConfig::get('PUI_HTTP_RETRY_MS', 100);
        $jwtAuth = $this->usesJwtOutboundAuth();

        $last = ['http_status' => 0, 'body' => null, 'raw' => ''];
        for ($i = 0; $i < $retries; $i++) {
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ];
            $last = $this->doPost($url, $headers, $payload);
            $code = $last['http_status'];
            if ($code === 401 && $jwtAuth) {
                PuiOutboundBearerResolver::invalidateCache();
                $token = $this->resolveBearerForOutbound($tokenPerfil);
                $headers = [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                ];
                $last = $this->doPost($url, $headers, $payload);
                $code = $last['http_status'];
            }
            if ($code === 504) {
                return $last;
            }
            if ($code >= 200 && $code < 300) {
                return $last;
            }
            if ($code === 400 || $code === 401) {
                return $last;
            }
            if ($i < $retries - 1) {
                usleep(($baseMs * (1 << $i)) * 1000);
            }
        }
        return $last;
    }

    /**
     * @param list<string> $headers
     * @return array{http_status:int, body:mixed, raw:string}
     */
    private function doPost(string $url, array $headers, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['http_status' => 500, 'body' => null, 'raw' => 'encode_error'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $timeoutMs = max(1000, (int) PuiConfig::get('PUI_REMOTE_TIMEOUT_MS', 15000));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false) {
                if ($errno === 28 || (defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT)) {
                    return ['http_status' => 504, 'body' => null, 'raw' => 'timeout'];
                }

                return ['http_status' => 502, 'body' => null, 'raw' => 'curl_errno_' . $errno];
            }
        } else {
            $timeoutSec = max(1, (int) ceil(((int) PuiConfig::get('PUI_REMOTE_TIMEOUT_MS', 15000)) / 1000));
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $json,
                    'timeout' => $timeoutSec,
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            $code = 500;
            if (isset($http_response_header) && !empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
            if ($raw === false) {
                return ['http_status' => 504, 'body' => null, 'raw' => 'timeout'];
            }
        }

        $rawStr = is_string($raw) ? $raw : '';
        $body = json_decode($rawStr, true);
        return ['http_status' => $code, 'body' => $body, 'raw' => $rawStr];
    }
}
