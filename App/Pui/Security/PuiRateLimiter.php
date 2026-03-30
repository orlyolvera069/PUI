<?php

namespace App\Pui\Security;

class PuiRateLimiter
{
    private string $storageFile;

    public function __construct(?string $storageFile = null)
    {
        $this->storageFile = $storageFile ?? (APPPATH . '/storage/pui/rate_limit.json');
    }

    public function allow(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $key = trim($key);
        if ($key === '') {
            return true;
        }

        $maxRequests = max(1, $maxRequests);
        $windowSeconds = max(1, $windowSeconds);
        $now = time();
        $windowStart = $now - $windowSeconds;

        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($this->storageFile, 'c+');
        if (!is_resource($handle)) {
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($state)) {
                $state = [];
            }

            foreach ($state as $k => $timestamps) {
                if (!is_array($timestamps)) {
                    unset($state[$k]);
                    continue;
                }
                $state[$k] = array_values(array_filter($timestamps, static fn ($ts) => is_int($ts) && $ts >= $windowStart));
                if ($state[$k] === []) {
                    unset($state[$k]);
                }
            }

            $bucket = isset($state[$key]) && is_array($state[$key]) ? $state[$key] : [];
            if (count($bucket) >= $maxRequests) {
                return false;
            }

            $bucket[] = $now;
            $state[$key] = $bucket;

            $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $encoded = '{}';
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded);
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
