<?php

namespace App\Pui\Repository;

use App\Pui\Http\PuiLogger;
use App\Pui\Validation\ManualValidators;
use Core\Database;

class PuiCoincidenciaOracleRepository
{
    /**
     * @param array<string,mixed> $linea
     */
    public function registrarCoincidencia(array $linea): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $payload = $linea['payload_json'] ?? null;
        if (!is_string($payload)) {
            $payload = json_encode($linea, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                $payload = '{}';
            }
        }

        $curpCol = $this->resolverCurpParaInsert($linea, $payload);

        $eventoRowid = null;
        if (array_key_exists('evento_rowid', $linea) && $linea['evento_rowid'] !== null && trim((string) $linea['evento_rowid']) !== '') {
            $eventoRowid = strtoupper(trim((string) $linea['evento_rowid']));
        }

        $sql = <<<SQL
            INSERT INTO PUI_COINCIDENCIAS (
                ID_REPORTE,
                FASE_BUSQUEDA,
                TIPO_EVENTO,
                PAYLOAD_JSON,
                HTTP_STATUS,
                REQUEST_ID,
                ENDPOINT,
                FECHA_EVENTO,
                CURP,
                EVENTO_ROWID
            ) VALUES (
                :id_reporte,
                :fase_busqueda,
                :tipo_evento,
                TO_CLOB(:payload_json),
                :http_status,
                :request_id,
                :endpoint,
                SYSTIMESTAMP,
                :curp,
                :evento_rowid
            )
        SQL;

        $tipoEvento = null;
        if (array_key_exists('tipo_evento', $linea) && $linea['tipo_evento'] !== null) {
            $t = trim((string) $linea['tipo_evento']);
            if ($t !== '') {
                $tipoEvento = $t;
            }
        }
        if ($tipoEvento === null && array_key_exists('evento', $linea) && $linea['evento'] !== null) {
            $t = trim((string) $linea['evento']);
            if ($t !== '') {
                $tipoEvento = $t;
            }
        }

        $faseBusqueda = null;
        if (array_key_exists('fase_busqueda', $linea) && $linea['fase_busqueda'] !== null && trim((string) $linea['fase_busqueda']) !== '') {
            $faseBusqueda = trim((string) $linea['fase_busqueda']);
        }

        $httpStatus = null;
        if (array_key_exists('http_status', $linea) && $linea['http_status'] !== null && $linea['http_status'] !== '') {
            $httpStatus = (int) $linea['http_status'];
        }

        $params = [
            'id_reporte' => (string) ($linea['reporte_id'] ?? $linea['id_reporte'] ?? ''),
            'fase_busqueda' => $faseBusqueda,
            'tipo_evento' => $tipoEvento,
            'payload_json' => $payload,
            'http_status' => $httpStatus,
            'request_id' => isset($linea['requestId']) ? (string) $linea['requestId'] : null,
            'endpoint' => isset($linea['endpoint']) ? (string) $linea['endpoint'] : null,
            'curp' => $curpCol,
            'evento_rowid' => $eventoRowid,
        ];

        $rid = PuiLogger::requestContextId();
        PuiLogger::info($rid, 'coincidencia_insert_db', [
            'reporte_id' => $params['id_reporte'],
            'fase_busqueda' => $faseBusqueda,
            'endpoint' => $params['endpoint'],
            'http_status' => $httpStatus,
        ]);

        $ok = $db->insert($sql, $params);
        if (!$ok) {
            throw new \RuntimeException('No se pudo registrar coincidencia en Oracle.');
        }
    }

    /**
     * Coincidencias §7.2 persistidas (mismo criterio que incremento de NUM_COINCIDENCIAS).
     */
    public function contarNotificacionesPorReporte(string $idReporte): int
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return 0;
        }
        if ($idReporte === '') {
            return 0;
        }
        $sql = <<<'SQL'
            SELECT COUNT(1) AS CNT
            FROM PUI_COINCIDENCIAS
            WHERE ID_REPORTE = :id_reporte
              AND ENDPOINT = 'notificar-coincidencia'
              AND HTTP_STATUS >= 200
              AND HTTP_STATUS < 300
        SQL;
        $row = $db->queryOne($sql, ['id_reporte' => $idReporte]);

        return (int) (($row['CNT'] ?? $row['cnt'] ?? 0));
    }

    /**
     * CURP en columna dedicada; si falta, intenta JSON §7.2.
     */
    private function resolverCurpParaInsert(array $linea, string $payloadJson): ?string
    {
        $raw = trim((string) ($linea['curp'] ?? ''));
        if ($raw !== '') {
            $n = ManualValidators::normalizeCurp($raw);

            return $n !== '' ? $n : null;
        }
        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            return null;
        }
        $n = ManualValidators::normalizeCurp((string) ($decoded['curp'] ?? ''));

        return $n !== '' ? $n : null;
    }

    public function existeCoincidenciaFasePorCurp(string $idReporte, string $faseBusqueda, string $curp): bool
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return false;
        }

        $curp = strtoupper(trim($curp));
        if ($curp === '') {
            return false;
        }

        // Dedupe por presencia de '"curp":"<curp>"' dentro del payload JSON almacenado.
        // Fase 3: preferir {@see existeNotificacionFase3PorEventoRowid} (misma CURP en muchos EVENTO).
        $needle = '"curp":"' . $curp . '"';

        $sql = <<<SQL
            SELECT COUNT(1) AS CNT
            FROM PUI_COINCIDENCIAS
            WHERE ID_REPORTE = :id_reporte
              AND FASE_BUSQUEDA = :fase
              AND DBMS_LOB.INSTR(PAYLOAD_JSON, :needle) > 0
        SQL;

        $row = $db->queryOne($sql, [
            'id_reporte' => $idReporte,
            'fase' => $faseBusqueda,
            'needle' => $needle,
        ]);

        $cnt = (int) (($row['CNT'] ?? $row['cnt'] ?? 0));
        return $cnt > 0;
    }

    /**
     * Fase 3: una notificación por fila EVENTO (ROWID), no por CURP.
     */
    public function existeNotificacionFase3PorEventoRowid(string $idReporte, string $eventoRowid): bool
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return false;
        }
        $rid = strtoupper(trim($eventoRowid));
        if ($rid === '') {
            return false;
        }
        $sql = <<<'SQL'
            SELECT COUNT(1) AS CNT
            FROM PUI_COINCIDENCIAS
            WHERE ID_REPORTE = :id_reporte
              AND FASE_BUSQUEDA = '3'
              AND EVENTO_ROWID = :evento_rowid
              AND ENDPOINT = 'notificar-coincidencia'
              AND HTTP_STATUS >= 200
              AND HTTP_STATUS < 300
        SQL;
        $row = $db->queryOne($sql, [
            'id_reporte' => $idReporte,
            'evento_rowid' => $rid,
        ]);

        return (int) (($row['CNT'] ?? $row['cnt'] ?? 0)) > 0;
    }

    /**
     * Mismo EVENTO (ROWID) no debe contarse dos veces: puede notificarse en fase 2 (histórico) y volver a
     * aparecer en fase 3 (continua). Si ya hubo notificación §7.2 exitosa con ese ROWID, no repetir.
     */
    public function existeNotificacionExitosaPorEventoRowid(string $idReporte, string $eventoRowid): bool
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return false;
        }
        $rid = strtoupper(trim($eventoRowid));
        if ($rid === '') {
            return false;
        }
        $sql = <<<'SQL'
            SELECT COUNT(1) AS CNT
            FROM PUI_COINCIDENCIAS
            WHERE ID_REPORTE = :id_reporte
              AND EVENTO_ROWID = :evento_rowid
              AND ENDPOINT = 'notificar-coincidencia'
              AND HTTP_STATUS >= 200
              AND HTTP_STATUS < 300
        SQL;
        $row = $db->queryOne($sql, [
            'id_reporte' => $idReporte,
            'evento_rowid' => $rid,
        ]);

        return (int) (($row['CNT'] ?? $row['cnt'] ?? 0)) > 0;
    }
}
