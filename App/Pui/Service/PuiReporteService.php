<?php

namespace App\Pui\Service;

use App\Pui\Config\PuiConfig;
use App\Pui\Exception\DatabaseUnavailableException;
use App\Pui\Http\PuiLogger;
use App\Pui\Integration\HttpPuiOutboundClient;
use App\Pui\Integration\PuiOutboundBearerResolver;
use App\Pui\Integration\PuiOutboundFactory;
use App\Pui\Integration\PuiOutboundTimeoutException;
use App\Pui\Repository\PuiCoincidenciaMemoryRepository;
use App\Pui\Repository\PuiCoincidenciaOracleRepository;
use App\Pui\Repository\PuiJobMemoryRepository;
use App\Pui\Repository\PuiJobOracleRepository;
use App\Pui\Repository\PuiReporteActivoMemoryRepository;
use App\Pui\Repository\PuiReporteActivoOracleRepository;
use App\Pui\Validation\ManualValidators;
use App\Pui\Validation\PuiManualPayloadValidator;

/**
 * Orquestación según Manual Técnico PUI v1.0 (§6 flujo, §7.2–7.3 salientes, §8.2–8.4 receptores).
 */
class PuiReporteService
{
    // Manual: tipo_evento permite sólo caracteres restringidos (incluye '-' pero no '—').
    public const TIPO_F1 = 'Búsqueda fase 1 - datos básicos';
    public const TIPO_F2 = 'Búsqueda fase 2 - histórica';
    public const TIPO_F3 = 'Búsqueda fase 3 - continua';

    private PuiReporteActivoOracleRepository|PuiReporteActivoMemoryRepository $estado;
    private PuiCoincidenciaOracleRepository|PuiCoincidenciaMemoryRepository $coincidencias;
    private PuiSearchOrchestratorService $orchestrator;
    private PuiJobOracleRepository|PuiJobMemoryRepository $jobs;

    public function __construct(
        ?PuiReporteActivoOracleRepository $estado = null,
        ?PuiCoincidenciaOracleRepository $coincidencias = null,
        ?PuiSearchOrchestratorService $orchestrator = null,
        ?PuiJobOracleRepository $jobs = null
    ) {
        if (PuiConfig::isSimulationMode()) {
            $memEstado = new PuiReporteActivoMemoryRepository();
            $memCoin = new PuiCoincidenciaMemoryRepository();
            $memJobs = new PuiJobMemoryRepository();
            $this->estado = $memEstado;
            $this->coincidencias = $memCoin;
            $this->jobs = $memJobs;
            $this->orchestrator = $orchestrator ?? new PuiSearchOrchestratorService(null, $memCoin, $memEstado);
            return;
        }

        $this->estado = $estado ?? new PuiReporteActivoOracleRepository();
        $this->coincidencias = $coincidencias ?? new PuiCoincidenciaOracleRepository();
        $this->orchestrator = $orchestrator ?? new PuiSearchOrchestratorService(null, $this->coincidencias, $this->estado);
        $this->jobs = $jobs ?? new PuiJobOracleRepository();
    }

    /**
     * §8.2 / §8.3 — Activar reporte (y prueba). Respuesta 200: solo { "message": "..." } (manual).
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public function activarReporte(string $requestId, array $body, bool $esPrueba): array
    {
        if (!empty($body['curp'])) {
            $body['curp'] = ManualValidators::normalizeCurp((string) $body['curp']);
        }

        $err = PuiManualPayloadValidator::activarReporte($body);
        if ($err !== []) {
            PuiLogger::warning($requestId, 'activar_validacion', ['err' => $err]);
            return $this->err($requestId, 400, 'PUI-VAL-400', implode('; ', $err));
        }

        if ($esPrueba && !PuiConfig::isSimulationMode()) {
            try {
                (new HttpPuiOutboundClient())->verificarConectividadSaliente();
            } catch (\RuntimeException $e) {
                PuiLogger::warning($requestId, 'activar_prueba_ping_fail', ['msg' => $e->getMessage()]);
                return $this->err($requestId, 502, 'PUI-EXT-502', 'No se pudo verificar conectividad con la PUI.');
            }
        }

        $id = trim((string) $body['id']);
        try {
            return $this->ejecutarPipelineActivacion($requestId, $body, $esPrueba, $id);
        } catch (PuiOutboundTimeoutException $e) {
            $this->marcarEstado($id, 'ERROR');
            return $this->err($requestId, 504, 'PUI-EXT-504', 'Tiempo de espera agotado al contactar la PUI.');
        } catch (\InvalidArgumentException $e) {
            $this->marcarEstado($id, 'ERROR');
            return $this->err($requestId, 400, 'PUI-VAL-400', $e->getMessage());
        } catch (\PDOException $e) {
            PuiLogger::error($requestId, 'activar_pdo', ['msg' => $e->getMessage()]);
            try {
                $this->marcarEstado($id, 'ERROR');
            } catch (\Throwable $ignored) {
            }
            return $this->err($requestId, 503, 'PUI-DB-503', 'Servicio de datos no disponible.');
        } catch (DatabaseUnavailableException $e) {
            PuiLogger::error($requestId, 'activar_db_unavailable', ['msg' => $e->getMessage()]);
            try {
                $this->marcarEstado($id, 'ERROR');
            } catch (\Throwable $ignored) {
            }
            return $this->err($requestId, 503, 'PUI-DB-503', 'Servicio de datos no disponible.');
        } catch (\RuntimeException $e) {
            $this->marcarEstado($id, 'ERROR');
            return $this->err($requestId, 502, 'PUI-EXT-502', 'Fallo al notificar a la PUI.', $e->getMessage());
        } catch (\Throwable $e) {
            PuiLogger::error($requestId, 'activar_exception', ['class' => get_class($e), 'msg' => $e->getMessage()]);
            try {
                $this->marcarEstado($id, 'ERROR');
            } catch (\Throwable $ignored) {
            }
            return $this->err($requestId, 502, 'PUI-EXT-502', 'No se pudo completar la activación del reporte.', $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function ejecutarPipelineActivacion(string $requestId, array $body, bool $esPrueba, string $id): array
    {
        $curp = strtoupper(trim((string) ($body['curp'] ?? '')));
        if ($curp === '') {
            throw new \InvalidArgumentException('CURP vacío en activar-reporte');
        }

        // Normalizamos nuevamente para asegurar consistencia entre validación y persistencia.
        $body['curp'] = $curp;

        $institucionId = $this->resolverInstitucionIdParaActivar($body);
        if ($institucionId === '' || !ManualValidators::institucionId($institucionId)) {
            return $this->err($requestId, 500, 'PUI-CFG-500', 'Configure INSTITUCION_RFC en pui.ini (4–13 caracteres) o envíe institucion_id en el cuerpo (mismo valor que en login hacia la PUI / simulador).');
        }

        $this->estado->guardar($id, [
            'id' => $id,
            'curp' => $curp,
            'institucion_id' => $institucionId,
            'nombre' => (string) ($body['nombre'] ?? ''),
            'primer_apellido' => (string) ($body['primer_apellido'] ?? ''),
            'segundo_apellido' => (string) ($body['segundo_apellido'] ?? ''),
            'rfc_criterio' => (string) ($body['rfc_criterio'] ?? ''),
            'estado' => 'PROCESANDO',
            'es_prueba' => $esPrueba,
            'activo' => 1,
        ]);

        try {
            $this->orchestrator->ejecutarFases1y2($requestId, $body, $id, $esPrueba, $institucionId);
        } catch (\Throwable $e) {
            if (!$this->shouldSwallowOrchestratorFailure($e)) {
                throw $e;
            }
            PuiLogger::warning($requestId, 'activar_orquestador_continua_tras_fallo_saliente', [
                'class' => get_class($e),
                'msg' => $e->getMessage(),
            ]);
        }

        try {
            $this->jobs->programarFase3($id, $esPrueba, 15, $requestId);
        } catch (\Throwable $e) {
            // §7.3 ya se envió; el job fase 3 es complementario — no debe revertir la activación.
            PuiLogger::warning($requestId, 'programar_fase3_error', ['id' => $id, 'msg' => $e->getMessage()]);
        }
        try {
            $this->kickFase3RunnerDaemon();
        } catch (\Throwable $e) {
            PuiLogger::warning($requestId, 'kick_fase3_daemon_error', ['msg' => $e->getMessage()]);
        }

        $this->marcarEstado($id, 'ACTIVO');

        return [
            'status' => 200,
            'body' => [
                'message' => 'La solicitud de activación del reporte de búsqueda se recibió correctamente.',
            ],
        ];
    }

    /**
     * §8.4 — Solo id en cuerpo. Respuesta message según tabla del manual (pág. 57).
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public function desactivarReporte(string $requestId, array $body): array
    {
        $err = PuiManualPayloadValidator::desactivarReporte($body);
        if ($err !== []) {
            PuiLogger::warning($requestId, 'desactivar_validacion', ['err' => $err]);
            return $this->err($requestId, 400, 'PUI-VAL-400', implode('; ', $err));
        }

        $id = trim((string) $body['id']);
        $prev = $this->estado->obtener($id);
        if ($prev === null) {
            return $this->err($requestId, 404, 'PUI-NOT-FOUND', 'Reporte no encontrado.');
        }

        $this->estado->marcarInactivo($id);
        $this->jobs->cancelarJobsReporte($id);

        // Notificación saliente automática §7.3 tras cierre local (la PUI recibe el cierre de monitoreo).
        $this->notificarBusquedaFinalizadaSalienteTrasDesactivar($requestId, $id, $prev);

        return [
            'status' => 200,
            'body' => [
                // Mismo texto que §7.3 respuesta 200 (manual + mock saliente) para que la PUI reconozca la finalización.
                'message' => 'Registro de finalización de búsqueda histórica guardado correctamente.',
            ],
        ];
    }

    /**
     * Tras desactivar en BD, envía POST /busqueda-finalizada hacia la PUI (id + institucion_id).
     * Errores HTTP solo se registran; la respuesta §8.4 al cliente sigue siendo 200.
     *
     * @param array<string,mixed> $prev Registro previo de PUI_REPORTES_ACTIVOS (antes de marcarInactivo).
     */
    private function notificarBusquedaFinalizadaSalienteTrasDesactivar(string $requestId, string $idReporte, array $prev): void
    {
        try {
            $inst = strtoupper(trim((string) ($prev['institucion_id'] ?? $prev['INSTITUCION_ID'] ?? '')));
            if ($inst === '' || !ManualValidators::institucionId($inst)) {
                PuiLogger::warning($requestId, 'desactivar_busqueda_finalizada_omitida_institucion', ['id' => $idReporte]);

                return;
            }
            $bf = PuiManualPayloadValidator::normalizarBusquedaFinalizada([
                'id' => $idReporte,
                'institucion_id' => $inst,
            ]);
            $verr = PuiManualPayloadValidator::busquedaFinalizada($bf);
            if ($verr !== []) {
                PuiLogger::warning($requestId, 'desactivar_busqueda_finalizada_payload_invalido', ['err' => $verr]);

                return;
            }
            $esPrueba = !empty($prev['es_prueba'] ?? $prev['ES_PRUEBA'] ?? 0);
            PuiLogger::info($requestId, 'outbound_busqueda_finalizada_tras_desactivar', [
                'id' => $bf['id'],
                'institucion_id' => $bf['institucion_id'],
            ]);
            $outbound = PuiOutboundFactory::create($esPrueba);
            $r = $outbound->busquedaFinalizada($bf);
            PuiLogger::info($requestId, 'desactivar_busqueda_finalizada_respuesta', [
                'http_status' => (int) ($r['http_status'] ?? 0),
                'saliente_jwt' => PuiOutboundBearerResolver::mustUseJwtLogin(),
            ]);
            $this->coincidencias->registrarCoincidencia([
                'evento' => 'busqueda_finalizada_tras_desactivar',
                'tipo_evento' => 'busqueda_finalizada_tras_desactivar',
                'reporte_id' => $idReporte,
                'http_status' => $r['http_status'],
                'requestId' => $requestId,
                'endpoint' => 'busqueda-finalizada',
                'payload_json' => json_encode($bf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $code = (int) ($r['http_status'] ?? 0);
            if ($code < 200 || $code >= 300) {
                PuiLogger::warning($requestId, 'desactivar_busqueda_finalizada_no_2xx', ['http_status' => $code]);
            }
        } catch (\Throwable $e) {
            PuiLogger::warning($requestId, 'desactivar_busqueda_finalizada_error', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Mismo RFC que la PUI usa en §7.2/§7.3: por defecto INSTITUCION_RFC; si el cuerpo trae institucion_id (p. ej. simulador XAXX010101000), se usa ese.
     *
     * @param array<string,mixed> $body
     */
    private function resolverInstitucionIdParaActivar(array $body): string
    {
        if (isset($body['institucion_id']) && is_string($body['institucion_id']) && trim($body['institucion_id']) !== '') {
            return strtoupper(trim($body['institucion_id']));
        }

        return strtoupper(trim((string) PuiConfig::get('INSTITUCION_RFC', '')));
    }

    private function notifyAbortEnabled(): bool
    {
        $v = PuiConfig::get('PUI_ABORT_ON_NOTIFY_FAIL', '0');

        return $v === '1' || $v === 1 || $v === true;
    }

    /**
     * Con PUI_ABORT_ON_NOTIFY_FAIL = 0, fallos HTTP/timeout hacia la PUI externa no deben impedir
     * completar activación (reporte persistido, job fase 3, respuesta 200).
     */
    private function shouldSwallowOrchestratorFailure(\Throwable $e): bool
    {
        if ($this->notifyAbortEnabled()) {
            return false;
        }
        if ($e instanceof DatabaseUnavailableException || $e instanceof \PDOException) {
            return false;
        }
        if ($e instanceof \InvalidArgumentException) {
            return false;
        }
        if ($e instanceof PuiOutboundTimeoutException) {
            return true;
        }
        if ($e instanceof \RuntimeException) {
            $m = $e->getMessage();

            return str_contains($m, 'busqueda-finalizada HTTP') || str_contains($m, 'notificar-coincidencia HTTP');
        }

        return false;
    }

    private function marcarEstado(string $id, string $estado): void
    {
        $prev = $this->estado->obtener($id) ?? [];

        // PUI_REPORTES_ACTIVOS::obtener() devuelve columnas con llaves uppercase (CURP, INSTITUCION_ID, ...),
        // mientras que PuiReporteActivoOracleRepository::guardar() espera keys lowercase (curp, institucion_id, ...).
        // Convertimos para evitar persistir NULL (ORA-01407) por claves inexistentes.
        $curpPrev = strtoupper(trim((string) ($prev['curp'] ?? $prev['CURP'] ?? '')));
        if ($curpPrev === '') {
            throw new \InvalidArgumentException('CURP vacío al marcarEstado; estado=' . $estado);
        }

        $institucionPrev = strtoupper(trim((string) ($prev['institucion_id'] ?? $prev['INSTITUCION_ID'] ?? '')));
        $nombrePrev = trim((string) ($prev['nombre'] ?? $prev['CRITERIO_NOMBRE'] ?? ''));
        $primerPrev = trim((string) ($prev['primer_apellido'] ?? $prev['PRIMER_APELLIDO'] ?? ''));
        $segundoPrev = trim((string) ($prev['segundo_apellido'] ?? $prev['SEGUNDO_APELLIDO'] ?? ''));
        $rfcPrev = strtoupper(trim((string) ($prev['rfc_criterio'] ?? $prev['RFC_CRITERIO'] ?? '')));
        $esPruebaPrev = !empty($prev['es_prueba'] ?? $prev['ES_PRUEBA'] ?? 0);

        $registro = [
            // Keys esperadas por guardar()
            'id' => $id,
            'curp' => $curpPrev,
            'institucion_id' => $institucionPrev,
            'nombre' => $nombrePrev,
            'primer_apellido' => $primerPrev,
            'segundo_apellido' => $segundoPrev,
            'rfc_criterio' => $rfcPrev,
            'es_prueba' => $esPruebaPrev ? 1 : 0,
            'activo' => strtoupper($estado) === 'CERRADO' ? 0 : 1,
            'estado' => $estado,
        ];

        $this->estado->guardar($id, $registro);
    }

    /**
     * Inicia un runner daemon de fase 3 en segundo plano.
     * La re-ejecución continúa mientras exista en PUI_JOBS y el reporte siga activo.
     */
    private function kickFase3RunnerDaemon(): void
    {
        try {
            $backendRoot = dirname(__DIR__, 3); // .../backend
            $runnerScript = $backendRoot . '/Jobs/controllers/JobTableRunner.php';
            $lockFile = $backendRoot . '/App/storage/pui/pui_fase3_runner.lock';

            if (!is_file($runnerScript)) {
                return;
            }

            $recentSeconds = 60;
            if (is_file($lockFile)) {
                $age = time() - filemtime($lockFile);
                if ($age >= 0 && $age < $recentSeconds) {
                    return; // daemon ya está vivo (heartbeat reciente)
                }
            }

            $dir = dirname($lockFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            // Marcar como iniciado para evitar carreras entre activaciones simultáneas.
            @file_put_contents($lockFile, (string) time(), LOCK_EX);

            $php = PHP_BINARY;
            $sleepSeconds = (int) PuiConfig::get('PUI_FASE3_DAEMON_SLEEP_SECONDS', 15);

            $cmd = 'cmd /c start "" /B ' . escapeshellarg($php)
                . ' -f ' . escapeshellarg($runnerScript)
                . ' run-daemon ' . escapeshellarg((string) $sleepSeconds)
                . ' ' . escapeshellarg('20')
                . ' ' . escapeshellarg($lockFile);

            @pclose(@popen($cmd, 'r'));
        } catch (\Throwable $e) {
            // No bloquea el flujo principal; el runner puede ejecutarse por cron externo.
        }
    }

    private function err(string $requestId, int $st, string $code, string $msg, ?string $detalle = null): array
    {
        $verbose = PuiConfig::exposeErrorDetailInResponse();
        if ($st >= 500) {
            $detalle = null;
            if (!$verbose) {
                $msg = 'Error en el servicio. Intente más tarde.';
            }
        } elseif (!$verbose && $st >= 400 && $st < 500) {
            $detalle = null;
            if ($code === 'PUI-VAL-400') {
                $msg = 'Solicitud inválida.';
            }
        }
        return [
            'status' => $st,
            'body' => [
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
                'error' => ['codigo' => $code, 'mensaje' => $msg, 'detalle' => $detalle],
            ],
        ];
    }
}
