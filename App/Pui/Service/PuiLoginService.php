<?php

namespace App\Pui\Service;

use App\Pui\Config\PuiConfig;
use App\Pui\Security\JwtService;
use App\Pui\Validation\ManualValidators;

/**
 * §8.1 — Autenticación institucional: usuario fijo "PUI" y clave 16–20 (complejidad manual).
 * Respuesta: token JWT (§7.1 referencia de formato).
 */
class PuiLoginService
{
    private JwtService $jwt;

    public function __construct(?JwtService $jwt = null)
    {
        $secret = PuiConfig::jwtSecretOrFail();
        $issuer = (string) PuiConfig::get('JWT_ISSUER', 'cultiva-pui');
        $audience = (string) PuiConfig::get('JWT_AUDIENCE', 'pui-institucion');
        $this->jwt = $jwt ?? new JwtService($secret, $issuer, $audience);
    }

    /**
     * @param array<string,mixed> $body "usuario" y "clave" (§8.1)
     * @return array{status:int, body:array<string,mixed>}
     */
    public function login(array $body): array
    {
        $usuario = (string) ($body['usuario'] ?? '');
        $clave = (string) ($body['clave'] ?? '');

        if (!ManualValidators::usuarioPuiInstitucion($usuario) || !ManualValidators::claveInstitucion($clave)) {
            return ['status' => 403, 'body' => ['error' => 'Credenciales inválidas']];
        }

        $eu = (string) PuiConfig::get('PUI_LOGIN_USUARIO', 'PUI');
        $ep = (string) PuiConfig::get('PUI_LOGIN_CLAVE', '');
        if (!hash_equals($eu, $usuario) || !hash_equals($ep, $clave)) {
            return ['status' => 403, 'body' => ['error' => 'Credenciales inválidas']];
        }

        $ttl = (int) PuiConfig::get('JWT_EXPIRES_SECONDS', 3600);
        $token = $this->jwt->issue([
            'sub' => 'PUI',
            'jti' => bin2hex(random_bytes(8)),
        ], $ttl);

        return [
            'status' => 200,
            'body' => [
                'token' => $token,
            ],
        ];
    }

    public function getJwtService(): JwtService
    {
        return $this->jwt;
    }
}
