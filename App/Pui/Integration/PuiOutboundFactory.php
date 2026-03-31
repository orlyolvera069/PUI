<?php

namespace App\Pui\Integration;

use App\Pui\Config\PuiConfig;

class PuiOutboundFactory
{
    /**
     * @param bool $esPrueba reservado para futuras ramas (p. ej. trazas); el mock solo aplica en modo simulación.
     */
    public static function create(bool $esPrueba = false): PuiOutboundClientInterface
    {
        // Manual Técnico — modo simulación (MOCK): sin llamadas HTTP salientes a la PUI (§7.2–7.3).
        if (PuiConfig::isSimulationMode()) {
            return new MockPuiOutboundClient();
        }
        PuiConfig::assertRealIntegrationReady();
        return new HttpPuiOutboundClient();
    }
}
