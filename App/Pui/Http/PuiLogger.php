<?php

namespace App\Pui\Http;

use App\Pui\Config\PuiConfig;
use App\Pui\Exception\DatabaseUnavailableException;

class PuiLogger
{
    private static ?string $requestContext = null;

    /**
     * Debe invocarse al inicio del request (p. ej. PuiFrontController::dispatch) para correlacionar logs con meta.requestId.
     */
    public static function setRequestContext(?string $requestId): void
    {
        self::$requestContext = $requestId;
    }

    public static function requestContextId(): string
    {
        return self::$requestContext ?? 'no-req';
    }

    /**
     * Lanza siempre {@see DatabaseUnavailableException} con causa encadenada (manual: detalle null al cliente).
     *
     * @throws DatabaseUnavailableException
     */
    public static function throwDatabaseUnavailable(?\Throwable $previous = null): void
    {
        error_log('[PUI][DEBUG] entering DB exception catch');
        throw new DatabaseUnavailableException('PUI-DB-503', 0, $previous ?? new \RuntimeException('db_activa is null'));
    }

    public static function info(string $requestId, string $message, array $context = []): void
    {
        self::write('INFO', $requestId, $message, $context);
    }

    public static function warning(string $requestId, string $message, array $context = []): void
    {
        self::write('WARN', $requestId, $message, $context);
    }

    public static function error(string $requestId, string $message, array $context = []): void
    {
        self::write('ERROR', $requestId, $message, $context);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function sanitizeParamsForLog(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $key = (string) $k;
            if (\is_string($v)) {
                $lower = strtolower($key);
                if (str_contains($lower, 'password') || str_contains($lower, 'clave') || str_contains($lower, 'secret')) {
                    $out[$key] = '***';
                    continue;
                }
                $s = $v;
                if (strlen($s) > 500) {
                    $s = substr($s, 0, 500) . '…(truncado)';
                }
                $out[$key] = $s;
                continue;
            }
            if (\is_scalar($v) || $v === null) {
                $out[$key] = $v;
                continue;
            }
            $out[$key] = '…(no escalar)';
        }
        return $out;
    }

    private static function write(string $level, string $requestId, string $message, array $context): void
    {
        static $logErrorsTouched = false;
        if (!$logErrorsTouched) {
            @ini_set('log_errors', '1');
            $logErrorsTouched = true;
        }

        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'requestId' => $requestId,
            'msg' => $message,
            'ctx' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $full = PuiConfig::logPrefix() . ' ' . $line;

        error_log($full);

        if (@file_put_contents('php://stderr', $full . PHP_EOL, 0) === false && \defined('STDERR') && \is_resource(STDERR)) {
            @fwrite(STDERR, $full . PHP_EOL);
        }
    }
}
