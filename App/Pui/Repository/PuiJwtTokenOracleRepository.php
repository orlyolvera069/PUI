<?php

namespace App\Pui\Repository;

use Core\Database;

/**
 * Mantenimiento opcional de PUI_JWT_TOKENS (p. ej. limpieza de filas antiguas).
 * La validez del JWT en cada petición no depende de esta tabla: no hay consumo ni INSERT por request.
 */
class PuiJwtTokenOracleRepository
{
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
