<?php

namespace App\Pui\Integration;

use App\Pui\Config\PuiConfig;

/**
 * Bearer para HTTP saliente hacia la PUI/simulador.
 * El simulador de pruebas espera JWT obtenido con POST /login; no usa la clave como Authorization: Bearer.
 */
class PuiOutboundBearerResolver
{
    private static ?string $cachedJwt = null;

    private static int $cachedAt = 0;

    public static function invalidateCache(): void
    {
        self::$cachedJwt = null;
        self::$cachedAt = 0;
    }

    /**
     * true si el Bearer saliente debe obtenerse vía POST /login (JWT), no como cadena fija en ini.
     *
     * - PUI_OUTBOUND_AUTH_MODE=login
     * - Sin tokens estáticos pero con PUI_OUTBOUND_LOGIN_CLAVE (login implícito)
     * - Cualquier PUI_OUTBOUND_TOKEN* igual a PUI_OUTBOUND_LOGIN_CLAVE (error típico: usar la clave del simulador como Bearer)
     */
    public static function mustUseJwtLogin(): bool
    {
        $mode = strtolower(trim((string) PuiConfig::get('PUI_OUTBOUND_AUTH_MODE', 'static')));
        if ($mode === 'login') {
            return true;
        }

        $clave = trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_CLAVE', ''));
        if ($clave === '') {
            return false;
        }

        $candidatos = [
            trim((string) PuiConfig::get('PUI_OUTBOUND_TOKEN', '')),
            trim((string) PuiConfig::get('PUI_OUTBOUND_TOKEN_NOTIFICAR', '')),
            trim((string) PuiConfig::get('PUI_OUTBOUND_TOKEN_BUSQUEDA_FINALIZADA', '')),
        ];
        foreach ($candidatos as $t) {
            if ($t !== '' && hash_equals($clave, $t)) {
                return true;
            }
        }

        return $candidatos[0] === '' && $candidatos[1] === '' && $candidatos[2] === '';
    }

    /**
     * JWT para Authorization: Bearer (solo cuando mustUseJwtLogin() es true).
     *
     * @throws \RuntimeException
     */
    public function resolveBearer(): string
    {
        if (!self::mustUseJwtLogin()) {
            throw new \RuntimeException(
                'PuiOutboundBearerResolver: flujo JWT no activo. Use HttpPuiOutboundClient con token estático o defina PUI_OUTBOUND_AUTH_MODE=login / PUI_OUTBOUND_LOGIN_CLAVE.'
            );
        }

        return $this->resolveLoginCached();
    }

    private function resolveLoginCached(): string
    {
        $ttl = max(60, (int) PuiConfig::get('PUI_OUTBOUND_LOGIN_CACHE_SECONDS', 3300));
        if (self::$cachedJwt !== null && self::$cachedJwt !== '' && (time() - self::$cachedAt) < $ttl) {
            return self::$cachedJwt;
        }
        $jwt = $this->fetchLoginTokenWithStyle();
        self::$cachedJwt = $jwt;
        self::$cachedAt = time();

        return $jwt;
    }

    /**
     * PUI_OUTBOUND_LOGIN_BODY_STYLE: auto | institucion_id | usuario
     */
    private function fetchLoginTokenWithStyle(): string
    {
        $style = strtolower(trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_BODY_STYLE', 'auto')));
        if ($style === 'institucion_id') {
            $r = $this->loginRequest($this->bodyVariantInstitucionId());

            return $this->extractTokenFromLoginResult($r);
        }
        if ($style === 'usuario') {
            $r = $this->loginRequest($this->bodyVariantUsuario());

            return $this->extractTokenFromLoginResult($r);
        }

        $parts = [];
        try {
            $rA = $this->loginRequest($this->bodyVariantInstitucionId());
            if ($rA['http_status'] >= 200 && $rA['http_status'] < 300) {
                return $this->extractTokenFromLoginResult($rA);
            }
            $parts[] = 'variante institucion_id HTTP ' . $rA['http_status'];
        } catch (\Throwable $e) {
            $parts[] = 'variante institucion_id: ' . $e->getMessage();
        }
        try {
            $rB = $this->loginRequest($this->bodyVariantUsuario());
            if ($rB['http_status'] >= 200 && $rB['http_status'] < 300) {
                return $this->extractTokenFromLoginResult($rB);
            }
            $parts[] = 'variante usuario HTTP ' . $rB['http_status'];
        } catch (\Throwable $e) {
            $parts[] = 'variante usuario: ' . $e->getMessage();
        }

        throw new \RuntimeException('Login al simulador (auto) falló: ' . implode('; ', $parts));
    }

    /**
     * @return array{institucion_id: string, clave: string}|array{usuario: string, clave: string}
     */
    private function bodyVariantInstitucionId(): array
    {
        $id = trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_INSTITUCION_ID', ''));
        $clave = trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_CLAVE', ''));
        if ($id === '' || $clave === '') {
            throw new \RuntimeException('PUI_OUTBOUND_LOGIN_INSTITUCION_ID y PUI_OUTBOUND_LOGIN_CLAVE son obligatorios para login (variante institucion_id).');
        }

        return ['institucion_id' => $id, 'clave' => $clave];
    }

    /**
     * @return array{usuario: string, clave: string}
     */
    private function bodyVariantUsuario(): array
    {
        $u = trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_USUARIO', 'PUI'));
        $clave = trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_CLAVE', ''));
        if ($clave === '') {
            throw new \RuntimeException('PUI_OUTBOUND_LOGIN_CLAVE es obligatoria para login (variante usuario).');
        }

        return ['usuario' => $u, 'clave' => $clave];
    }

    /**
     * Expuesto para GET /test-login-simulador.
     *
     * @return array{http_status:int, body:mixed, raw:string, variant:string}
     */
    public function loginRequestPublicForProbe(array $body, ?string $label = null): array
    {
        $r = $this->loginRequest($body);
        if ($label !== null) {
            $r['variant'] = $label;
        }

        return $r;
    }

    /**
     * @param array<string,string> $body
     * @return array{http_status:int, body:mixed, raw:string}
     */
    private function loginRequest(array $body): array
    {
        $base = rtrim((string) PuiConfig::get('PUI_OUTBOUND_BASE_URL', ''), '/');
        if ($base === '') {
            throw new \RuntimeException('PUI_OUTBOUND_BASE_URL vacío.');
        }
        $path = (string) PuiConfig::get('PUI_OUTBOUND_LOGIN_PATH', '/login');
        $path = $path !== '' && $path[0] === '/' ? $path : '/' . $path;
        $url = $base . $path;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('login: JSON inválido.');
        }
        $timeoutMs = max(2000, (int) PuiConfig::get('PUI_REMOTE_TIMEOUT_MS', 15000));

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Se requiere ext-curl para login saliente hacia la PUI.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $rawStr = is_string($raw) ? $raw : '';
        $parsed = json_decode($rawStr, true);

        return [
            'http_status' => $code,
            'body' => is_array($parsed) ? $parsed : null,
            'raw' => $rawStr,
        ];
    }

    /**
     * @param array{http_status:int, body:mixed, raw:string} $loginResult
     */
    private function extractTokenFromLoginResult(array $loginResult): string
    {
        $body = $loginResult['body'] ?? null;
        if (!is_array($body)) {
            throw new \RuntimeException('Login: respuesta no JSON. raw=' . substr((string) ($loginResult['raw'] ?? ''), 0, 300));
        }
        foreach (['token', 'access_token', 'accessToken'] as $k) {
            if (isset($body[$k]) && is_string($body[$k]) && trim($body[$k]) !== '') {
                return trim($body[$k]);
            }
        }

        throw new \RuntimeException('Login: respuesta sin token (esperado token|access_token). Keys: ' . implode(',', array_keys($body)));
    }

    /**
     * Prueba ambas variantes de login contra el simulador (diagnóstico).
     *
     * @return array<string,mixed>
     */
    public static function probeBothVariants(): array
    {
        $resolver = new self();
        $out = [
            'variante_a_institucion_id' => null,
            'variante_b_usuario' => null,
            'recomendacion' => null,
        ];

        $id = trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_INSTITUCION_ID', ''));
        $clave = trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_CLAVE', ''));
        if ($id !== '' && $clave !== '') {
            $ra = $resolver->loginRequestPublicForProbe(['institucion_id' => $id, 'clave' => $clave], 'institucion_id');
            $out['variante_a_institucion_id'] = self::summarizeProbe($ra);
        } else {
            $out['variante_a_institucion_id'] = ['error_config' => 'Defina PUI_OUTBOUND_LOGIN_INSTITUCION_ID y PUI_OUTBOUND_LOGIN_CLAVE para probar variante A.'];
        }

        $u = trim((string) PuiConfig::get('PUI_OUTBOUND_LOGIN_USUARIO', 'PUI'));
        if ($clave !== '') {
            $rb = $resolver->loginRequestPublicForProbe(['usuario' => $u, 'clave' => $clave], 'usuario');
            $out['variante_b_usuario'] = self::summarizeProbe($rb);
        } else {
            $out['variante_b_usuario'] = ['error_config' => 'Defina PUI_OUTBOUND_LOGIN_CLAVE para probar variante B.'];
        }

        $okA = is_array($out['variante_a_institucion_id'])
            && ($out['variante_a_institucion_id']['http_status'] ?? 0) >= 200
            && ($out['variante_a_institucion_id']['http_status'] ?? 0) < 300
            && !empty($out['variante_a_institucion_id']['token_preview']);
        $okB = is_array($out['variante_b_usuario'])
            && ($out['variante_b_usuario']['http_status'] ?? 0) >= 200
            && ($out['variante_b_usuario']['http_status'] ?? 0) < 300
            && !empty($out['variante_b_usuario']['token_preview']);

        if ($okA && !$okB) {
            $out['recomendacion'] = 'Use PUI_OUTBOUND_LOGIN_BODY_STYLE=institucion_id';
        } elseif ($okB && !$okA) {
            $out['recomendacion'] = 'Use PUI_OUTBOUND_LOGIN_BODY_STYLE=usuario';
        } elseif ($okA && $okB) {
            $out['recomendacion'] = 'Ambas OK; con auto se intenta primero institucion_id.';
        } else {
            $out['recomendacion'] = 'Ninguna variante devolvió 200 con token; revise URL, clave y PUI_OUTBOUND_LOGIN_PATH.';
        }

        return $out;
    }

    /**
     * @param array{http_status:int, body:mixed, raw:string, variant?:string} $r
     * @return array<string,mixed>
     */
    private static function summarizeProbe(array $r): array
    {
        $http = (int) ($r['http_status'] ?? 0);
        $tokenPreview = null;
        $err = null;
        $fullToken = null;
        try {
            $x = new self();
            $fullToken = $x->extractTokenFromLoginResult($r);
            $tokenPreview = strlen($fullToken) > 24 ? substr($fullToken, 0, 12) . '…' . substr($fullToken, -8) : $fullToken;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }

        return [
            'http_status' => $http,
            'ok' => $http >= 200 && $http < 300 && $fullToken !== null,
            'token' => $fullToken,
            'token_preview' => $tokenPreview,
            'error_parse_token' => $err,
            'body_keys' => is_array($r['body'] ?? null) ? array_keys($r['body']) : [],
        ];
    }
}
