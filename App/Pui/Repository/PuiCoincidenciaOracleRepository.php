<?php

namespace App\Pui\Repository;

use App\Pui\Http\PuiLogger;
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

        $sql = <<<SQL
            INSERT INTO PUI_COINCIDENCIAS (
                ID_REPORTE,
                FASE_BUSQUEDA,
                TIPO_EVENTO,
                PAYLOAD_JSON,
                HTTP_STATUS,
                REQUEST_ID,
                ENDPOINT,
                FECHA_EVENTO
            ) VALUES (
                :id_reporte,
                :fase_busqueda,
                :tipo_evento,
                TO_CLOB(:payload_json),
                :http_status,
                :request_id,
                :endpoint,
                SYSTIMESTAMP
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

        $ok = $db->insert($sql, [
            'id_reporte' => (string) ($linea['reporte_id'] ?? $linea['id_reporte'] ?? ''),
            'fase_busqueda' => $faseBusqueda,
            'tipo_evento' => $tipoEvento,
            'payload_json' => $payload,
            'http_status' => $httpStatus,
            'request_id' => isset($linea['requestId']) ? (string) $linea['requestId'] : null,
            'endpoint' => isset($linea['endpoint']) ? (string) $linea['endpoint'] : null,
        ]);
        if (!$ok) {
            throw new \RuntimeException('No se pudo registrar coincidencia en Oracle.');
        }
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
        // Esto evita reprocesar la misma coincidencia en búsqueda continua (fase 3).
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

        $cnt = (int) (($row['CNT'] ?? 0));
        return $cnt > 0;
    }
}
