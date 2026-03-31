<?php

namespace Jobs\models;

use App\Pui\Repository\PuiJobOracleRepository;
use App\Pui\Repository\PuiReporteActivoOracleRepository;
use App\Pui\Integration\PuiOutboundFactory;
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

                $jobType = (string) ($job['JOB_TYPE'] ?? '');
                $idReporte = (string) ($job['ID_REPORTE'] ?? ($payload['id_reporte'] ?? ''));
                $esPrueba = !empty($payload['es_prueba']);
                if ($idReporte === '') {
                    throw new \RuntimeException('Job sin id_reporte.');
                }

                $isResyncNotificar = str_starts_with($jobType, PuiJobOracleRepository::JOB_RESYNC_NOTIFICAR . '_');
                $isResyncFinalizada = str_starts_with($jobType, PuiJobOracleRepository::JOB_RESYNC_FINALIZADA . '_');
                if ($isResyncNotificar || $isResyncFinalizada) {
                    $outbound = PuiOutboundFactory::create($esPrueba);
                    if ($isResyncNotificar) {
                        $res = $outbound->notificarCoincidencia($payload);
                    } else {
                        $res = $outbound->busquedaFinalizada($payload);
                    }

                    $status = (int) ($res['http_status'] ?? 0);
                    if ($status >= 200 && $status < 300) {
                        $jobsRepo->cancelarJob($id);
                        $procesados++;
                    } else {
                        throw new \RuntimeException('Resync HTTP ' . $status);
                    }
                } elseif (!$reportesRepo->estaActivo($idReporte)) {
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
                $jobType = (string) ($job['JOB_TYPE'] ?? '');
                $isResyncNotificar = str_starts_with($jobType, PuiJobOracleRepository::JOB_RESYNC_NOTIFICAR . '_');
                $isResyncFinalizada = str_starts_with($jobType, PuiJobOracleRepository::JOB_RESYNC_FINALIZADA . '_');
                if (
                    !$isResyncNotificar
                    && !$isResyncFinalizada
                    && $idReporteForErr !== ''
                    && !$reportesRepo->estaActivo($idReporteForErr)
                ) {
                    $jobsRepo->cancelarJob($id);
                } else {
                    if ($isResyncNotificar || $isResyncFinalizada) {
                        $jobsRepo->marcarErrorConBackoff($id, $e->getMessage());
                    } else {
                        $jobsRepo->marcarError($id, $e->getMessage());
                    }
                    $errores++;
                }
            } finally {
                $jobsRepo->liberarLock($id, $interval);
            }
        }

        return ['procesados' => $procesados, 'errores' => $errores];
    }
}
