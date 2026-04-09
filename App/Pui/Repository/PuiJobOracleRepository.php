<?php

namespace App\Pui\Repository;

use App\Pui\Exception\DatabaseUnavailableException;
use App\Pui\Http\PuiLogger;
use Core\Database;

class PuiJobOracleRepository
{
    public const JOB_FASE3_SCAN = 'PUI_FASE3_SCAN';
    public const JOB_RESYNC_NOTIFICAR = 'PUI_RESYNC_NOTIFICAR';
    public const JOB_RESYNC_FINALIZADA = 'PUI_RESYNC_FINALIZADA';

    private function anonymousRequestId(): string
    {
        try {
            return 'job-' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return 'job-' . uniqid('', true);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function sanitizeParamsForLog(array $params): array
    {
        return PuiLogger::sanitizeParamsForLog($params);
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>|null
     */
    private function sanitizePayloadForLog(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['_note' => 'payload no serializable'];
        }
        if (strlen($json) > 4000) {
            return ['_truncated' => substr($json, 0, 4000) . '…'];
        }
        $decoded = json_decode($json, true);
        return \is_array($decoded) ? $decoded : ['_raw' => $json];
    }

    /**
     * PDO OCI: tras MERGE/UPDATE/INSERT correctos el SQLSTATE a veces es null o '' (no '00000').
     */
    private static function isPdoSqlStateSuccess(?string $sqlState): bool
    {
        return $sqlState === null || $sqlState === '' || $sqlState === '00000';
    }

    private function parseSqlOffset(?string $message): ?int
    {
        if ($message === null || $message === '') {
            return null;
        }
        if (preg_match('/(?:position|offset)\s*(?:is|:)?\s*(\d+)/i', $message, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/at\s+line\s+(\d+)/i', $message, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Contexto tipo oci_error a partir de PDO / statement (driver OCI).
     *
     * @return array{message:string,code:int|string|null,offset:int|null,stmt_error_info:array<int,mixed>|null,pdo_error_info:array<int,mixed>|null}
     */
    private function ociLikeFromPdo(\Throwable $e, ?\PDOStatement $stmt): array
    {
        $stmtInfo = null;
        if ($stmt !== null) {
            try {
                $stmtInfo = $stmt->errorInfo();
            } catch (\Throwable $t) {
                $stmtInfo = null;
            }
        }
        $pdoInfo = null;
        if ($e instanceof \PDOException) {
            try {
                $pdoInfo = $e->errorInfo ?? null;
            } catch (\Throwable $t) {
                $pdoInfo = null;
            }
        }
        $msg = $e->getMessage();
        $code = $e->getCode();
        if (\is_array($stmtInfo) && isset($stmtInfo[2]) && (string) $stmtInfo[2] !== '') {
            $msg = (string) $stmtInfo[2];
        }
        if (\is_array($stmtInfo) && isset($stmtInfo[1]) && $stmtInfo[1] !== null && $stmtInfo[1] !== '') {
            $code = $stmtInfo[1];
        } elseif (\is_array($pdoInfo) && isset($pdoInfo[1]) && $pdoInfo[1] !== null && $pdoInfo[1] !== '') {
            $code = $pdoInfo[1];
        }
        $offset = $this->parseSqlOffset($msg);

        return [
            'message' => $msg,
            'code' => $code,
            'offset' => $offset,
            'stmt_error_info' => $stmtInfo,
            'pdo_error_info' => $pdoInfo,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed>|null $payload
     */
    private function logOracleFailure(
        string $requestId,
        string $operation,
        \Throwable $e,
        string $sql,
        array $params,
        ?array $payload,
        ?string $idReporte,
        ?\PDOStatement $stmt
    ): void {
        $oci = $this->ociLikeFromPdo($e, $stmt);
        PuiLogger::error($requestId, 'oracle_error', [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'trace' => $e->getTraceAsString(),
            'payload' => $this->sanitizePayloadForLog($payload),
            'id_reporte' => $idReporte,
            'operation' => $operation,
            'sql_hash' => substr(sha1($sql), 0, 12),
            'params' => $this->sanitizeParamsForLog($params),
            'oci_error' => [
                'message' => $oci['message'],
                'code' => $oci['code'],
                'offset' => $oci['offset'],
            ],
            'stmt_error_info' => $oci['stmt_error_info'],
            'pdo_error_info' => $oci['pdo_error_info'],
        ]);
    }

    /**
     * Insert/merge/update que debe fallar de forma explícita (503 interno).
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed>|null $payload
     *
     * @throws DatabaseUnavailableException
     */
    private function executeInsert(
        Database $db,
        string $sql,
        array $params,
        string $operation,
        ?string $requestId,
        ?array $payload = null,
        ?string $idReporte = null
    ): void {
        $rid = $requestId ?? $this->anonymousRequestId();
        $pdo = $db->db_activa;
        if ($pdo === null) {
            PuiLogger::throwDatabaseUnavailable();
        }
        $stmt = null;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $err = $stmt->errorInfo();
            $sqlState = $err[0] ?? null;
            if (!self::isPdoSqlStateSuccess($sqlState)) {
                $pe = new \PDOException((string) ($err[2] ?? 'Error en sentencia SQL'), is_numeric($err[1] ?? null) ? (int) $err[1] : 0);
                $this->logOracleFailure($rid, $operation, $pe, $sql, $params, $payload, $idReporte, $stmt);
                PuiLogger::throwDatabaseUnavailable($pe);
            }
        } catch (\PDOException $e) {
            $this->logOracleFailure($rid, $operation, $e, $sql, $params, $payload, $idReporte, $stmt);
            PuiLogger::throwDatabaseUnavailable($e);
        }
    }

    /**
     * Insert/update tolerante (p. ej. tomar job): registra oracle_error y devuelve éxito/fallo.
     *
     * @param array<string,mixed> $params
     */
    private function tryInsert(
        Database $db,
        string $sql,
        array $params,
        string $operation,
        ?string $requestId,
        ?string $idReporte = null
    ): bool {
        $rid = $requestId ?? $this->anonymousRequestId();
        $pdo = $db->db_activa;
        if ($pdo === null) {
            return false;
        }
        $stmt = null;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $err = $stmt->errorInfo();
            $sqlState = $err[0] ?? null;
            if (!self::isPdoSqlStateSuccess($sqlState)) {
                $pe = new \PDOException((string) ($err[2] ?? 'Error en sentencia SQL'), is_numeric($err[1] ?? null) ? (int) $err[1] : 0);
                $this->logOracleFailure($rid, $operation, $pe, $sql, $params, null, $idReporte, $stmt);
                return false;
            }
            return true;
        } catch (\PDOException $e) {
            $this->logOracleFailure($rid, $operation, $e, $sql, $params, null, $idReporte, $stmt);
            return false;
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function executeQueryAll(
        Database $db,
        string $sql,
        array $params,
        string $operation,
        ?string $requestId,
        ?string $idReporte = null
    ): array {
        $rid = $requestId ?? $this->anonymousRequestId();
        $pdo = $db->db_activa;
        if ($pdo === null) {
            return [];
        }
        $stmt = null;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return \is_array($rows) ? $rows : [];
        } catch (\PDOException $e) {
            $this->logOracleFailure($rid, $operation, $e, $sql, $params, null, $idReporte, $stmt);
            return [];
        }
    }

    public function programarFase3(string $idReporte, bool $esPrueba, int $intervalMinutes = 15, ?string $requestId = null): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
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
                    TO_CLOB(:payload_json) AS PAYLOAD_JSON,
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

        // INTERVAL_MINUTES en tabla: referencia aproximada (compat); el runner usa PUI_FASE3_JOB_INTERVAL_SECONDS.
        $this->executeInsert($db, $sql, [
            'job_type' => self::JOB_FASE3_SCAN,
            'id_reporte' => $idReporte,
            'payload_json' => $payload,
            'interval_minutes' => max(1, $intervalMinutes),
        ], 'programarFase3_merge', $requestId, [
            'id_reporte' => $idReporte,
            'es_prueba' => $esPrueba,
            'interval_minutes' => $intervalMinutes,
        ], $idReporte);
    }

    public function cancelarJobsReporte(string $idReporte, ?string $requestId = null): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
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
        $this->executeInsert($db, $sql, ['id_reporte' => $idReporte], 'cancelarJobsReporte', $requestId, null, $idReporte);
    }

    public function cancelarJob(int $idJob, ?string $requestId = null): void
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
        $this->tryInsert($db, $sql, ['id' => $idJob], 'cancelarJob', $requestId, null);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function obtenerJobsPendientes(int $limit = 20, ?string $requestId = null): array
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
        $rows = $this->executeQueryAll($db, $sql, ['limite' => max(1, $limit)], 'obtenerJobsPendientes', $requestId, null);
        return is_array($rows) ? $rows : [];
    }

    public function tomarJob(int $id, string $worker, ?string $requestId = null): bool
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return false;
        }

        $rid = $requestId ?? $this->anonymousRequestId();
        $pdo = $db->db_activa;

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET STATUS = 'RUNNING',
                LOCKED_BY = :worker,
                LOCKED_AT = SYSTIMESTAMP,
                UPDATED_AT = SYSTIMESTAMP
            WHERE ID = :id
              AND STATUS IN ('PENDING','RETRY')
        SQL;

        $stmt = null;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id, 'worker' => $worker]);
            $err = $stmt->errorInfo();
            $sqlState = $err[0] ?? null;
            if (!self::isPdoSqlStateSuccess($sqlState)) {
                $pe = new \PDOException((string) ($err[2] ?? 'Error en sentencia SQL'), is_numeric($err[1] ?? null) ? (int) $err[1] : 0);
                $this->logOracleFailure($rid, 'tomarJob', $pe, $sql, ['id' => $id, 'worker' => $worker], null, null, $stmt);

                return false;
            }
            $n = $stmt->rowCount();
            if ($n > 0) {
                return true;
            }
        } catch (\PDOException $e) {
            $this->logOracleFailure($rid, 'tomarJob', $e, $sql, ['id' => $id, 'worker' => $worker], null, null, $stmt);

            return false;
        }

        // OCI/PDO a veces devuelve 0 o -1 aunque el UPDATE aplicó; verificar estado real.
        $rows = $this->executeQueryAll(
            $db,
            'SELECT STATUS, LOCKED_BY FROM PUI_JOBS WHERE ID = :id',
            ['id' => $id],
            'tomarJob_verificar_estado',
            $rid,
            null
        );
        $r = $rows[0] ?? null;
        if ($r === null) {
            return false;
        }
        $st = strtoupper(trim((string) ($r['STATUS'] ?? $r['status'] ?? '')));
        $lb = trim((string) ($r['LOCKED_BY'] ?? $r['locked_by'] ?? ''));

        return $st === 'RUNNING' && $lb === $worker;
    }

    /**
     * @param int $intervalSeconds Segundos hasta la próxima ejecución elegible (RUN_AT). Alineado con PUI_FASE3_JOB_INTERVAL_SECONDS.
     */
    public function marcarProcesado(int $id, int $intervalSeconds = 30, ?string $requestId = null): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $intervalSeconds = max(1, $intervalSeconds);

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET STATUS = 'PENDING',
                RUN_AT = SYSTIMESTAMP + NUMTODSINTERVAL(:interval_seconds, 'SECOND'),
                ATTEMPTS = 0,
                LOCKED_BY = NULL,
                LOCKED_AT = NULL,
                LAST_ERROR = NULL,
                UPDATED_AT = SYSTIMESTAMP
            WHERE ID = :id
        SQL;
        $this->executeInsert($db, $sql, [
            'id' => $id,
            'interval_seconds' => $intervalSeconds,
        ], 'marcarProcesado', $requestId, ['job_id' => $id], null);
    }

    /**
     * @param int $intervalSeconds Segundos hasta RUN_AT al liberar lock (misma semántica que marcarProcesado).
     */
    public function liberarLock(int $id, int $intervalSeconds = 30, ?string $requestId = null): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return;
        }

        $intervalSeconds = max(1, $intervalSeconds);

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET
                LOCKED_AT = NULL,
                LOCKED_BY = NULL,
                STATUS = 'PENDING',
                RUN_AT = SYSTIMESTAMP + NUMTODSINTERVAL(:interval_seconds, 'SECOND'),
                UPDATED_AT = SYSTIMESTAMP
            WHERE ID = :id
              AND STATUS = 'RUNNING'
        SQL;
        $this->tryInsert($db, $sql, [
            'id' => $id,
            'interval_seconds' => $intervalSeconds,
        ], 'liberarLock', $requestId, null);
    }

    public function marcarError(int $id, string $error, ?string $requestId = null): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
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
        $this->executeInsert($db, $sql, [
            'id' => $id,
            'last_error' => substr($error, 0, 3900),
        ], 'marcarError', $requestId, ['job_id' => $id, 'last_error_len' => strlen($error)], null);
    }

    public function requeueFailed(?string $requestId = null): void
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
        $this->tryInsert($db, $sql, [], 'requeueFailed', $requestId, null);
    }

    /**
     * Encola una resincronización outbound, deduplicada por (job_type, id_reporte).
     *
     * @param array<string,mixed> $payload
     */
    public function encolarResync(string $endpoint, string $idReporte, array $payload, int $intervalMinutes = 5, ?string $requestId = null): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $hash = substr(sha1($json), 0, 12);
        $baseType = $endpoint === 'busqueda-finalizada' ? self::JOB_RESYNC_FINALIZADA : self::JOB_RESYNC_NOTIFICAR;
        $jobType = $baseType . '_' . $hash;

        $sql = <<<SQL
            MERGE INTO PUI_JOBS T
            USING (
                SELECT
                    :job_type AS JOB_TYPE,
                    :id_reporte AS ID_REPORTE,
                    TO_CLOB(:payload_json) AS PAYLOAD_JSON,
                    :interval_minutes AS INTERVAL_MINUTES
                FROM DUAL
            ) S
            ON (T.JOB_TYPE = S.JOB_TYPE AND T.ID_REPORTE = S.ID_REPORTE)
            WHEN MATCHED THEN
                UPDATE SET
                    T.STATUS = 'RETRY',
                    T.PAYLOAD_JSON = S.PAYLOAD_JSON,
                    T.INTERVAL_MINUTES = S.INTERVAL_MINUTES,
                    T.RUN_AT = SYSTIMESTAMP,
                    T.UPDATED_AT = SYSTIMESTAMP,
                    T.LAST_ERROR = NULL
            WHEN NOT MATCHED THEN
                INSERT (JOB_TYPE, ID_REPORTE, STATUS, RUN_AT, ATTEMPTS, MAX_ATTEMPTS, INTERVAL_MINUTES, PAYLOAD_JSON, UPDATED_AT)
                VALUES (S.JOB_TYPE, S.ID_REPORTE, 'RETRY', SYSTIMESTAMP, 0, 10, S.INTERVAL_MINUTES, S.PAYLOAD_JSON, SYSTIMESTAMP)
        SQL;
        $this->executeInsert($db, $sql, [
            'job_type' => $jobType,
            'id_reporte' => $idReporte,
            'payload_json' => $json,
            'interval_minutes' => max(1, $intervalMinutes),
        ], 'encolarResync_merge', $requestId, [
            'endpoint' => $endpoint,
            'id_reporte' => $idReporte,
            'interval_minutes' => $intervalMinutes,
            'job_type' => $jobType,
        ], $idReporte);
    }

    /**
     * Backoff exponencial para jobs de resincronización.
     */
    public function marcarErrorConBackoff(int $id, string $error, ?string $requestId = null): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $sql = <<<SQL
            UPDATE PUI_JOBS
            SET ATTEMPTS = ATTEMPTS + 1,
                STATUS = CASE WHEN ATTEMPTS + 1 >= MAX_ATTEMPTS THEN 'FAILED' ELSE 'RETRY' END,
                RUN_AT = SYSTIMESTAMP + NUMTODSINTERVAL(POWER(2, LEAST(ATTEMPTS, 6)), 'MINUTE'),
                LAST_ERROR = :last_error,
                LOCKED_BY = NULL,
                LOCKED_AT = NULL,
                UPDATED_AT = SYSTIMESTAMP
            WHERE ID = :id
        SQL;
        $this->executeInsert($db, $sql, [
            'id' => $id,
            'last_error' => substr($error, 0, 3900),
        ], 'marcarErrorConBackoff', $requestId, ['job_id' => $id], null);
    }
}
