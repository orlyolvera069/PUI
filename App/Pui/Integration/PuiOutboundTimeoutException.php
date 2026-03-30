<?php

namespace App\Pui\Integration;

/**
 * Llamada HTTP saliente hacia la PUI excedió el tiempo de espera (equivalente 504).
 */
class PuiOutboundTimeoutException extends \RuntimeException
{
}
