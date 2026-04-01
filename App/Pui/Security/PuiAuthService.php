<?php

namespace App\Pui\Security;

use App\Pui\Config\PuiConfig;

/**
 * Servicio central de autenticación para endpoints PUI protegidos por JWT.
 *
 * La validez del token es solo criptográfica y temporal (JwtService::decode):
 * firma HS256, exp, nbf, iat, iss, aud. El mismo JWT se puede usar en cada
 * petición hasta que expire; no hay consumo por JTI en base de datos.
 */
class PuiAuthService
{
    private JwtService $jwt;

    public function __construct(?JwtService $jwt = null)
    {
        $secret = PuiConfig::jwtSecretOrFail();
        $issuer = (string) PuiConfig::get('JWT_ISSUER', 'cultiva-pui');
        $audience = (string) PuiConfig::get('JWT_AUDIENCE', 'pui-institucion');
        $this->jwt = $jwt ?? new JwtService($secret, $issuer, $audience);
    }

    public function validateJwt(string $token): bool
    {
        return $this->decodeJwt($token) !== null;
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
