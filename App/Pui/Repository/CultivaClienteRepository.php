<?php

namespace App\Pui\Repository;

use App\Pui\Config\PuiConfig;
use Core\Database;

/**
 * Tabla CL — búsquedas por fase según Manual Técnico PUI (fase 1 básica, 2 histórica, 3 continua).
 */
class CultivaClienteRepository
{
    private const SELECT_BASE = <<<SQL
        SELECT
            CL.CODIGO AS CODIGO_CLIENTE,
            TRIM(CL.NOMBRE1) AS NOMBRE1,
            TRIM(CL.NOMBRE2) AS NOMBRE2,
            TRIM(CL.PRIMAPE) AS PRIMAPE,
            TRIM(CL.SEGAPE) AS SEGAPE,
            CONCATENA_NOMBRE(CL.NOMBRE1, CL.NOMBRE2, CL.PRIMAPE, CL.SEGAPE) AS NOMBRE_COMPLETO,
            CL.RFC AS RFC,
            TRIM(CL.CURP) AS CURP,
            TO_CHAR(CL.NACIMIENTO, 'YYYY-MM-DD') AS FECHA_NACIMIENTO,
            CL.SEXO AS SEXO,
            TRIM(CL.CALLE) AS CALLE,
            CL.CDGPAI AS CDGPAI,
            CL.CDGEF AS CDGEF,
            CL.CDGMU AS CDGMU,
            EF.CCC AS CLAVE_ESTADO,
            EF.NOMBRE AS ESTADO_NOMBRE,
            COL.CDGPOSTAL AS CODIGO_POSTAL
        FROM CL
        LEFT JOIN EF ON CL.CDGEF = EF.CODIGO
        LEFT JOIN COL ON CL.CDGCOL = COL.CODIGO AND CL.CDGLO = COL.CDGLO AND CL.CDGMU = COL.CDGMU AND CL.CDGEF = COL.CDGEF
    SQL;

    /** Fase 1: coincidencia exacta por CURP (datos básicos). */
    public function buscarFase1PorCurpExacta(string $curp18): ?array
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return null;
        }
        $sql = self::SELECT_BASE . ' WHERE UPPER(TRIM(CL.CURP)) = :curp AND ROWNUM = 1';
        $row = $db->queryOne($sql, ['curp' => $curp18]);
        return !empty($row) ? $row : null;
    }

    /**
     * Consulta directa por CURP para endpoint GET /persona/{curp}.
     *
     * @return array<string,mixed>|null
     */
    public function obtenerPersonaPorCurp(string $curp18): ?array
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return null;
        }

        $curp = strtoupper(preg_replace('/\s+/', '', trim($curp18)) ?? '');
        $sql = <<<SQL
            SELECT
                CL.CODIGO AS CODIGO_CLIENTE,
                TRIM(CL.NOMBRE1) AS NOMBRE1,
                TRIM(CL.NOMBRE2) AS NOMBRE2,
                TRIM(CL.PRIMAPE) AS PRIMAPE,
                TRIM(CL.SEGAPE) AS SEGAPE,
                TRIM(CL.CURP) AS CURP,
                TRIM(CL.RFC) AS RFC,
                TO_CHAR(CL.NACIMIENTO, 'YYYY-MM-DD') AS FECHA_NACIMIENTO,
                TRIM(CL.SEXO) AS SEXO,
                TRIM(CL.CDGPAI) AS CDGPAI,
                TRIM(CL.CDGEF) AS CDGEF,
                TRIM(CL.CDGMU) AS CDGMU,
                TRIM(CL.CALLE) AS CALLE
            FROM CL
            WHERE UPPER(REGEXP_REPLACE(TRIM(CL.CURP), '[[:space:]]+', '')) = UPPER(REGEXP_REPLACE(TRIM(:curp), '[[:space:]]+', ''))
              AND ROWNUM = 1
        SQL;
        $row = $db->queryOne($sql, ['curp' => $curp]);

        if ($this->debugEnabled()) {
            error_log('[PUI repo] obtenerPersonaPorCurp ejecutado (sin datos identificables en log)');
        }

        return !empty($row) ? $row : null;
    }

    /**
     * Fase 2: búsqueda “histórica” por fragmento de nombre completo (LIKE).
     *
     * @return list<array<string,mixed>>
     */
    /**
     * Fase 2: búsqueda “histórica” por fragmento de nombre (LIKE) acotada por ventana de fecha.
     *
     * @param string $fragmento
     * @param int $limite
     * @param string|null $fechaInicio YYYY-MM-DD (opcional)
     * @param string|null $fechaFin YYYY-MM-DD (opcional)
     */
    public function buscarFase2HistoricaPorNombre(
        string $fragmento,
        int $limite = 30,
        ?string $fechaInicio = null,
        ?string $fechaFin = null
    ): array {
        return $this->buscarPorNombre($fragmento, $limite, $fechaInicio, $fechaFin);
    }

    /**
     * Búsqueda general para endpoint POST /busqueda.
     *
     * @return list<array<string,mixed>>
     */
    public function busquedaGeneral(?string $curp, ?string $nombreFragmento, ?string $rfc, int $limite = 20): array
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return [];
        }

        $limite = max(1, min(50, $limite));
        $where = ['1=1'];
        $params = ['limite' => $limite];

        if ($curp !== null && trim($curp) !== '') {
            $where[] = 'UPPER(TRIM(CL.CURP)) LIKE :curp_like';
            $params['curp_like'] = '%' . strtoupper(trim($curp)) . '%';
        }
        if ($nombreFragmento !== null && trim($nombreFragmento) !== '') {
            $where[] = 'UPPER(CONCATENA_NOMBRE(CL.NOMBRE1, CL.NOMBRE2, CL.PRIMAPE, CL.SEGAPE)) LIKE :nom_like';
            $params['nom_like'] = '%' . strtoupper(trim($nombreFragmento)) . '%';
        }
        if ($rfc !== null && trim($rfc) !== '') {
            $where[] = 'UPPER(TRIM(CL.RFC)) LIKE :rfc_like';
            $params['rfc_like'] = '%' . strtoupper(trim($rfc)) . '%';
        }

        $sql = 'SELECT * FROM (' . self::SELECT_BASE . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY CL.CODIGO) WHERE ROWNUM <= :limite';
        $rows = $db->queryAll($sql, $params);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Fase 3: búsqueda continua — en este modelo se usa criterio alterno (p. ej. RFC + nombre) para simular eventos distintos.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * @param string|null $watermarkIso Marca temporal ISO (desde PUI_REPORTES_ACTIVOS); null = sin filtro incremental.
     * @param bool $watermarkInclusive true en la primera pasada (desde FECHA_FIN_FASE2); false si ya hubo ejecución previa.
     *
     * @return list<array<string,mixed>>
     */
    public function buscarFase3Continua(
        string $fragmentoNombre,
        ?string $rfcOpcional,
        int $limite = 30,
        ?string $watermarkIso = null,
        bool $watermarkInclusive = false
    ): array {
        $db = new Database();
        if ($db->db_activa === null) {
            return [];
        }
        $limite = max(1, min(50, $limite));
        $where = ['1=1'];
        $params = [];

        if ($fragmentoNombre !== '') {
            $where[] = 'UPPER(CONCATENA_NOMBRE(CL.NOMBRE1, CL.NOMBRE2, CL.PRIMAPE, CL.SEGAPE)) LIKE :nom_like';
            $params['nom_like'] = '%' . strtoupper(trim($fragmentoNombre)) . '%';
        }
        if ($rfcOpcional !== null && $rfcOpcional !== '') {
            $where[] = 'UPPER(TRIM(CL.RFC)) LIKE :rfc_like';
            $params['rfc_like'] = '%' . strtoupper(trim($rfcOpcional)) . '%';
        }

        $expr = $this->clActividadTimestampExpr();
        if ($expr !== null && $watermarkIso !== null && trim($watermarkIso) !== '') {
            $wm = substr(trim($watermarkIso), 0, 32);
            $op = $watermarkInclusive ? '>=' : '>';
            $where[] = '(' . $expr . ') ' . $op . ' TO_TIMESTAMP(:wm, \'YYYY-MM-DD"T"HH24:MI:SS\')';
            $params['wm'] = strlen($wm) >= 19 ? substr($wm, 0, 19) : $wm;
        }

        $sql = 'SELECT * FROM (' . self::SELECT_BASE . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY CL.CODIGO) WHERE ROWNUM <= :limite';
        $params['limite'] = $limite;

        $rows = $db->queryAll($sql, $params);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buscarPorNombre(
        string $fragmento,
        int $limite,
        ?string $fechaInicio = null,
        ?string $fechaFin = null
    ): array
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return [];
        }
        $limite = max(1, min(50, $limite));
        $where = ['1=1'];
        $params = [
            'nom_like' => '%' . strtoupper(trim($fragmento)) . '%',
            'limite' => $limite,
        ];
        $where[] = 'UPPER(CONCATENA_NOMBRE(CL.NOMBRE1, CL.NOMBRE2, CL.PRIMAPE, CL.SEGAPE)) LIKE :nom_like';

        // Ventana [fecha_desaparición, hoy] acotada a 12 años (orquestador). Criterio sobre actividad del registro en CL, no NACIMIENTO.
        $actividad = $this->clActividadTimestampExpr();
        if (
            $actividad !== null
            && $fechaInicio !== null
            && $fechaFin !== null
            && trim($fechaInicio) !== ''
            && trim($fechaFin) !== ''
        ) {
            $params['fecha_inicio'] = trim($fechaInicio);
            $params['fecha_fin'] = trim($fechaFin);
            $where[] = 'TRUNC(' . $actividad . ') BETWEEN TRUNC(TO_DATE(:fecha_inicio, \'YYYY-MM-DD\')) AND TRUNC(TO_DATE(:fecha_fin, \'YYYY-MM-DD\'))';
        }

        $sql = 'SELECT * FROM (' . self::SELECT_BASE . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY CL.CODIGO) WHERE ROWNUM <= :limite';
        $rows = $db->queryAll($sql, $params);
        return is_array($rows) ? $rows : [];
    }

    private function debugEnabled(): bool
    {
        $v = PuiConfig::get('PUI_DEBUG_CONFIG', '0');
        return $v === 1 || $v === '1' || $v === true;
    }

    /**
     * Expresión Oracle (columna o NVL) con fecha/hora de alta o última modificación del cliente en CL.
     * Configurar en pui.ini: PUI_CL_ACTIVIDAD_TIMESTAMP_EXPR (ej. NVL(CL.MODIFICA, CL.ALTA)).
     */
    private function clActividadTimestampExpr(): ?string
    {
        $raw = trim((string) PuiConfig::get('PUI_CL_ACTIVIDAD_TIMESTAMP_EXPR', ''));
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^[A-Z0-9_().,\s]+$/i', $raw)) {
            return null;
        }
        return $raw;
    }
}
