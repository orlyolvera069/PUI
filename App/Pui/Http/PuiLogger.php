<?php

namespace App\Pui\Http;

use App\Pui\Config\PuiConfig;

class PuiLogger
{
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

    private static function write(string $level, string $requestId, string $message, array $context): void
    {
        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'requestId' => $requestId,
            'msg' => $message,
            'ctx' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log(PuiConfig::logPrefix() . ' ' . $line);
    }
}
