<?php
/**
 * Router para php -S (desarrollo): enruta /api/pui/* al front controller API.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}
$normalized = rtrim((string) $uri, '/');
if (stripos($normalized, '/api/pui') === 0) {
    require __DIR__ . '/api/pui/index.php';
    return true;
}
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'PUI: use /api/pui (ver README).';
return true;
