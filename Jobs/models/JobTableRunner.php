<?php

namespace Jobs\models;

use App\Pui\Repository\PuiJobOracleRepository;
use App\Pui\Repository\PuiReporteActivoOracleRepository;
use App\Pui\Service\PuiSearchOrchestratorService;

class JobTableRunner
{
    /**
     * @return array{procesados:int,errores:int}
     */
    public static function runOnce(int $limit, string $workerId): array
    {
        $jobsRepo = new PuiJobOracleRepository();
        $reportesRepo = new PuiReporteActivoOracleRepository();
        $orchestrator = new PuiSearchOrchestratorService();

        $procesados = 0;
        $errores = 0;
        $jobs = $jobsRepo->obtenerJobsPendientes($limit);
        foreach ($jobs as $job) {
            $id = (int) ($job['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $interval = max(1, (int) ($job['INTERVAL_MINUTES'] ?? 15));
            if (!$jobsRepo->tomarJob($id, $workerId)) {
                continue;
            }

            try {
                $payloadRaw = (string) ($job['PAYLOAD_JSON'] ?? '{}');
                $payload = json_decode($payloadRaw, true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                $idReporte = (string) ($job['ID_REPORTE'] ?? ($payload['id_reporte'] ?? ''));
                $esPrueba = !empty($payload['es_prueba']);
                if ($idReporte === '') {
                    throw new \RuntimeException('Job sin id_reporte.');
                }

                if (!$reportesRepo->estaActivo($idReporte)) {
                    $jobsRepo->cancelarJob($id);
                } else {
                    $requestId = bin2hex(random_bytes(12));
                    $orchestrator->ejecutarFase3PorReporte($requestId, $idReporte, $esPrueba);

                    if ($reportesRepo->estaActivo($idReporte)) {
                        $jobsRepo->marcarProcesado($id, $interval);
                        $procesados++;
                    } else {
                        $jobsRepo->cancelarJob($id);
                    }
                }
            } catch (\Throwable $e) {
                $idReporteForErr = (string) ($job['ID_REPORTE'] ?? '');
                if ($idReporteForErr !== '' && !$reportesRepo->estaActivo($idReporteForErr)) {
                    $jobsRepo->cancelarJob($id);
                } else {
                    $jobsRepo->marcarError($id, $e->getMessage());
                    $errores++;
                }
            } finally {
                $jobsRepo->liberarLock($id, $interval);
            }
        }

        return ['procesados' => $procesados, 'errores' => $errores];
    }
}
