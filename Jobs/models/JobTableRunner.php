<?php

namespace Jobs\models;

use App\Pui\Config\PuiConfig;
use App\Pui\Http\PuiLogger;
use App\Pui\Repository\PuiJobOracleRepository;
use App\Pui\Repository\PuiReporteActivoOracleRepository;
use App\Pui\Integration\PuiOutboundFactory;
use App\Pui\Service\PuiSearchOrchestratorService;

class JobTableRunner
{
    /**
     * @return array{procesados:int,errores:int,candidatos:int}
     */
    public static function runOnce(int $limit, string $workerId): array
    {
        $jobsRepo = new PuiJobOracleRepository();
        $reportesRepo = new PuiReporteActivoOracleRepository();
        $orchestrator = new PuiSearchOrchestratorService();

        $procesados = 0;
        $errores = 0;
        try {
            $runRid = 'job-runner-' . bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $runRid = 'job-runner-' . uniqid('', true);
        }
        $staleLockSeconds = max(120, PuiConfig::fase3StaleLockSeconds());
        $jobsRepo->requeueStaleRunning($staleLockSeconds, $runRid);
        $jobs = $jobsRepo->obtenerJobsPendientes($limit);
        $resumenCandidatos = [];
        foreach ($jobs as $j) {
            $resumenCandidatos[] = [
                'id' => (int) ($j['ID'] ?? 0),
                'job_type' => (string) ($j['JOB_TYPE'] ?? ''),
                'id_reporte' => (string) ($j['ID_REPORTE'] ?? ''),
                'status' => (string) ($j['STATUS'] ?? ''),
                'run_at' => (string) ($j['RUN_AT'] ?? ''),
            ];
        }
        PuiLogger::info($runRid, 'job_table_runner_tick', [
            'worker' => $workerId,
            'limite' => $limit,
            'candidatos' => $resumenCandidatos,
            'n_candidatos' => \count($jobs),
        ]);

        foreach ($jobs as $job) {
            $id = (int) ($job['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $rescheduleSec = PuiConfig::fase3JobIntervalSeconds();
            if (!$jobsRepo->tomarJob($id, $workerId, $runRid)) {
                PuiLogger::warning($runRid, 'job_tomar_fallido', [
                    'job_id' => $id,
                    'worker' => $workerId,
                    'job_type' => (string) ($job['JOB_TYPE'] ?? ''),
                    'id_reporte' => (string) ($job['ID_REPORTE'] ?? ''),
                    'run_at' => (string) ($job['RUN_AT'] ?? ''),
                    'nota' => 'Otro worker tomó el job, el estado ya no era PENDING/RETRY, o fallo de BD.',
                ]);
                continue;
            }

            PuiLogger::info($runRid, 'job_tomado', [
                'job_id' => $id,
                'worker' => $workerId,
                'job_type' => (string) ($job['JOB_TYPE'] ?? ''),
                'id_reporte' => (string) ($job['ID_REPORTE'] ?? ''),
            ]);

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
                        if (!$jobsRepo->marcarProcesado($id, $rescheduleSec, $runRid)) {
                            PuiLogger::warning($runRid, 'fase3_marcar_procesado_omitido', [
                                'job_id' => $id,
                                'id_reporte' => $idReporte,
                                'nota' => 'El job no estaba RUNNING (p. ej. cancelado en paralelo) o no se pudo confirmar PENDING.',
                            ]);
                        }
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
                    $marcado = $isResyncNotificar || $isResyncFinalizada
                        ? $jobsRepo->marcarErrorConBackoff($id, $e->getMessage(), $runRid)
                        : $jobsRepo->marcarError($id, $e->getMessage(), $runRid);
                    if (!$marcado) {
                        PuiLogger::warning($runRid, 'marcar_error_sin_efecto', [
                            'job_id' => $id,
                            'nota' => 'El job ya no estaba RUNNING (p. ej. cancelación concurrente).',
                        ]);
                    }
                    $errores++;
                }
            } finally {
                try {
                    $jobsRepo->liberarLock($id, $rescheduleSec, $runRid);
                } catch (\Throwable $t) {
                    PuiLogger::warning($runRid, 'job_finally_liberarLock_excepcion', [
                        'job_id' => $id,
                        'msg' => $t->getMessage(),
                        'class' => \get_class($t),
                    ]);
                }
            }
        }

        $jobsRepo->requeueStaleRunning($staleLockSeconds, $runRid);

        return ['procesados' => $procesados, 'errores' => $errores, 'candidatos' => \count($jobs)];
    }
}
