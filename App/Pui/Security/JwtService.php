<?php

namespace App\Pui\Security;

/**
 * JWT HS256 sin dependencias externas (manual PUI: Bearer en cada petición).
 * En producción: rotar JWT_SECRET vía gestor de secretos; valor en pui.ini solo para dev.
 */
class JwtService
{
    public function __construct(
        private string $secret,
        private ?string $expectedIssuer = null,
        private ?string $expectedAudience = null,
        private int $leewaySeconds = 60
    ) {
    }

    /**
     * @param array<string,mixed> $claims claims estándar + datos de negocio permitidos por el manual
     */
    public function issue(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => $this->expectedIssuer ?? 'cultiva-pui',
            'aud' => $this->expectedAudience ?? 'pui-institucion',
            'nbf' => $now,
        ], $claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);
        return $this->sign($payload);
    }

    /** @return array<string,mixed>|null payload decodificado o null si inválido/expirado */
    public function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $sig64] = $parts;
        $header = json_decode($this->b64urlDecode($h64), true);
        if (!is_array($header)) {
            return null;
        }
        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            return null;
        }
        $expected = $this->b64urlEncode(hash_hmac('sha256', $h64 . '.' . $p64, $this->secret, true));
        if (!hash_equals($expected, $sig64)) {
            return null;
        }
        $payload = json_decode($this->b64urlDecode($p64), true);
        if (!is_array($payload)) {
            return null;
        }
        $now = time();
        if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
            return null;
        }
        if ((int) $payload['exp'] < ($now - $this->leewaySeconds)) {
            return null;
        }
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > ($now + $this->leewaySeconds)) {
            return null;
        }
        if (isset($payload['iat']) && is_numeric($payload['iat']) && (int) $payload['iat'] > ($now + $this->leewaySeconds)) {
            return null;
        }
        if ($this->expectedIssuer !== null && ($payload['iss'] ?? null) !== $this->expectedIssuer) {
            return null;
        }
        if ($this->expectedAudience !== null && ($payload['aud'] ?? null) !== $this->expectedAudience) {
            return null;
        }
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function sign(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $h64 = $this->b64urlEncode(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $p64 = $this->b64urlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig = $this->b64urlEncode(hash_hmac('sha256', $h64 . '.' . $p64, $this->secret, true));
        return $h64 . '.' . $p64 . '.' . $sig;
    }

    private function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($b64, true);
    }
}
