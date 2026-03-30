<?php

namespace App\Pui\Repository;

/**
 * Auditoría de coincidencias notificadas hacia la PUI (trazabilidad regulatoria).
 */
class PuiAuditoriaRepository
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $base = dirname(__DIR__, 2) . '/storage/pui';
        $this->path = $path ?? ($base . '/auditoria_coincidencias.log');
    }

    /**
     * @param array<string,mixed> $linea
     */
    public function registrarCoincidencia(array $linea): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $linea['ts'] = gmdate('c');
        file_put_contents($this->path, json_encode($linea, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
