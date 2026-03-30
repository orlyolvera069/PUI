<?php

namespace App\Pui\Integration;

use App\Pui\Config\PuiConfig;

class PuiOutboundFactory
{
    public static function create(bool $forzarPruebaMock = false): PuiOutboundClientInterface
    {
        // En cumplimiento estricto no se permiten fallback ni mocks silenciosos.
        PuiConfig::assertRealIntegrationReady();
        return new HttpPuiOutboundClient();
    }
}
