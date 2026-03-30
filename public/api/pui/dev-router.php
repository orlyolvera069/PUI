<?php
/**
 * Servidor de desarrollo PHP embebido (sin Apache):
 *   cd public
 *   php -S localhost:8080 api/pui/dev-router.php
 *
 * Ejemplo: GET http://localhost:8080/api/pui/salud
 */
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if (strpos($path, '/api/pui') === 0) {
    require __DIR__ . '/index.php';
    return true;
}
return false;
