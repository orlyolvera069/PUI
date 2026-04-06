<?php

namespace App\Pui\Service;

use App\Pui\Config\PuiConfig;
use App\Pui\Http\PuiLogger;
use App\Pui\Integration\PuiOutboundBearerResolver;
use App\Pui\Integration\PuiOutboundClientInterface;
use App\Pui\Integration\PuiOutboundFactory;
use App\Pui\Integration\PuiOutboundTimeoutException;
use App\Pui\Mapper\NotificarCoincidenciaPayloadFactory;
use App\Pui\Repository\CultivaClienteRepository;
use App\Pui\Repository\PuiCoincidenciaMemoryRepository;
use App\Pui\Repository\PuiCoincidenciaOracleRepository;
use App\Pui\Repository\PuiReporteActivoMemoryRepository;
use App\Pui\Repository\PuiReporteActivoOracleRepository;
use App\Pui\Validation\ManualValidators;
use App\Pui\Validation\PuiManualPayloadValidator;

class PuiSearchOrchestratorService
{
    private CultivaClienteRepository $cl;
    private PuiCoincidenciaOracleRepository|PuiCoincidenciaMemoryRepository $coincidencias;
    private PuiReporteActivoOracleRepository|PuiReporteActivoMemoryRepository $reportesActivos;

    public function __construct(
        ?CultivaClienteRepository $cl = null,
        PuiCoincidenciaOracleRepository|PuiCoincidenciaMemoryRepository|null $coincidencias = null,
        PuiReporteActivoOracleRepository|PuiReporteActivoMemoryRepository|null $reportesActivos = null
    ) {
        $this->cl = $cl ?? new CultivaClienteRepository();
        $this->coincidencias = $coincidencias ?? new PuiCoincidenciaOracleRepository();
        $this->reportesActivos = $reportesActivos ?? new PuiReporteActivoOracleRepository();
    }

    /**
     * Ejecuta fases 1 y 2 + busqueda-finalizada.
     *
     * @param array<string,mixed> $body
     */
    public function ejecutarFases1y2(string $requestId, array $body, string $id, bool $esPrueba, string $institucionId): void
    {
        $outbound = PuiOutboundFactory::create($esPrueba);
        $fragmento = $this->fragmentoNombreDesdeActivar($body);

        // Fase 1
        $curp = (string) $body['curp'];
        $rowF1 = $this->cl->buscarFase1PorCurpExacta($curp);
        if ($rowF1 !== null) {
            // Si el activador no envió nombre/apellidos, derivamos criterio para fase 2/3 con base en CL.
            if ($fragmento === '') {
                $nombreD = trim((string) ($rowF1['NOMBRE1'] ?? ''));
                $paD = trim((string) ($rowF1['PRIMAPE'] ?? ''));
                $saD = trim((string) ($rowF1['SEGAPE'] ?? ''));
                $fragmento = trim(implode(' ', array_filter([$nombreD, $paD, $saD], static fn ($x) => $x !== '')));
            }

            if ($this->reportesActivos->estaActivo($id)) {
                $nombreD = trim((string) ($rowF1['NOMBRE1'] ?? ''));
                $paD = trim((string) ($rowF1['PRIMAPE'] ?? ''));
                $saD = trim((string) ($rowF1['SEGAPE'] ?? ''));
                $rfcD = trim((string) ($rowF1['RFC'] ?? ''));
                $curpPersist = strtoupper(trim((string) ($body['curp'] ?? $rowF1['CURP'] ?? '')));
                if ($curpPersist === '') {
                    throw new \InvalidArgumentException('CURP vacío en orquestador (f1) antes de persistir.');
                }

                // Persistimos criterio derivado para que fase 3 pueda ejecutarse sin depender del payload de activación.
                $this->reportesActivos->guardar($id, [
                    'id' => $id,
                    'curp' => $curpPersist,
                    'institucion_id' => $institucionId,
                    'nombre' => $nombreD,
                    'primer_apellido' => $paD,
                    'segundo_apellido' => $saD,
                    'rfc_criterio' => $rfcD,
                    'estado' => 'PROCESANDO',
                    'es_prueba' => $esPrueba,
                    'activo' => 1,
                ]);
            }

            $payload = NotificarCoincidenciaPayloadFactory::desdeRegistroCl(
                $rowF1,
                $id,
                $institucionId,
                '1',
                PuiReporteService::TIPO_F1,
                false,
                null
            );
            $this->enviarNotificacionValidada($requestId, $id, $institucionId, $outbound, $payload);
        }

        // Fase 2
        $fechaDesaparicion = isset($body['fecha_desaparicion']) ? trim((string) $body['fecha_desaparicion']) : '';
        if ($fechaDesaparicion !== '' && ManualValidators::fechaIso8601($fechaDesaparicion) && $fragmento !== '') {
            $end = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $endStr = $end->format('Y-m-d');

            $start = $end->modify('-12 years');
            $des = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaDesaparicion, new \DateTimeZone('UTC'));
            if ($des instanceof \DateTimeImmutable) {
                // Si la desaparición es dentro de los últimos 12 años, usamos esa como inicio.
                if ($des > $start) {
                    $start = $des;
                }
            }

            $fechaInicio = $start->format('Y-m-d');
            $fechaFin = $endStr;

            foreach ($this->cl->buscarFase2HistoricaPorNombre($fragmento, 30, $fechaInicio, $fechaFin) as $row) {
                $tipoEv = trim((string) ($row['TIPO_EVENTO'] ?? ''));
                $tipoFinal = $tipoEv !== '' ? $tipoEv : PuiReporteService::TIPO_F2;
                $fechaEv = trim((string) ($row['FECHA_EVENTO'] ?? ''));
                $fechaFinal = $fechaEv !== '' ? $fechaEv : $endStr;
                $payload = NotificarCoincidenciaPayloadFactory::desdeRegistroCl(
                    $row,
                    $id,
                    $institucionId,
                    '2',
                    $tipoFinal,
                    true,
                    $fechaFinal
                );
                $this->enviarNotificacionValidada($requestId, $id, $institucionId, $outbound, $payload);
            }
        }

        $bf = PuiManualPayloadValidator::normalizarBusquedaFinalizada([
            'id' => $id,
            'institucion_id' => $institucionId,
        ]);
        $verr = PuiManualPayloadValidator::busquedaFinalizada($bf);
        if ($verr !== []) {
            throw new \RuntimeException('Payload busqueda-finalizada inválido: ' . implode('; ', $verr));
        }
        PuiLogger::info($requestId, 'outbound_busqueda_finalizada_enviando', [
            'id' => $bf['id'],
            'institucion_id' => $bf['institucion_id'],
            'base_url_configurada' => (bool) trim((string) PuiConfig::get('PUI_OUTBOUND_BASE_URL', '')),
        ]);
        $rbf = $outbound->busquedaFinalizada($bf);
        PuiLogger::info($requestId, 'outbound_busqueda_finalizada_respuesta', [
            'http_status' => (int) ($rbf['http_status'] ?? 0),
            'saliente_jwt' => PuiOutboundBearerResolver::mustUseJwtLogin(),
        ]);
        $this->registrarAuditoriaSaliente($requestId, [
            'evento' => 'busqueda_finalizada',
            'tipo_evento' => 'busqueda_finalizada',
            'reporte_id' => $id,
            // Sin fase_busqueda: §7.3 no es "notificación de fase"; el 2 aquí confundía con "atascado en fase 2".
            'http_status' => $rbf['http_status'],
            'requestId' => $requestId,
            'endpoint' => 'busqueda-finalizada',
            'payload_json' => json_encode($bf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $abort = PuiConfig::get('PUI_ABORT_ON_NOTIFY_FAIL', '0');
        $abortOn = $abort === '1' || $abort === 1 || $abort === true;

        if ((int) ($rbf['http_status'] ?? 0) === 504) {
            if ($abortOn) {
                throw new PuiOutboundTimeoutException('busqueda-finalizada timeout');
            }
            PuiLogger::warning($requestId, 'outbound_busqueda_finalizada_timeout_continua', [
                'endpoint' => 'busqueda-finalizada',
            ]);

            return;
        }
        if ($rbf['http_status'] >= 200 && $rbf['http_status'] < 300) {
            try {
                $this->reportesActivos->marcarFechaFinFase2($id);
            } catch (\Throwable $e) {
                PuiLogger::warning($requestId, 'marcar_fecha_fin_fase2_error', ['msg' => $e->getMessage(), 'id' => $id]);
            }
        }
        if ($rbf['http_status'] < 200 || $rbf['http_status'] >= 300) {
            if ($abortOn) {
                throw new \RuntimeException('busqueda-finalizada HTTP ' . $rbf['http_status']);
            }
            $ctx = [
                'http_status' => $rbf['http_status'],
                'endpoint' => 'busqueda-finalizada',
            ];
            if ((int) ($rbf['http_status'] ?? 0) === 401) {
                $ctx['ayuda'] = '401: el simulador espera el JWT devuelto por POST …/login (clave PUI_OUTBOUND_LOGIN_CLAVE), no la clave como Bearer. Revise PUI_OUTBOUND_AUTH_MODE=login y reinicie PHP; si usaba PUI_OUTBOUND_TOKEN=clave, defina también PUI_OUTBOUND_LOGIN_CLAVE con el mismo valor.';
            }
            PuiLogger::warning($requestId, 'outbound_busqueda_finalizada_no_2xx_continua', $ctx);
        }
    }

    public function ejecutarFase3PorReporte(string $requestId, string $idReporte, bool $esPrueba): void
    {
        $reporte = $this->reportesActivos->obtener($idReporte);
        if ($reporte === null) {
            return;
        }
        if ((int) ($reporte['ACTIVO'] ?? $reporte['activo'] ?? 0) !== 1) {
            return;
        }

        $institucionId = strtoupper(trim((string) ($reporte['INSTITUCION_ID'] ?? $reporte['institucion_id'] ?? '')));
        if ($institucionId === '') {
            return;
        }

        $nombre = trim((string) ($reporte['CRITERIO_NOMBRE'] ?? $reporte['criterio_nombre'] ?? ''));
        $pa = trim((string) ($reporte['PRIMER_APELLIDO'] ?? $reporte['primer_apellido'] ?? ''));
        $sa = trim((string) ($reporte['SEGUNDO_APELLIDO'] ?? $reporte['segundo_apellido'] ?? ''));
        $fragmento = trim(implode(' ', array_filter([$nombre, $pa, $sa], static fn ($x) => $x !== '')));
        $rfcCriterio = trim((string) ($reporte['RFC_CRITERIO'] ?? $reporte['rfc_criterio'] ?? ''));

        if ($fragmento === '' && $rfcCriterio === '') {
            return;
        }

        $ultimaEj = trim((string) ($reporte['ULTIMA_EJECUCION_FASE3'] ?? $reporte['ultima_ejecucion_fase3'] ?? ''));
        $finF2 = trim((string) ($reporte['FECHA_FIN_FASE2'] ?? $reporte['fecha_fin_fase2'] ?? ''));
        $watermarkIso = null;
        $watermarkInclusive = false;
        if ($ultimaEj !== '') {
            $watermarkIso = $ultimaEj;
            $watermarkInclusive = false;
        } elseif ($finF2 !== '') {
            $watermarkIso = $finF2;
            $watermarkInclusive = true;
        }

        $outbound = PuiOutboundFactory::create($esPrueba);
        $coincidenciasNotificadas = 0;
        foreach ($this->cl->buscarFase3Continua(
            $fragmento,
            $rfcCriterio !== '' ? $rfcCriterio : null,
            30,
            $watermarkIso,
            $watermarkInclusive
        ) as $row) {
            if (!$this->reportesActivos->estaActivo($idReporte)) {
                $this->reportesActivos->actualizarUltimaEjecucionFase3($idReporte);
                return;
            }
            $curpRow = strtoupper(trim((string) ($row['CURP'] ?? '')));
            if ($curpRow !== '' && $this->coincidencias->existeCoincidenciaFasePorCurp($idReporte, '3', $curpRow)) {
                // Evitar reprocesar la misma coincidencia en búsqueda continua.
                continue;
            }
            $tipoEv = trim((string) ($row['TIPO_EVENTO'] ?? ''));
            $tipoFinal = $tipoEv !== '' ? $tipoEv : PuiReporteService::TIPO_F3;
            $fechaEv = trim((string) ($row['FECHA_EVENTO'] ?? ''));
            $fechaFinal = $fechaEv !== '' ? $fechaEv : gmdate('Y-m-d');
            $payload = NotificarCoincidenciaPayloadFactory::desdeRegistroCl(
                $row,
                $idReporte,
                $institucionId,
                '3',
                $tipoFinal,
                true,
                $fechaFinal
            );
            $this->enviarNotificacionValidada($requestId, $idReporte, $institucionId, $outbound, $payload);
            $coincidenciasNotificadas++;
        }

        // Auditoría interna: dejar evidencia de ejecución de fase 3 sin resultados.
        if ($coincidenciasNotificadas === 0) {
            $payloadScan = [
                'id_reporte' => $idReporte,
                'timestamp' => gmdate('c'),
                'criterio' => [
                    'fragmento' => $fragmento,
                    'rfc' => $rfcCriterio,
                ],
            ];
            $payloadScanJson = json_encode($payloadScan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadScanJson === false) {
                $payloadScanJson = '{}';
            }
            $this->registrarAuditoriaSaliente($requestId, [
                'evento' => 'scan_sin_resultados',
                'reporte_id' => $idReporte,
                'institucion_id' => $institucionId,
                'fase_busqueda' => '3',
                'tipo_evento' => 'scan_sin_resultados',
                'http_status' => null,
                'requestId' => $requestId,
                'endpoint' => 'fase3-scan-interno',
                'payload_json' => $payloadScanJson,
            ]);
        }

        $this->reportesActivos->actualizarUltimaEjecucionFase3($idReporte);

        // No debemos re-activar reportes que ya fueron desactivados durante la ejecución del job.
        if ($this->reportesActivos->estaActivo($idReporte)) {
            $this->reportesActivos->guardar($idReporte, [
                'id' => $idReporte,
                'curp' => (string) ($reporte['CURP'] ?? $reporte['curp'] ?? ''),
                'institucion_id' => $institucionId,
                'nombre' => $nombre,
                'primer_apellido' => $pa,
                'segundo_apellido' => $sa,
                'rfc_criterio' => $rfcCriterio,
                'estado' => 'ACTIVO',
                'es_prueba' => (int) ($reporte['ES_PRUEBA'] ?? $reporte['es_prueba'] ?? 0) === 1,
                'activo' => 1,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function enviarNotificacionValidada(
        string $requestId,
        string $reporteId,
        string $institucionId,
        PuiOutboundClientInterface $outbound,
        array $payload
    ): void {
        $verr = PuiManualPayloadValidator::notificarCoincidencia($payload);
        if ($verr !== []) {
            throw new \RuntimeException('Payload notificar-coincidencia inválido: ' . implode('; ', $verr));
        }

        $r = $outbound->notificarCoincidencia($payload);
        $this->registrarAuditoriaSaliente($requestId, [
            'evento' => 'notificar_coincidencia',
            'reporte_id' => $reporteId,
            'institucion_id' => $institucionId,
            'fase_busqueda' => (string) ($payload['fase_busqueda'] ?? ''),
            'tipo_evento' => $payload['tipo_evento'] ?? null,
            'http_status' => $r['http_status'],
            'requestId' => $requestId,
            'endpoint' => 'notificar-coincidencia',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $abort = PuiConfig::get('PUI_ABORT_ON_NOTIFY_FAIL', '0');
        $abortOn = $abort === '1' || $abort === 1 || $abort === true;

        if ((int) ($r['http_status'] ?? 0) === 504) {
            if ($abortOn) {
                throw new PuiOutboundTimeoutException('notificar-coincidencia timeout');
            }
            PuiLogger::warning($requestId, 'outbound_notificar_coincidencia_timeout_continua', [
                'fase_busqueda' => (string) ($payload['fase_busqueda'] ?? ''),
            ]);

            return;
        }
        if ($r['http_status'] < 200 || $r['http_status'] >= 300) {
            if ($abortOn) {
                throw new \RuntimeException('notificar-coincidencia HTTP ' . $r['http_status']);
            }
            PuiLogger::warning($requestId, 'outbound_notificar_coincidencia_no_2xx_continua', [
                'http_status' => $r['http_status'],
                'fase_busqueda' => (string) ($payload['fase_busqueda'] ?? ''),
            ]);
        }
    }

    /**
     * La auditoría en PUI_COINCIDENCIAS no debe impedir §7.2/§7.3 hacia la PUI si Oracle falla al insertar.
     *
     * @param array<string,mixed> $linea
     */
    private function registrarAuditoriaSaliente(string $requestId, array $linea): void
    {
        try {
            $this->coincidencias->registrarCoincidencia($linea);
        } catch (\Throwable $e) {
            PuiLogger::warning($requestId, 'pui_coincidencias_auditoria_no_guardada', [
                'msg' => $e->getMessage(),
                'endpoint' => (string) ($linea['endpoint'] ?? ''),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $body
     */
    private function fragmentoNombreDesdeActivar(array $body): string
    {
        $parts = [
            trim((string) ($body['nombre'] ?? '')),
            trim((string) ($body['primer_apellido'] ?? '')),
            trim((string) ($body['segundo_apellido'] ?? '')),
        ];
        $parts = array_filter($parts, static fn ($x) => $x !== '');
        return $parts !== [] ? implode(' ', $parts) : '';
    }
}
