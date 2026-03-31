<?php

namespace App\Pui\Repository;

use App\Pui\Http\PuiLogger;
use Core\Database;

class PuiReporteActivoOracleRepository
{
    /**
     * @param array<string,mixed> $registro
     */
    public function guardar(string $id, array $registro): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $sql = <<<SQL
            MERGE INTO PUI_REPORTES_ACTIVOS T
            USING (
                SELECT
                    :id_reporte AS ID_REPORTE,
                    :curp AS CURP,
                    :institucion_id AS INSTITUCION_ID,
                    :criterio_nombre AS CRITERIO_NOMBRE,
                    :primer_apellido AS PRIMER_APELLIDO,
                    :segundo_apellido AS SEGUNDO_APELLIDO,
                    :rfc_criterio AS RFC_CRITERIO,
                    :estado AS ESTADO,
                    :es_prueba AS ES_PRUEBA,
                    :activo AS ACTIVO
                FROM DUAL
            ) S
            ON (T.ID_REPORTE = S.ID_REPORTE)
            WHEN MATCHED THEN
                UPDATE SET
                    T.CURP = S.CURP,
                    T.INSTITUCION_ID = S.INSTITUCION_ID,
                    T.CRITERIO_NOMBRE = S.CRITERIO_NOMBRE,
                    T.PRIMER_APELLIDO = S.PRIMER_APELLIDO,
                    T.SEGUNDO_APELLIDO = S.SEGUNDO_APELLIDO,
                    T.RFC_CRITERIO = S.RFC_CRITERIO,
                    T.ESTADO = S.ESTADO,
                    T.ES_PRUEBA = S.ES_PRUEBA,
                    T.ACTIVO = S.ACTIVO,
                    T.ULTIMA_BUSQUEDA = SYSTIMESTAMP,
                    T.FECHA_DESACTIVACION = CASE WHEN S.ACTIVO = 0 THEN SYSTIMESTAMP ELSE NULL END
            WHEN NOT MATCHED THEN
                INSERT (ID_REPORTE, CURP, INSTITUCION_ID, CRITERIO_NOMBRE, PRIMER_APELLIDO, SEGUNDO_APELLIDO, RFC_CRITERIO, ESTADO, ES_PRUEBA, ACTIVO, FECHA_ACTIVACION, ULTIMA_BUSQUEDA)
                VALUES (S.ID_REPORTE, S.CURP, S.INSTITUCION_ID, S.CRITERIO_NOMBRE, S.PRIMER_APELLIDO, S.SEGUNDO_APELLIDO, S.RFC_CRITERIO, S.ESTADO, S.ES_PRUEBA, S.ACTIVO, SYSTIMESTAMP, SYSTIMESTAMP)
        SQL;

        $ok = $db->insert($sql, [
            'id_reporte' => $id,
            'curp' => strtoupper(trim((string) ($registro['curp'] ?? ''))),
            'institucion_id' => strtoupper(trim((string) ($registro['institucion_id'] ?? ''))),
            'criterio_nombre' => trim((string) ($registro['nombre'] ?? '')),
            'primer_apellido' => trim((string) ($registro['primer_apellido'] ?? '')),
            'segundo_apellido' => trim((string) ($registro['segundo_apellido'] ?? '')),
            'rfc_criterio' => strtoupper(trim((string) ($registro['rfc_criterio'] ?? ''))),
            'estado' => strtoupper(trim((string) ($registro['estado'] ?? 'ACTIVO'))),
            'es_prueba' => !empty($registro['es_prueba']) ? 1 : 0,
            'activo' => !array_key_exists('activo', $registro) || !empty($registro['activo']) ? 1 : 0,
        ]);
        if (!$ok) {
            throw new \RuntimeException('No se pudo guardar el reporte activo en Oracle.');
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function obtener(string $id): ?array
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return null;
        }

        $sql = <<<SQL
            SELECT
                ID_REPORTE AS ID,
                CURP,
                INSTITUCION_ID,
                CRITERIO_NOMBRE,
                PRIMER_APELLIDO,
                SEGUNDO_APELLIDO,
                RFC_CRITERIO,
                ESTADO,
                ES_PRUEBA,
                ACTIVO,
                TO_CHAR(FECHA_ACTIVACION, 'YYYY-MM-DD"T"HH24:MI:SS') AS FECHA_ACTIVACION,
                TO_CHAR(ULTIMA_BUSQUEDA, 'YYYY-MM-DD"T"HH24:MI:SS') AS ULTIMA_BUSQUEDA,
                TO_CHAR(FECHA_DESACTIVACION, 'YYYY-MM-DD"T"HH24:MI:SS') AS FECHA_DESACTIVACION,
                TO_CHAR(FECHA_FIN_FASE2, 'YYYY-MM-DD"T"HH24:MI:SS') AS FECHA_FIN_FASE2,
                TO_CHAR(ULTIMA_EJECUCION_FASE3, 'YYYY-MM-DD"T"HH24:MI:SS') AS ULTIMA_EJECUCION_FASE3
            FROM PUI_REPORTES_ACTIVOS
            WHERE ID_REPORTE = :id_reporte
        SQL;

        $row = $db->queryOne($sql, ['id_reporte' => $id]);
        return !empty($row) ? $row : null;
    }

    public function estaActivo(string $id): bool
    {
        $row = $this->obtener($id);
        if ($row === null) {
            return false;
        }
        $activo = $row['ACTIVO'] ?? $row['activo'] ?? 0;
        return (int) $activo === 1;
    }

    public function marcarInactivo(string $id): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $sql = <<<SQL
            UPDATE PUI_REPORTES_ACTIVOS
            SET
                ACTIVO = 0,
                ESTADO = 'CERRADO',
                FECHA_DESACTIVACION = SYSTIMESTAMP,
                ULTIMA_BUSQUEDA = SYSTIMESTAMP
            WHERE ID_REPORTE = :id_reporte
        SQL;
        $ok = $db->insert($sql, ['id_reporte' => $id]);
        if (!$ok) {
            throw new \RuntimeException('No se pudo marcar inactivo el reporte en Oracle.');
        }
    }

    /**
     * Marca el instante en que terminó la fase 2 (post busqueda-finalizada exitosa hacia la PUI).
     * Primera ejecución de fase 3 usa este punto como cota inferior incremental.
     */
    public function marcarFechaFinFase2(string $idReporte): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $sql = <<<SQL
            UPDATE PUI_REPORTES_ACTIVOS
            SET FECHA_FIN_FASE2 = SYSTIMESTAMP
            WHERE ID_REPORTE = :id_reporte
        SQL;
        $ok = $db->insert($sql, ['id_reporte' => $idReporte]);
        if (!$ok) {
            throw new \RuntimeException('No se pudo registrar FECHA_FIN_FASE2.');
        }
    }

    public function actualizarUltimaEjecucionFase3(string $idReporte): void
    {
        $db = new Database();
        if ($db->db_activa === null) {
            PuiLogger::throwDatabaseUnavailable();
        }

        $sql = <<<SQL
            UPDATE PUI_REPORTES_ACTIVOS
            SET ULTIMA_EJECUCION_FASE3 = SYSTIMESTAMP
            WHERE ID_REPORTE = :id_reporte
        SQL;
        $ok = $db->insert($sql, ['id_reporte' => $idReporte]);
        if (!$ok) {
            throw new \RuntimeException('No se pudo actualizar ULTIMA_EJECUCION_FASE3.');
        }
    }
}
