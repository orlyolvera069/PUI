<?php

namespace App\Pui\Config;

use App\Pui\Integration\PuiOutboundBearerResolver;

defined('APPPATH') or define('APPPATH', dirname(__DIR__, 2));

/**
 * Configuración del módulo PUI.
 *
 * Prioridad de resolución (por clave):
 * 1. `App/config/pui.ini` — fuente principal; si la clave existe en el archivo, se usa siempre su valor.
 * 2. Variable de entorno con el mismo nombre — solo si la clave NO está definida en `pui.ini`.
 * 3. Valor por defecto pasado a `get($key, $default)`.
 *
 * Así se evita que `getenv()` sobrescriba silenciosamente valores correctos del INI (p. ej. `PUI_LOGIN_CLAVE`).
 */
class PuiConfig
{
    private const INI_RELATIVE_PATH = '/config/pui.ini';
    private const JWT_SECRET_PLACEHOLDERS = [
        'change_me_dev_only_pui_jwt_secret',
        'change_me_dev_only_pui_jwt_secret_min_32_chars_recommended',
    ];

    /**
     * Claves conocidas del módulo (para `debug()` y diagnóstico aunque falten en el INI).
     *
     * @var list<string>
     */
    private const KNOWN_KEYS = [
        'PUI_PUBLIC_BASE',
        'PUI_MOCK_MODE',
        'PUI_INTEGRATION_MODE',
        'INSTITUCION_RFC',
        'PUI_OUTBOUND_BASE_URL',
        'PUI_OUTBOUND_AUTH_MODE',
        'PUI_OUTBOUND_LOGIN_PATH',
        'PUI_OUTBOUND_LOGIN_INSTITUCION_ID',
        'PUI_OUTBOUND_LOGIN_USUARIO',
        'PUI_OUTBOUND_LOGIN_CLAVE',
        'PUI_OUTBOUND_LOGIN_BODY_STYLE',
        'PUI_OUTBOUND_LOGIN_CACHE_SECONDS',
        'PUI_OUTBOUND_LOGIN_EXPIRY_MARGIN_SECONDS',
        'PUI_OUTBOUND_TOKEN',
        'PUI_OUTBOUND_TOKEN_NOTIFICAR',
        'PUI_OUTBOUND_TOKEN_BUSQUEDA_FINALIZADA',
        'PUI_PATH_NOTIFICAR_COINCIDENCIA',
        'PUI_PATH_BUSQUEDA_FINALIZADA',
        'PUI_HTTP_RETRIES',
        'PUI_HTTP_RETRY_MS',
        'PUI_REMOTE_TIMEOUT_MS',
        'PUI_ABORT_ON_NOTIFY_FAIL',
        'JWT_SECRET',
        'JWT_EXPIRES_SECONDS',
        'JWT_ISSUER',
        'JWT_AUDIENCE',
        'PUI_LOGIN_USUARIO',
        'PUI_LOGIN_CLAVE',
        'PUI_LOG_PREFIX',
        'PUI_E2E_ENABLE',
        'PUI_DEBUG_CONFIG',
        'PUI_DB_SESSION_DEBUG',
        'PUI_SQL_DEBUG',
        'PUI_ENABLE_AUX_PERSONA',
        'PUI_ENABLE_AUX_BUSQUEDA',
        'PUI_STRICT_REAL_MODE',
        'PUI_RATE_LIMIT_ENABLED',
        'PUI_RATE_LIMIT_MAX_REQUESTS',
        'PUI_RATE_LIMIT_WINDOW_SECONDS',
        'PUI_CL_ACTIVIDAD_TIMESTAMP_EXPR',
        'PUI_VERBOSE_CLIENT_ERRORS',
        'PUI_ENABLE_ACTIVAR_PRUEBA',
        'PUI_ENABLE_TEST_LOGIN_SIMULADOR',
        'PUI_OUTBOUND_PING_PATH',
        'PUI_OUTBOUND_PING_TIMEOUT_MS',
        'PUI_PADRON_SCHEMA',
        'PUI_FASE3_DAEMON_SLEEP_SECONDS',
        'PUI_SIMULADOR_SYNC_DELAY_US',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /**
     * Carga obligatoria de `pui.ini`. Si el archivo no existe o no se puede leer, lanza excepción.
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public static function ensureLoaded(): void
    {
        if (self::$data !== null) {
            return;
        }

        $path = self::iniPath();
        if (!is_file($path)) {
            throw new \RuntimeException(
                'PUI: no se encontró el archivo de configuración: ' . $path
            );
        }
        if (!is_readable($path)) {
            throw new \RuntimeException(
                'PUI: el archivo de configuración no es legible: ' . $path
            );
        }

        $parsed = @parse_ini_file($path, false, INI_SCANNER_TYPED);
        if ($parsed === false) {
            $msg = 'PUI: error al analizar pui.ini (sintaxis inválida o archivo dañado): ' . $path;
            error_log($msg);
            throw new \InvalidArgumentException($msg);
        }

        self::$data = is_array($parsed) ? $parsed : [];
    }

    /**
     * Ruta absoluta a `pui.ini`.
     */
    public static function iniPath(): string
    {
        return APPPATH . self::INI_RELATIVE_PATH;
    }

    /**
     * Todos los valores cargados desde `pui.ini` (sin aplicar env ni defaults).
     * Fuerza la carga del archivo; falla con la misma excepción que `ensureLoaded()` si aplica.
     *
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        self::ensureLoaded();
        return self::$data ?? [];
    }

    /**
     * Obtiene un valor de configuración.
     *
     * @param mixed $default Valor si la clave no está en INI ni en entorno
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        self::ensureLoaded();
        $resolved = self::resolve($key, $default);

        if (self::isDebugConfigEnabled()) {
            $display = self::maskSensitive($key, $resolved['value']);
            error_log(sprintf(
                '[PUI config] %s = %s (source=%s)',
                $key,
                is_scalar($display) ? (string) $display : json_encode($display),
                $resolved['source']
            ));
        }

        return $resolved['value'];
    }

    /**
     * Diagnóstico: todas las claves relevantes con valor (enmascarado si es sensible) y fuente.
     *
     * @return array<string,array{value:mixed,source:string}>
     */
    public static function debug(): array
    {
        self::ensureLoaded();
        $keys = array_unique(array_merge(array_keys(self::$data ?? []), self::KNOWN_KEYS));
        sort($keys);

        $out = [];
        foreach ($keys as $key) {
            $r = self::resolve($key, null);
            $out[$key] = [
                'value' => self::maskSensitive($key, $r['value']),
                'source' => $r['source'],
            ];
        }

        return $out;
    }

    public static function isMockMode(): bool
    {
        // Default conservador: si no se configura explícitamente, NO usar mock.
        $v = self::get('PUI_MOCK_MODE', '0');
        return $v === true || $v === 1 || $v === '1';
    }

    public static function isSimulationMode(): bool
    {
        if (self::isMockMode()) {
            return true;
        }
        $mode = strtolower(trim((string) self::get('PUI_INTEGRATION_MODE', 'real')));
        return $mode === 'mock';
    }

    public static function publicBase(): string
    {
        return rtrim((string) self::get('PUI_PUBLIC_BASE', '/api/pui'), '/');
    }

    /**
     * Modo de cumplimiento estricto del manual técnico:
     * - PUI_MOCK_MODE debe ser 0
     * - PUI_INTEGRATION_MODE debe ser real
     * - PUI_OUTBOUND_BASE_URL y autenticación saliente: PUI_OUTBOUND_TOKEN (modo static) o login (PUI_OUTBOUND_AUTH_MODE=login + clave)
     *
     * @throws \RuntimeException
     */
    public static function assertRealIntegrationReady(): void
    {
        $strict = self::get('PUI_STRICT_REAL_MODE', '1');
        $strictEnabled = $strict === true || $strict === 1 || $strict === '1';
        if (!$strictEnabled) {
            return;
        }

        $mockMode = self::get('PUI_MOCK_MODE', '0');
        if ($mockMode === true || $mockMode === 1 || $mockMode === '1') {
            throw new \RuntimeException('Configuración inválida: PUI_MOCK_MODE debe ser 0 en cumplimiento estricto.');
        }

        $integrationMode = strtolower(trim((string) self::get('PUI_INTEGRATION_MODE', 'real')));
        if ($integrationMode !== 'real') {
            throw new \RuntimeException('Configuración inválida: PUI_INTEGRATION_MODE debe ser real en cumplimiento estricto.');
        }

        $base = trim((string) self::get('PUI_OUTBOUND_BASE_URL', ''));
        if ($base === '') {
            throw new \RuntimeException('Configuración inválida: PUI_OUTBOUND_BASE_URL es obligatorio en modo real.');
        }

        if (PuiOutboundBearerResolver::mustUseJwtLogin()) {
            $clave = PuiOutboundBearerResolver::effectiveLoginClave();
            if ($clave === '') {
                throw new \RuntimeException(
                    'Configuración inválida: autenticación saliente por JWT requiere PUI_OUTBOUND_LOGIN_CLAVE (o PUI_OUTBOUND_TOKEN con forma simulador_PUI_* solo como clave de login, no como Bearer).'
                );
            }
            return;
        }

        $token = trim((string) self::get('PUI_OUTBOUND_TOKEN', ''));
        if ($token === '') {
            throw new \RuntimeException('Configuración inválida: PUI_OUTBOUND_TOKEN es obligatorio en modo real (o configure login saliente con PUI_OUTBOUND_LOGIN_CLAVE).');
        }
        if (stripos($token, 'CHANGE_ME') !== false) {
            throw new \RuntimeException('Configuración inválida: PUI_OUTBOUND_TOKEN debe ser un valor real, no placeholder.');
        }
    }

    public static function logPrefix(): string
    {
        return (string) self::get('PUI_LOG_PREFIX', '[PUI]');
    }

    /**
     * Si es false, las respuestas JSON de error no incluyen detalles internos ni listados de validación (§10).
     */
    public static function exposeErrorDetailInResponse(): bool
    {
        $v = self::get('PUI_VERBOSE_CLIENT_ERRORS', '0');
        return $v === true || $v === 1 || $v === '1';
    }

    /**
     * Obtiene JWT_SECRET y falla si está ausente o inseguro.
     *
     * @throws \RuntimeException
     */
    public static function jwtSecretOrFail(): string
    {
        $secret = trim((string) self::get('JWT_SECRET', ''));
        if ($secret === '') {
            throw new \RuntimeException('PUI: JWT_SECRET es obligatorio y no puede estar vacío.');
        }
        foreach (self::JWT_SECRET_PLACEHOLDERS as $placeholder) {
            if (hash_equals($placeholder, $secret)) {
                throw new \RuntimeException('PUI: JWT_SECRET usa placeholder inseguro. Configure un secreto real.');
            }
        }
        if (strlen($secret) < 32) {
            throw new \RuntimeException('PUI: JWT_SECRET debe tener al menos 32 caracteres.');
        }
        return $secret;
    }

    /**
     * Resolución con prioridad: INI > env (solo si la clave no está en INI) > default.
     *
     * @return array{value:mixed,source:'ini'|'env'|'default'}
     */
    private static function resolve(string $key, $default): array
    {
        $all = self::$data ?? [];

        if (array_key_exists($key, $all)) {
            return ['value' => $all[$key], 'source' => 'ini'];
        }

        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return ['value' => $env, 'source' => 'env'];
        }

        return ['value' => $default, 'source' => 'default'];
    }

    /**
     * Modo trazabilidad: `PUI_DEBUG_CONFIG=1` en pui.ini (preferido) o en entorno si la clave no está en INI.
     */
    private static function isDebugConfigEnabled(): bool
    {
        $all = self::$data ?? [];
        if (array_key_exists('PUI_DEBUG_CONFIG', $all)) {
            $v = $all['PUI_DEBUG_CONFIG'];
            return $v === true || $v === 1 || $v === '1';
        }

        $e = getenv('PUI_DEBUG_CONFIG');
        if ($e !== false && $e !== '') {
            return $e === '1' || $e === 1 || $e === true;
        }

        return false;
    }

    /**
     * Oculta secretos en logs y en `debug()`.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function maskSensitive(string $key, $value)
    {
        if (!self::isSensitiveKey($key)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $value;
        }
        return '***';
    }

    private static function isSensitiveKey(string $key): bool
    {
        $k = strtoupper($key);
        foreach (['SECRET', 'CLAVE', 'TOKEN', 'PASSWORD', 'PASS'] as $frag) {
            if (strpos($k, $frag) !== false) {
                return true;
            }
        }
        return false;
    }
}
