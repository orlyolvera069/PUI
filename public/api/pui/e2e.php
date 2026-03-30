<?php
/**
 * Simulación E2E (solo desarrollo). Activar en App/config/pui.ini: PUI_E2E_ENABLE = 1
 */
declare(strict_types=1);

define('PROJECTPATH', dirname(__DIR__, 3));
define('APPPATH', PROJECTPATH . '/App');

require_once __DIR__ . '/autoload_pui.php';

use App\Pui\Config\PuiConfig;
use App\Pui\SimulacionE2e;

header('Content-Type: application/json; charset=UTF-8');

if ((string) PuiConfig::get('PUI_E2E_ENABLE', '0') !== '1') {
    http_response_code(403);
    echo json_encode(['error' => 'PUI_E2E_ENABLE no está activado en pui.ini']);
    exit;
}

echo json_encode(SimulacionE2e::ejecutar(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
