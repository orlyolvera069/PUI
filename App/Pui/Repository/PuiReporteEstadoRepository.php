<?php

namespace App\Pui\Repository;

/**
 * Estado de reportes PUI (activo/cerrado). Persistencia en archivo para no requerir nueva tabla Oracle de inmediato.
 * Producción: migrar a tabla dedicada (BITACORA_PUI_REPORTE) según políticas de la institución.
 */
class PuiReporteEstadoRepository
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $base = dirname(__DIR__, 2) . '/storage/pui';
        $this->path = $path ?? ($base . '/reportes_estado.json');
    }

    /** @return array<string,array<string,mixed>> */
    private function loadAll(): array
    {
        if (!is_readable($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /** @param array<string,mixed> $data */
    private function saveAll(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $this->path);
    }

    /** @param array<string,mixed> $registro */
    public function guardar(string $id, array $registro): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fp = fopen($this->path, 'c+');
        if ($fp === false) {
            $all = $this->loadAll();
            $all[$id] = $registro;
            $this->saveAll($all);
            return;
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $all = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
            if (!is_array($all)) {
                $all = [];
            }
            $all[$id] = $registro;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @return array<string,mixed>|null */
    public function obtener(string $id): ?array
    {
        $all = $this->loadAll();
        return $all[$id] ?? null;
    }
}
