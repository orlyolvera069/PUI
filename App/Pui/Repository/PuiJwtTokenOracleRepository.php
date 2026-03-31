<?php

namespace App\Pui\Repository;

use App\Pui\Http\PuiJsonResponse;
use App\Pui\Http\PuiLogger;
use Core\Database;
use PDO;

class PuiJwtTokenOracleRepository
{
    /**
     * Registra el jti como consumido.
     * Retorna false si el jti ya estaba previamente registrado.
     */
    public function consumeJti(string $jti, int $expEpoch): bool
    {
        $jti = trim($jti);
        if ($jti === '') {
            return false;
        }

        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $expEpoch = max($expEpoch, time() + 60);

        try {
            $sql = <<<SQL
                INSERT INTO PUI_JWT_TOKENS (JTI, EXP_AT, USADO, USED_AT, CREATED_AT)
                VALUES (:jti, TO_TIMESTAMP_TZ(:exp_at, 'YYYY-MM-DD"T"HH24:MI:SS"Z"'), 1, SYSTIMESTAMP, SYSTIMESTAMP)
            SQL;
            $stmt = $db->db_activa->prepare($sql);
            $expAt = gmdate('Y-m-d\TH:i:s\Z', $expEpoch);
            return $stmt->execute([
                'jti' => $jti,
                'exp_at' => $expAt,
            ]);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'ORA-00001') !== false) {
                return false;
            }
            if (PuiJsonResponse::isConnectionOrLinkFailure($e)) {
                PuiLogger::error(PuiLogger::requestContextId(), 'oracle_error', [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString(),
                    'source' => 'PuiJwtTokenOracleRepository::consumeJti',
                    'pdo_error_info' => $e->errorInfo ?? null,
                ]);
                PuiLogger::throwDatabaseUnavailable($e);
            }
            throw new \RuntimeException('No se pudo registrar consumo de JWT.');
        }
    }

    public function purgeExpired(int $graceSeconds = 300): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return;
        }

        try {
            $sql = <<<SQL
                DELETE FROM PUI_JWT_TOKENS
                WHERE EXP_AT < (SYSTIMESTAMP - NUMTODSINTERVAL(:grace, 'SECOND'))
            SQL;
            $stmt = $db->db_activa->prepare($sql);
            $stmt->execute(['grace' => max(0, $graceSeconds)]);
        } catch (\Throwable $e) {
            // Limpieza best-effort, no bloquea flujo principal.
        }
    }
}
