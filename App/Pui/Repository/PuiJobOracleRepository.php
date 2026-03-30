<?php

namespace App\Pui\Repository;

use Core\Database;

class PuiJobOracleRepository
{
    public const JOB_FASE3_SCAN = 'PUI_FASE3_SCAN';

    public function programarFase3(string $idReporte, bool $esPrueba, int $intervalMinutes = 15): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            throw new \RuntimeException('No hay conexión a Oracle para PUI_JOBS.');
        }

        $payload = json_encode([
            'id_reporte' => $idReporte,
            'es_prueba' => $esPrueba,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{}';
        }

        $sql = <<<SQL
            MERGE INTO PUI_JOBS T
            USING (
                SELECT
                    :job_type AS JOB_TYPE,
                    :id_reporte AS ID_REPORTE,
                    :payload_json AS PAYLOAD_JSON,
                    :interval_minutes AS INTERVAL_MINUTES
                FROM DUAL
            ) S
            ON (T.JOB_TYPE = S.JOB_TYPE AND T.ID_REPORTE = S.ID_REPORTE)
            WHEN MATCHED THEN
                UPDATE SET
                    T.STATUS = 'PENDING',
                    T.RUN_AT = SYSTIMESTAMP,
                    T.PAYLOAD_JSON = S.PAYLOAD_JSON,
                    T.INTERVAL_MINUTES = S.INTERVAL_MINUTES,
                    T.LOCKED_BY = NULL,
                    T.LOCKED_AT = NULL,
                    T.LAST_ERROR = NULL,
                    T.UPDATED_AT = SYSTIMESTAMP
            WHEN NOT MATCHED THEN
                INSERT (JOB_TYPE, ID_REPORTE, STATUS, RUN_AT, ATTEMPTS, MAX_ATTEMPTS, INTERVAL_MINUTES, PAYLOAD_JSON, UPDATED_AT)
                VALUES (S.JOB_TYPE, S.ID_REPORTE, 'PENDING', SYSTIMESTAMP, 0, 10, S.INTERVAL_MINUTES, S.PAYLOAD_JSON, SYSTIMESTAMP)
        SQL;

        $ok = $db->insert($sql, [
            'job_type' => self::JOB_FASE3_SCAN,
            'id_reporte' => $idReporte,
            'payload_json' => $payload,
            'interval_minutes' => max(1, $intervalMinutes),
        ]);
        if (!$ok) {
            throw new \RuntimeException('No se pudo programar job de fase 3.');
        }
    }

    public function cancelarJobsReporte(string $idReporte): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            throw new \RuntimeException('No hay conexión a Oracle para cancelar jobs PUI.');
        }

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET STATUS = 'CANCELLED',
                UPDATED_AT = SYSTIMESTAMP,
                LOCKED_BY = NULL,
                LOCKED_AT = NULL
            WHERE ID_REPORTE = :id_reporte
              AND STATUS IN ('PENDING', 'RUNNING', 'RETRY')
        SQL;
        $db->insert($sql, ['id_reporte' => $idReporte]);
    }

    public function cancelarJob(int $idJob): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return;
        }

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET STATUS = 'CANCELLED',
                UPDATED_AT = SYSTIMESTAMP,
                LOCKED_BY = NULL,
                LOCKED_AT = NULL
            WHERE ID = :id
        SQL;
        $db->insert($sql, ['id' => $idJob]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function obtenerJobsPendientes(int $limit = 20): array
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return [];
        }

        $sql = <<<SQL
            SELECT * FROM (
                SELECT
                    ID,
                    JOB_TYPE,
                    ID_REPORTE,
                    STATUS,
                    TO_CHAR(RUN_AT, 'YYYY-MM-DD"T"HH24:MI:SS') AS RUN_AT,
                    ATTEMPTS,
                    MAX_ATTEMPTS,
                    INTERVAL_MINUTES,
                    PAYLOAD_JSON
                FROM PUI_JOBS
                WHERE STATUS IN ('PENDING','RETRY')
                  AND RUN_AT <= SYSTIMESTAMP
                ORDER BY RUN_AT, ID
            ) WHERE ROWNUM <= :limite
        SQL;
        $rows = $db->queryAll($sql, ['limite' => max(1, $limit)]);
        return is_array($rows) ? $rows : [];
    }

    public function tomarJob(int $id, string $worker): bool
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return false;
        }

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET STATUS = 'RUNNING',
                LOCKED_BY = :worker,
                LOCKED_AT = SYSTIMESTAMP,
                UPDATED_AT = SYSTIMESTAMP
            WHERE ID = :id
              AND STATUS IN ('PENDING','RETRY')
        SQL;
        return $db->insert($sql, ['id' => $id, 'worker' => $worker]);
    }

    public function marcarProcesado(int $id, int $intervalMinutes = 15): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            throw new \RuntimeException('No hay conexión a Oracle para actualizar job PUI.');
        }

        $intervalMinutes = max(1, $intervalMinutes);

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET STATUS = 'PENDING',
                RUN_AT = SYSTIMESTAMP + NUMTODSINTERVAL(:interval_minutes, 'MINUTE'),
                ATTEMPTS = 0,
                LOCKED_BY = NULL,
                LOCKED_AT = NULL,
                LAST_ERROR = NULL,
                UPDATED_AT = SYSTIMESTAMP
            WHERE ID = :id
        SQL;
        $db->insert($sql, [
            'id' => $id,
            'interval_minutes' => $intervalMinutes,
        ]);
    }

    /**
     * Libera un job que sigue en RUNNING (p. ej. fallo silencioso o excepción tras tomarJob).
     * No modifica filas ya actualizadas a PENDING/RETRY/FAILED/CANCELLED.
     */
    public function liberarLock(int $id, int $intervalMinutes = 15): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return;
        }

        $intervalMinutes = max(1, $intervalMinutes);

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET
                LOCKED_AT = NULL,
                LOCKED_BY = NULL,
                STATUS = 'PENDING',
                RUN_AT = SYSTIMESTAMP + NUMTODSINTERVAL(:interval_minutes, 'MINUTE'),
                UPDATED_AT = SYSTIMESTAMP
            WHERE ID = :id
              AND STATUS = 'RUNNING'
        SQL;
        $db->insert($sql, [
            'id' => $id,
            'interval_minutes' => $intervalMinutes,
        ]);
    }

    public function marcarError(int $id, string $error): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            throw new \RuntimeException('No hay conexión a Oracle para marcar error de job PUI.');
        }

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET ATTEMPTS = ATTEMPTS + 1,
                STATUS = CASE WHEN ATTEMPTS + 1 >= MAX_ATTEMPTS THEN 'FAILED' ELSE 'RETRY' END,
                RUN_AT = SYSTIMESTAMP + NUMTODSINTERVAL(5, 'MINUTE'),
                LAST_ERROR = :last_error,
                LOCKED_BY = NULL,
                LOCKED_AT = NULL,
                UPDATED_AT = SYSTIMESTAMP
            WHERE ID = :id
        SQL;
        $db->insert($sql, [
            'id' => $id,
            'last_error' => substr($error, 0, 3900),
        ]);
    }

    public function requeueFailed(): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return;
        }
        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET STATUS = 'RETRY',
                RUN_AT = SYSTIMESTAMP,
                UPDATED_AT = SYSTIMESTAMP
            WHERE STATUS = 'FAILED'
        SQL;
        $db->insert($sql, []);
    }
}
