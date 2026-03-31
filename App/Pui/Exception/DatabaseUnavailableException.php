<?php

namespace App\Pui\Exception;

/**
 * Indica que Oracle no está disponible (sin conexión o enlace roto).
 * No incluir detalles técnicos en el mensaje.
 */
class DatabaseUnavailableException extends \RuntimeException
{
}
