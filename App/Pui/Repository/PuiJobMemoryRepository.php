<?php

namespace App\Pui\Repository;

/**
 * Cola de jobs en memoria — modo simulación (fase 3 no persiste entre procesos).
 */
class PuiJobMemoryRepository
{
    /** @var array<string, array<string, mixed>> clave: JOB_TYPE . '|' . ID_REPORTE */
    private static array $jobs = [];

    public function programarFase3(string $idReporte, bool $esPrueba, int $intervalMinutes = 15): void
    {
        $k = PuiJobOracleRepository::JOB_FASE3_SCAN . '|' . $idReporte;
        self::$jobs[$k] = [
            'JOB_TYPE' => PuiJobOracleRepository::JOB_FASE3_SCAN,
            'ID_REPORTE' => $idReporte,
            'STATUS' => 'PENDING',
            'es_prueba' => $esPrueba,
            'INTERVAL_MINUTES' => max(1, $intervalMinutes),
        ];
    }

    public function cancelarJobsReporte(string $idReporte): void
    {
        foreach (self::$jobs as $k => $row) {
            if (($row['ID_REPORTE'] ?? '') === $idReporte) {
                self::$jobs[$k]['STATUS'] = 'CANCELLED';
            }
        }
    }

    public function cancelarJob(int $idJob): void
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function obtenerJobsPendientes(int $limit = 20): array
    {
        return [];
    }
}
