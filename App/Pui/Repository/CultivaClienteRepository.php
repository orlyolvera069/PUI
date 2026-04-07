<?php

namespace App\Pui\Repository;

use App\Pui\Config\PuiConfig;
use App\Pui\Http\PuiLogger;
use Core\Database;

/**
 * Padrón Oracle: PUI.CLIENTE y PUI.EVENTO (columnas alineadas a ALL_TAB_COLUMNS del esquema PUI).
 * Proyección con alias compatibles con NotificarCoincidenciaPayloadFactory (NOMBRE1, PRIMAPE, CDGPAI, …).
 */
class CultivaClienteRepository
{
    /** Nombre físico de la tabla de eventos en Oracle (no "EVENTOS"). */
    private const TABLA_EVENTO = 'EVENTO';

    /** Esquema del padrón cuando PUI_PADRON_SCHEMA no está definido (mismo owner que en ALL_TAB_COLUMNS). */
    private const PADRON_SCHEMA_DEFAULT = 'PUI';

    /** Log temporal una vez por proceso: tabla calificada usada en JOIN E ⨝ C. */
    private static bool $tablaEventoLogEmitido = false;

    /**
     * Califica tablas como ESQUEMA.CLIENTE / ESQUEMA.EVENTO.
     * PUI_PADRON_SCHEMA en pui.ini; si está vacío o inválido → {@see PADRON_SCHEMA_DEFAULT} (determinístico).
     */
    private static function qualify(string $table): string
    {
        $sch = trim((string) PuiConfig::get('PUI_PADRON_SCHEMA', ''));
        if ($sch === '' || !preg_match('/^[A-Z0-9_]+$/i', $sch)) {
            $sch = self::PADRON_SCHEMA_DEFAULT;
        }

        return strtoupper($sch) . '.' . $table;
    }

    /**
     * CURP normalizada para JOIN y filtros: UPPER + colapso de espacios (equiv. a UPPER(TRIM(CURP)) para CURP sin espacios internos).
     */
    private static function normCurpExpr(string $aliasCol): string
    {
        return "UPPER(REGEXP_REPLACE(TRIM({$aliasCol}), '[[:space:]]+', ''))";
    }

    private static function fromCliente(): string
    {
        return self::qualify('CLIENTE') . ' C';
    }

    private static function logTablaEventoUsadaUnaVez(): void
    {
        if (!self::$tablaEventoLogEmitido) {
            self::$tablaEventoLogEmitido = true;
            PuiLogger::info(
                PuiLogger::requestContextId(),
                'tabla_evento_usada',
                ['tabla_evento_usada' => self::qualify(self::TABLA_EVENTO)]
            );
        }
    }

    /**
     * Fase 2 por CURP / fase 3: EVENTO como base; permite filas de EVENTO sin fila en CLIENTE (LEFT JOIN).
     */
    private static function fromEventoLeftJoinCliente(): string
    {
        self::logTablaEventoUsadaUnaVez();
        $cli = self::fromCliente();
        $ev = self::qualify(self::TABLA_EVENTO) . ' E';

        return "{$ev} LEFT JOIN {$cli} ON " . self::normCurpExpr('E.CURP') . ' = ' . self::normCurpExpr('C.CURP');
    }

    /**
     * Ventana inclusiva por día civil sobre FECHA_EVENTO (equivalente a filtrar E.FECHA_EVENTO en [fecha_desde 00:00, fecha_hasta 23:59…]).
     */
    private static function condicionFechaEventoVentana(): string
    {
        return 'TRUNC(E.FECHA_EVENTO) BETWEEN TO_DATE(:fecha_desde, \'YYYY-MM-DD\') AND TO_DATE(:fecha_hasta, \'YYYY-MM-DD\')';
    }

    /**
     * Columnas CLIENTE → forma esperada por NotificarCoincidenciaPayloadFactory / PuiConsultaService.
     */
    private static function selectClienteProyeccion(string $alias = 'C'): string
    {
        $c = $alias;

        return <<<SQL
            {$c}.ID AS CODIGO_CLIENTE,
            TRIM({$c}.NOMBRE1) AS NOMBRE1,
            TRIM({$c}.NOMBRE2) AS NOMBRE2,
            TRIM({$c}.APELLIDO1) AS PRIMAPE,
            TRIM({$c}.APELLIDO2) AS SEGAPE,
            TRIM({$c}.NOMBRE1) || ' ' || TRIM(NVL({$c}.NOMBRE2, '')) || ' ' || TRIM(NVL({$c}.APELLIDO1, '')) || ' ' || TRIM(NVL({$c}.APELLIDO2, '')) AS NOMBRE_COMPLETO,
            CAST(NULL AS VARCHAR2(20)) AS RFC,
            TRIM({$c}.CURP) AS CURP,
            TO_CHAR({$c}.FECHA_NACIMIENTO, 'YYYY-MM-DD') AS FECHA_NACIMIENTO,
            {$c}.SEXO AS SEXO,
            TRIM({$c}.CALLE) AS CALLE,
            CAST(NULL AS VARCHAR2(100)) AS NUMERO,
            TRIM({$c}.COLONIA) AS CDGPAI,
            TRIM({$c}.CP) AS CODIGO_POSTAL,
            TRIM({$c}.MUNICIPIO) AS CDGMU,
            TRIM({$c}.ENTIDAD_FEDERATIVA) AS ESTADO_NOMBRE
        SQL;
    }

    /**
     * Solo persona (fases 2–3: domicilio del evento viene de EVENTO).
     *
     * @param string|null $eventAlias Si no es null (p. ej. E), CURP = NVL(CLIENTE, EVENTO) para LEFT JOIN sin fila en C.
     */
    private static function selectClienteSoloPersona(string $alias = 'C', ?string $eventAlias = null): string
    {
        $c = $alias;
        $curpExpr = $eventAlias !== null && $eventAlias !== ''
            ? "NVL(TRIM({$c}.CURP), TRIM({$eventAlias}.CURP)) AS CURP"
            : "TRIM({$c}.CURP) AS CURP";

        return <<<SQL
            {$c}.ID AS CODIGO_CLIENTE,
            TRIM({$c}.NOMBRE1) AS NOMBRE1,
            TRIM({$c}.NOMBRE2) AS NOMBRE2,
            TRIM({$c}.APELLIDO1) AS PRIMAPE,
            TRIM({$c}.APELLIDO2) AS SEGAPE,
            TRIM({$c}.NOMBRE1) || ' ' || TRIM(NVL({$c}.NOMBRE2, '')) || ' ' || TRIM(NVL({$c}.APELLIDO1, '')) || ' ' || TRIM(NVL({$c}.APELLIDO2, '')) AS NOMBRE_COMPLETO,
            CAST(NULL AS VARCHAR2(20)) AS RFC,
            {$curpExpr},
            TO_CHAR({$c}.FECHA_NACIMIENTO, 'YYYY-MM-DD') AS FECHA_NACIMIENTO,
            {$c}.SEXO AS SEXO
        SQL;
    }

    /** Dirección del evento administrativo (EVENTO). */
    private static function selectEventoProyeccion(string $alias = 'E'): string
    {
        $e = $alias;

        return <<<SQL
            TRIM({$e}.TIPO_EVENTO) AS TIPO_EVENTO,
            TO_CHAR({$e}.FECHA_EVENTO, 'YYYY-MM-DD') AS FECHA_EVENTO,
            TRIM({$e}.CALLE) AS CALLE,
            CAST(NULL AS VARCHAR2(50)) AS NUMERO,
            TRIM({$e}.COLONIA) AS CDGPAI,
            TRIM({$e}.CP) AS CODIGO_POSTAL,
            TRIM({$e}.MUNICIPIO) AS CDGMU,
            TRIM({$e}.ENTIDAD_FEDERATIVA) AS ESTADO_NOMBRE
        SQL;
    }

    /** Fase 1: CLIENTE por CURP (normalizada). */
    public function buscarFase1PorCurpExacta(string $curp18): ?array
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return null;
        }
        $curp = strtoupper(preg_replace('/\s+/', '', trim($curp18)) ?? '');
        $from = self::fromCliente();
        $sql = 'SELECT ' . self::selectClienteProyeccion('C') . " FROM {$from} WHERE " . self::normCurpExpr('C.CURP') . ' = :curp AND ROWNUM = 1';
        $row = $db->queryOne($sql, ['curp' => $curp]);

        return !empty($row) ? $row : null;
    }

    /**
     * GET /persona/{curp} — solo CLIENTE.
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
        $from = self::fromCliente();
        $sql = 'SELECT '
            . self::selectClienteProyeccion('C')
            . " FROM {$from} WHERE " . self::normCurpExpr('C.CURP') . ' = :curp AND ROWNUM = 1';
        $row = $db->queryOne($sql, ['curp' => $curp]);

        if ($this->debugEnabled()) {
            error_log('[PUI repo] obtenerPersonaPorCurp ejecutado (sin datos identificables en log)');
        }

        return !empty($row) ? $row : null;
    }

    /**
     * Fase 2: ventana temporal; filtro por CURP exacta en EVENTO; LEFT JOIN a CLIENTE.
     *
     * @return list<array<string,mixed>>
     */
    public function buscarFase2HistoricaPorCurpExacta(
        string $curp18,
        int $limite = 30,
        ?string $fechaInicio = null,
        ?string $fechaFin = null
    ): array {
        return $this->buscarPorCurpExactoFase2($curp18, $limite, $fechaInicio, $fechaFin);
    }

    /**
     * POST /busqueda — solo CLIENTE (criterio exclusivo: CURP exacta normalizada).
     *
     * @return list<array<string,mixed>>
     */
    public function busquedaGeneral(?string $curp, int $limite = 20): array
    {
        $db = new Database();
        if ($db->db_activa === null) {
            return [];
        }
        if ($curp === null || trim($curp) === '') {
            return [];
        }

        $limite = max(1, min(50, $limite));
        $curpNorm = strtoupper(preg_replace('/\s+/', '', trim($curp)) ?? '');
        $from = self::fromCliente();
        $sql = 'SELECT * FROM (SELECT '
            . self::selectClienteProyeccion('C')
            . " FROM {$from} WHERE " . self::normCurpExpr('C.CURP') . ' = :curp'
            . ' ORDER BY C.ID) WHERE ROWNUM <= :limite';

        $rows = $db->queryAll($sql, ['curp' => $curpNorm, 'limite' => $limite]);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Fase 3: EVENTO ⨝ CLIENTE (LEFT JOIN); filtro por CURP en EVENTO; incremental por FECHA_EVENTO.
     *
     * @param string|null $watermarkIso Marca ISO (desde PUI_REPORTES_ACTIVOS); null = sin filtro incremental.
     *
     * @return list<array<string,mixed>>
     */
    public function buscarFase3Continua(
        string $curp18,
        int $limite = 30,
        ?string $watermarkIso = null,
        bool $watermarkInclusive = false
    ): array {
        $db = new Database();
        if ($db->db_activa === null) {
            return [];
        }
        $limite = max(1, min(50, $limite));
        $from = self::fromEventoLeftJoinCliente();
        $curp = strtoupper(preg_replace('/\s+/', '', trim($curp18)) ?? '');

        $where = [
            self::normCurpExpr('E.CURP') . ' = :curp',
        ];
        $params = [
            'curp' => $curp,
            'limite' => $limite,
        ];

        if ($watermarkIso !== null && trim($watermarkIso) !== '') {
            $wm = substr(trim($watermarkIso), 0, 32);
            $wm19 = strlen($wm) >= 19 ? substr($wm, 0, 19) : $wm;
            $op = $watermarkInclusive ? '>=' : '>';
            $where[] = 'E.FECHA_EVENTO ' . $op . " TO_TIMESTAMP(:wm, 'YYYY-MM-DD\"T\"HH24:MI:SS')";
            $params['wm'] = $wm19;
        }

        $sql = 'SELECT ' . self::selectClienteSoloPersona('C', 'E') . ', ' . self::selectEventoProyeccion('E')
            . " FROM {$from} WHERE " . implode(' AND ', $where)
            . ' ORDER BY E.FECHA_EVENTO, E.ROWID';

        $sql = 'SELECT * FROM (' . $sql . ') t WHERE ROWNUM <= :limite';

        $rows = $db->queryAll($sql, $params);
        $rows = is_array($rows) ? $rows : [];

        $this->registrarTrazasConsultaEvento('fase3_continua', $sql, $params, $rows, null, null);

        return $rows;
    }

    /**
     * EVENTO LEFT JOIN CLIENTE; filtro por CURP en EVENTO; ventana {@see condicionFechaEventoVentana()}.
     *
     * @return list<array<string,mixed>>
     */
    private function buscarPorCurpExactoFase2(
        string $curp18,
        int $limite,
        ?string $fechaInicio = null,
        ?string $fechaFin = null
    ): array {
        $db = new Database();
        if ($db->db_activa === null) {
            return [];
        }
        $limite = max(1, min(50, $limite));
        $from = self::fromEventoLeftJoinCliente();
        $curp = strtoupper(preg_replace('/\s+/', '', trim($curp18)) ?? '');

        $where = [
            self::normCurpExpr('E.CURP') . ' = :curp',
        ];
        $params = [
            'curp' => $curp,
            'limite' => $limite,
        ];

        $fechaDesde = null;
        $fechaHasta = null;
        if (
            $fechaInicio !== null
            && $fechaFin !== null
            && trim($fechaInicio) !== ''
            && trim($fechaFin) !== ''
        ) {
            $fechaDesde = trim($fechaInicio);
            $fechaHasta = trim($fechaFin);
            $params['fecha_desde'] = $fechaDesde;
            $params['fecha_hasta'] = $fechaHasta;
            $where[] = self::condicionFechaEventoVentana();
        }

        $sql = 'SELECT ' . self::selectClienteSoloPersona('C', 'E') . ', ' . self::selectEventoProyeccion('E')
            . " FROM {$from} WHERE " . implode(' AND ', $where)
            . ' ORDER BY E.FECHA_EVENTO, E.ROWID';

        $sql = 'SELECT * FROM (' . $sql . ') t WHERE ROWNUM <= :limite';

        $rid = PuiLogger::requestContextId();
        PuiLogger::info($rid, 'SQL_FASE2_FINAL', ['query' => $sql]);
        PuiLogger::info($rid, 'PARAMS_FASE2', ['params' => PuiLogger::sanitizeParamsForLog($params)]);

        $rows = $db->queryAll($sql, $params);
        $rows = is_array($rows) ? $rows : [];

        $this->registrarTrazasConsultaEvento('fase2_por_curp', $sql, $params, $rows, $fechaDesde, $fechaHasta);

        PuiLogger::info($rid, 'cultiva_repo_fase2_curp_filas', ['count' => count($rows)]);

        return $rows;
    }

    /**
     * Trazabilidad: ventana de fechas, conteo, SQL opcional (PUI_SQL_DEBUG=1), aviso si 0 filas.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function registrarTrazasConsultaEvento(
        string $alcance,
        string $sql,
        array $params,
        array $rows,
        ?string $fechaDesde,
        ?string $fechaHasta
    ): void {
        $rid = PuiLogger::requestContextId();
        $n = count($rows);
        if ($fechaDesde !== null && $fechaHasta !== null) {
            PuiLogger::info($rid, 'evento_consulta_ventana_fecha', [
                'alcance' => $alcance,
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
                'total_filas' => $n,
            ]);
        } else {
            PuiLogger::info($rid, 'evento_consulta_resultado', [
                'alcance' => $alcance,
                'total_filas' => $n,
            ]);
        }

        $sqlDebug = PuiConfig::get('PUI_SQL_DEBUG', '0');
        if ($sqlDebug === '1' || $sqlDebug === 1 || $sqlDebug === true) {
            PuiLogger::info($rid, 'evento_consulta_sql_debug', [
                'alcance' => $alcance,
                'query' => $sql,
                'parametros' => PuiLogger::sanitizeParamsForLog($params),
                'total_filas' => $n,
            ]);
        }

        if ($n === 0) {
            PuiLogger::warning($rid, 'evento_consulta_sin_resultados', [
                'alcance' => $alcance,
                'mensaje' => 'No hay coincidencias: revisar fecha_evento o datos en EVENTO',
            ]);
        }
    }

    private function debugEnabled(): bool
    {
        $v = PuiConfig::get('PUI_DEBUG_CONFIG', '0');

        return $v === 1 || $v === '1' || $v === true;
    }
}
