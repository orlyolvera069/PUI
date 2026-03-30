<?php
/**
 * Front controller PUI — Manual Técnico (modelo por eventos / reportes).
 *
 * Institución expone (base típica /api/pui):
 *   POST /api/pui/login
 *   POST /api/pui/activar-reporte
 *   POST /api/pui/activar-reporte-prueba
 *   POST /api/pui/desactivar-reporte
 *   GET  /api/pui/salud
 *
 * Salida hacia la PUI (cliente HTTP): notificar-coincidencia, busqueda-finalizada (ver pui.ini).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

define('PROJECTPATH', dirname(__DIR__, 3));
define('APPPATH', PROJECTPATH . '/App');

require_once PROJECTPATH . '/public/api/pui/autoload_pui.php';

use App\Pui\Http\PuiFrontController;

@header_remove('X-Powered-By');
@header_remove('Server');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
// Si el servidor web reinyecta Server/X-Powered-By, suprimirlos también en Apache (Header unset) o nginx (proxy_hide_header).
if (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$controller = new PuiFrontController();
$controller->dispatch();
