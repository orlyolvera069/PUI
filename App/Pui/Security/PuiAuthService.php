<?php

namespace App\Pui\Security;

use App\Pui\Config\PuiConfig;
use App\Pui\Repository\PuiJwtTokenOracleRepository;

/**
 * Servicio central de autenticación para endpoints PUI protegidos por JWT.
 */
class PuiAuthService
{
    private JwtService $jwt;
    private PuiJwtTokenOracleRepository $jwtTokens;

    public function __construct(?JwtService $jwt = null, ?PuiJwtTokenOracleRepository $jwtTokens = null)
    {
        $secret = PuiConfig::jwtSecretOrFail();
        $issuer = (string) PuiConfig::get('JWT_ISSUER', 'cultiva-pui');
        $audience = (string) PuiConfig::get('JWT_AUDIENCE', 'pui-institucion');
        $this->jwt = $jwt ?? new JwtService($secret, $issuer, $audience);
        $this->jwtTokens = $jwtTokens ?? new PuiJwtTokenOracleRepository();
    }

    public function validateJwt(string $token): bool
    {
        $payload = $this->decodeJwt($token);
        if ($payload === null) {
            return false;
        }

        $jti = isset($payload['jti']) ? trim((string) $payload['jti']) : '';
        $exp = isset($payload['exp']) && is_numeric($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($jti === '' || $exp <= 0) {
            return false;
        }

        $this->jwtTokens->purgeExpired();
        return $this->jwtTokens->consumeJti($jti, $exp);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function decodeJwt(string $token): ?array
    {
        return $this->jwt->decode($token);
    }

    /**
     * @param array<string,mixed> $headers
     */
    public function extractBearerToken(array $headers): ?string
    {
        $auth = $headers['HTTP_AUTHORIZATION'] ?? ($headers['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if (!is_string($auth)) {
            return null;
        }
        if (stripos($auth, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($auth, 7));
        return $token !== '' ? $token : null;
    }
}
