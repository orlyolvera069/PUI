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
     * Secuencia manual: fase 1 → fase 2 (solo con fecha_desaparicion) → §7.3 busqueda-finalizada (siempre).
     * Fallos en notificaciones no cancelan busqueda-finalizada salvo PUI_ABORT_ON_NOTIFY_FAIL.
     *
     * @param array<string,mixed> $body
     */
    public function ejecutarFases1y2(string $requestId, array $body, string $id, bool $esPrueba, string $institucionId): void
    {
        $outbound = PuiOutboundFactory::create($esPrueba);
        $fragmento = $this->fragmentoNombreDesdeActivar($body);

        try {
            // —— Fase 1: §7.2 datos básicos. §8.2 nota 5: sin fila en CLIENTE se arma el cuerpo con activar-reporte.
            PuiLogger::info($requestId, 'fase1_inicio', ['id' => $id]);
            $curp = ManualValidators::normalizeCurp((string) ($body['curp'] ?? ''));
            $rowF1 = $this->cl->buscarFase1PorCurpExacta($curp);
            $desdePadron = $rowF1 !== null;
            if (!$desdePadron) {
                PuiLogger::info($requestId, 'fase1_sin_padron', [
                    'id' => $id,
                    'contexto' => '§7.2 fase 1 con datos de §8.2 (activar-reporte)',
                ]);
                $rowF1 = $this->construirFilaClienteDesdeCuerpoActivar($body);
            }

            if ($fragmento === '') {
                if ($desdePadron) {
                    $nombreD = trim((string) ($rowF1['NOMBRE1'] ?? ''));
                    $paD = trim((string) ($rowF1['PRIMAPE'] ?? ''));
                    $saD = trim((string) ($rowF1['SEGAPE'] ?? ''));
                } else {
                    $nombreD = trim((string) ($body['nombre'] ?? ''));
                    $paD = trim((string) ($body['primer_apellido'] ?? ''));
                    $saD = trim((string) ($body['segundo_apellido'] ?? ''));
                }
                $fragmento = trim(implode(' ', array_filter([$nombreD, $paD, $saD], static fn ($x) => $x !== '')));
            }

            if ($this->reportesActivos->estaActivo($id)) {
                if ($desdePadron) {
                    $nombreD = trim((string) ($rowF1['NOMBRE1'] ?? ''));
                    $paD = trim((string) ($rowF1['PRIMAPE'] ?? ''));
                    $saD = trim((string) ($rowF1['SEGAPE'] ?? ''));
                    $rfcD = trim((string) ($rowF1['RFC'] ?? ''));
                } else {
                    $nombreD = trim((string) ($body['nombre'] ?? ''));
                    $paD = trim((string) ($body['primer_apellido'] ?? ''));
                    $saD = trim((string) ($body['segundo_apellido'] ?? ''));
                    $rfcD = strtoupper(trim((string) ($body['rfc_criterio'] ?? '')));
                }
                $curpPersist = $curp !== '' ? $curp : strtoupper(trim((string) ($rowF1['CURP'] ?? '')));
                if ($curpPersist === '') {
                    throw new \InvalidArgumentException('CURP vacío en orquestador (f1) antes de persistir.');
                }

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
            PuiLogger::info($requestId, 'fase1_fin', [
                'id' => $id,
                'resultado' => $desdePadron ? 'notificacion_fase1_procesada' : 'notificacion_fase1_procesada_sin_padron',
            ]);
        } catch (\Throwable $e) {
            PuiLogger::warning($requestId, 'fase1_error', [
                'id' => $id,
                'msg' => $e->getMessage(),
                'class' => get_class($e),
            ]);
        }

        try {
            // —— Fase 2: solo si existe fecha_desaparicion válida (histórico hasta hoy, máx. 12 años).
            $fechaDesaparicion = isset($body['fecha_desaparicion']) ? trim((string) $body['fecha_desaparicion']) : '';
            if ($fechaDesaparicion === '' || !ManualValidators::fechaIso8601($fechaDesaparicion)) {
                PuiLogger::info($requestId, 'fase2_omitida', [
                    'id' => $id,
                    'motivo' => 'sin_fecha_desaparicion',
                ]);
            } else {
                $curpNorm = ManualValidators::normalizeCurp((string) ($body['curp'] ?? ''));
                $tieneCurp = $curpNorm !== '' && ManualValidators::curpOficial($curpNorm);

                if (!$tieneCurp) {
                    PuiLogger::info($requestId, 'fase2_omitida', [
                        'id' => $id,
                        'motivo' => 'sin_curp_valida',
                    ]);
                } else {
                    PuiLogger::info($requestId, 'fase2_inicio', [
                        'id' => $id,
                        'modo' => 'curp',
                        'tiene_curp_valida' => true,
                    ]);
                    $end = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $endStr = $end->format('Y-m-d');

                    $start = $end->modify('-12 years');
                    $des = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaDesaparicion, new \DateTimeZone('UTC'));
                    if ($des instanceof \DateTimeImmutable && $des > $start) {
                        $start = $des;
                    }

                    $fechaInicio = $start->format('Y-m-d');
                    $fechaFin = $endStr;

                    $filasF2 = $this->cl->buscarFase2HistoricaPorCurpExacta($curpNorm, 30, $fechaInicio, $fechaFin);

                    PuiLogger::info($requestId, 'fase2_resultados_encontrados', [
                        'id' => $id,
                        'modo' => 'curp',
                        'cantidad' => count($filasF2),
                    ]);

                    $nFase2 = 0;
                    foreach ($filasF2 as $idx => $row) {
                        PuiLogger::info($requestId, 'fase2_iterando_resultado', [
                            'id' => $id,
                            'indice' => $idx,
                            'curp_fila' => $row['CURP'] ?? null,
                            'fecha_evento' => $row['FECHA_EVENTO'] ?? null,
                        ]);
                        $tipoEv = trim((string) ($row['TIPO_EVENTO'] ?? ''));
                        $tipoFinal = $tipoEv !== '' ? $tipoEv : PuiReporteService::TIPO_F2;
                        $fechaEv = trim((string) ($row['FECHA_EVENTO'] ?? ''));
                        $fechaFinal = $fechaEv !== '' ? $fechaEv : $endStr;
                        PuiLogger::info($requestId, 'notificar_coincidencia_intento', [
                            'id' => $id,
                            'fase_busqueda' => '2',
                            'indice' => $idx,
                            'tipo_evento' => $tipoFinal,
                        ]);
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
                        $nFase2++;
                    }
                    PuiLogger::info($requestId, 'fase2_fin', [
                        'id' => $id,
                        'modo' => 'curp',
                        'notificaciones_fase2' => $nFase2,
                        'ventana_desde' => $fechaInicio,
                        'ventana_hasta' => $fechaFin,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            PuiLogger::warning($requestId, 'fase2_error', [
                'id' => $id,
                'msg' => $e->getMessage(),
                'class' => get_class($e),
            ]);
        }

        // —— §7.3 Siempre después de intentar fase 1 y 2 (con o sin coincidencias).
        $this->ejecutarBusquedaFinalizadaObligatoria($requestId, $id, $institucionId, $outbound);
    }

    /**
     * POST /busqueda-finalizada: solo id + institucion_id. No debe impedir cierre del flujo salvo modo abort explícito.
     */
    private function ejecutarBusquedaFinalizadaObligatoria(
        string $requestId,
        string $id,
        string $institucionId,
        PuiOutboundClientInterface $outbound
    ): void {
        try {
            $bf = PuiManualPayloadValidator::normalizarBusquedaFinalizada([
                'id' => $id,
                'institucion_id' => $institucionId,
            ]);
            $verr = PuiManualPayloadValidator::busquedaFinalizada($bf);
            if ($verr !== []) {
                PuiLogger::warning($requestId, 'busqueda_finalizada_payload_invalido', ['id' => $id, 'err' => $verr]);

                return;
            }
            PuiLogger::info($requestId, 'outbound_busqueda_finalizada_enviando', [
                'id' => $bf['id'],
                'institucion_id' => $bf['institucion_id'],
                'base_url_configurada' => (bool) trim((string) PuiConfig::get('PUI_OUTBOUND_BASE_URL', '')),
            ]);
            $rbf = $outbound->busquedaFinalizada($bf);
            $http = (int) ($rbf['http_status'] ?? 0);
            PuiLogger::info($requestId, 'busqueda_finalizada_enviada', [
                'id' => $bf['id'],
                'institucion_id' => $bf['institucion_id'],
                'http_status' => $http,
            ]);
            PuiLogger::info($requestId, 'outbound_busqueda_finalizada_respuesta', [
                'http_status' => $http,
                'saliente_jwt' => PuiOutboundBearerResolver::mustUseJwtLogin(),
            ]);
            $this->registrarAuditoriaSaliente($requestId, [
                'evento' => 'busqueda_finalizada',
                'tipo_evento' => 'busqueda_finalizada',
                'reporte_id' => $id,
                'http_status' => $rbf['http_status'],
                'requestId' => $requestId,
                'endpoint' => 'busqueda-finalizada',
                'payload_json' => json_encode($bf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $abort = PuiConfig::get('PUI_ABORT_ON_NOTIFY_FAIL', '0');
            $abortOn = $abort === '1' || $abort === 1 || $abort === true;

            if ($http === 504) {
                if ($abortOn) {
                    throw new PuiOutboundTimeoutException('busqueda-finalizada timeout');
                }
                PuiLogger::warning($requestId, 'outbound_busqueda_finalizada_timeout_continua', [
                    'endpoint' => 'busqueda-finalizada',
                ]);

                return;
            }
            if ($http >= 200 && $http < 300) {
                try {
                    $this->reportesActivos->marcarFechaFinFase2($id);
                } catch (\Throwable $e) {
                    PuiLogger::warning($requestId, 'marcar_fecha_fin_fase2_error', ['msg' => $e->getMessage(), 'id' => $id]);
                }
            }
            if ($http < 200 || $http >= 300) {
                if ($abortOn) {
                    throw new \RuntimeException('busqueda-finalizada HTTP ' . $http);
                }
                $ctx = [
                    'http_status' => $http,
                    'endpoint' => 'busqueda-finalizada',
                ];
                if ($http === 401) {
                    $ctx['ayuda'] = '401: el simulador espera el JWT devuelto por POST …/login (clave PUI_OUTBOUND_LOGIN_CLAVE), no la clave como Bearer. Revise PUI_OUTBOUND_AUTH_MODE=login y reinicie PHP; si usaba PUI_OUTBOUND_TOKEN=clave, defina también PUI_OUTBOUND_LOGIN_CLAVE con el mismo valor.';
                }
                PuiLogger::warning($requestId, 'outbound_busqueda_finalizada_no_2xx_continua', $ctx);
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            PuiLogger::warning($requestId, 'busqueda_finalizada_error', ['id' => $id, 'msg' => $e->getMessage()]);
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

        $curpReporte = ManualValidators::normalizeCurp((string) ($reporte['CURP'] ?? $reporte['curp'] ?? ''));
        if ($curpReporte === '' || !ManualValidators::curpOficial($curpReporte)) {
            PuiLogger::warning($requestId, 'fase3_omitida_sin_curp_valida', ['id_reporte' => $idReporte]);

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
            $curpReporte,
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
                    'curp' => $curpReporte,
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

        $nombre = trim((string) ($reporte['CRITERIO_NOMBRE'] ?? $reporte['criterio_nombre'] ?? ''));
        $pa = trim((string) ($reporte['PRIMER_APELLIDO'] ?? $reporte['primer_apellido'] ?? ''));
        $sa = trim((string) ($reporte['SEGUNDO_APELLIDO'] ?? $reporte['segundo_apellido'] ?? ''));
        $rfcCriterio = trim((string) ($reporte['RFC_CRITERIO'] ?? $reporte['rfc_criterio'] ?? ''));

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
     * Un POST por coincidencia. Validación u HTTP no abortan el flujo salvo PUI_ABORT_ON_NOTIFY_FAIL.
     *
     * @param array<string,mixed> $payload
     * @return bool true si HTTP 2xx
     */
    private function enviarNotificacionValidada(
        string $requestId,
        string $reporteId,
        string $institucionId,
        PuiOutboundClientInterface $outbound,
        array $payload
    ): bool {
        $verr = PuiManualPayloadValidator::notificarCoincidencia($payload);
        if ($verr !== []) {
            PuiLogger::warning($requestId, 'notificar_coincidencia_payload_invalido', [
                'reporte_id' => $reporteId,
                'fase_busqueda' => (string) ($payload['fase_busqueda'] ?? ''),
                'err' => $verr,
            ]);

            return false;
        }

        try {
            $r = $outbound->notificarCoincidencia($payload);
        } catch (\Throwable $e) {
            PuiLogger::warning($requestId, 'outbound_notificar_coincidencia_excepcion', [
                'reporte_id' => $reporteId,
                'class' => get_class($e),
                'msg' => $e->getMessage(),
            ]);

            return false;
        }
        $code = (int) ($r['http_status'] ?? 0);
        $abort = PuiConfig::get('PUI_ABORT_ON_NOTIFY_FAIL', '0');
        $abortOn = $abort === '1' || $abort === 1 || $abort === true;

        if ($code === 504) {
            if ($abortOn) {
                throw new PuiOutboundTimeoutException('notificar-coincidencia timeout');
            }
            PuiLogger::warning($requestId, 'outbound_notificar_coincidencia_timeout_continua', [
                'fase_busqueda' => (string) ($payload['fase_busqueda'] ?? ''),
            ]);

            return false;
        }
        if ($code >= 200 && $code < 300) {
            PuiLogger::info($requestId, 'notificar_coincidencia_enviado', [
                'reporte_id' => $reporteId,
                'institucion_id' => $institucionId,
                'fase_busqueda' => (string) ($payload['fase_busqueda'] ?? ''),
                'http_status' => $code,
            ]);
            $this->persistirCoincidenciaTrasNotificacionExitosa(
                $requestId,
                $reporteId,
                $institucionId,
                $payload,
                $r
            );

            return true;
        }
        if ($abortOn) {
            throw new \RuntimeException('notificar-coincidencia HTTP ' . $code);
        }
        PuiLogger::warning($requestId, 'outbound_notificar_coincidencia_no_2xx_continua', [
            'http_status' => $code,
            'fase_busqueda' => (string) ($payload['fase_busqueda'] ?? ''),
        ]);

        return false;
    }

    /**
     * Tras HTTP 2xx en §7.2: INSERT en PUI_COINCIDENCIAS + incremento NUM_COINCIDENCIAS en PUI_REPORTES_ACTIVOS.
     *
     * @param array<string,mixed> $payload Cuerpo enviado a notificar-coincidencia
     * @param array<string,mixed> $respuestaSaliente Respuesta del cliente HTTP (p. ej. http_status)
     */
    private function persistirCoincidenciaTrasNotificacionExitosa(
        string $requestId,
        string $reporteId,
        string $institucionId,
        array $payload,
        array $respuestaSaliente
    ): void {
        $curp = ManualValidators::normalizeCurp((string) ($payload['curp'] ?? ''));
        $linea = [
            'evento' => 'notificar_coincidencia',
            'reporte_id' => $reporteId,
            'institucion_id' => $institucionId,
            'fase_busqueda' => (string) ($payload['fase_busqueda'] ?? ''),
            'tipo_evento' => $payload['tipo_evento'] ?? null,
            'http_status' => $respuestaSaliente['http_status'] ?? null,
            'requestId' => $requestId,
            'endpoint' => 'notificar-coincidencia',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'curp' => $curp,
        ];
        try {
            $this->coincidencias->registrarCoincidencia($linea);
            $this->reportesActivos->incrementarNumCoincidencias($reporteId);
            $total = $this->coincidencias->contarNotificacionesPorReporte($reporteId);
            PuiLogger::info($requestId, 'coincidencia_guardada', [
                'reporte_id' => $reporteId,
                'fase' => (string) ($payload['fase_busqueda'] ?? ''),
                'curp' => $curp !== '' ? $curp : null,
            ]);
            PuiLogger::info($requestId, 'coincidencias_total_reporte', [
                'reporte_id' => $reporteId,
                'total' => $total,
            ]);
        } catch (\Throwable $e) {
            PuiLogger::error($requestId, 'COINCIDENCIA_NO_PERSISTIDA', [
                'reporte_id' => $reporteId,
                'msg' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            PuiLogger::warning($requestId, 'pui_coincidencias_auditoria_no_guardada', [
                'msg' => $e->getMessage(),
                'endpoint' => 'notificar-coincidencia',
            ]);
        }
    }

    /**
     * Auditoría en PUI_COINCIDENCIAS para §7.3 u eventos internos (no incrementa contador de §7.2).
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
     * Fila con alias de {@see CultivaClienteRepository} / {@see NotificarCoincidenciaPayloadFactory}
     * cuando no existe registro en CLIENTE (§8.2 nota 5).
     *
     * @param array<string,mixed> $body Cuerpo validado de activar-reporte
     * @return array<string,mixed>
     */
    private function construirFilaClienteDesdeCuerpoActivar(array $body): array
    {
        $curp = ManualValidators::normalizeCurp((string) ($body['curp'] ?? ''));

        return [
            'CODIGO_CLIENTE' => '',
            'NOMBRE1' => trim((string) ($body['nombre'] ?? '')),
            'NOMBRE2' => '',
            'PRIMAPE' => trim((string) ($body['primer_apellido'] ?? '')),
            'SEGAPE' => trim((string) ($body['segundo_apellido'] ?? '')),
            'NOMBRE_COMPLETO' => '',
            'RFC' => strtoupper(trim((string) ($body['rfc_criterio'] ?? ''))),
            'CURP' => $curp,
            'FECHA_NACIMIENTO' => isset($body['fecha_nacimiento']) ? trim((string) $body['fecha_nacimiento']) : '',
            'SEXO' => strtoupper(trim((string) ($body['sexo_asignado'] ?? 'X'))),
            'CALLE' => trim((string) ($body['calle'] ?? $body['direccion'] ?? '')),
            'NUMERO' => trim((string) ($body['numero'] ?? '')),
            'CODIGO_POSTAL' => trim((string) ($body['codigo_postal'] ?? '')),
            'CDGPAI' => trim((string) ($body['colonia'] ?? '')),
            'CDGMU' => trim((string) ($body['municipio_o_alcaldia'] ?? '')),
            'ESTADO_NOMBRE' => trim((string) ($body['entidad_federativa'] ?? '')),
        ];
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
