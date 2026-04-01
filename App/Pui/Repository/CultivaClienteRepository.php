<?php

namespace App\Pui\Repository;

use App\Pui\Config\PuiConfig;
use Core\Database;

/**
 * Padrón Oracle: CLIENTE (datos personales y domicilio en fase 1) y EVENTOS (fases 2 y 3).
 * Proyección con alias compatibles con NotificarCoincidenciaPayloadFactory (NOMBRE1, PRIMAPE, CDGPAI, …).
 */
class CultivaClienteRepository
{
    /** Califica esquema si PUI_PADRON_SCHEMA está definido (p. ej. ESQ.CLIENTE). */
    private static function qualify(string $table): string
    {
        $sch = trim((string) PuiConfig::get('PUI_PADRON_SCHEMA', ''));
        if ($sch === '' || !preg_match('/^[A-Z0-9_]+$/i', $sch)) {
            return $table;
        }

        return strtoupper($sch) . '.' . $table;
    }

    private static function normCurpExpr(string $aliasCol): string
    {
        return "UPPER(REGEXP_REPLACE(TRIM({$aliasCol}), '[[:space:]]+', ''))";
    }

    private static function fromCliente(): string
    {
        return self::qualify('CLIENTE') . ' C';
    }

    private static function fromEventosJoinCliente(): string
    {
        $cli = self::fromCliente();
        $ev = self::qualify('EVENTOS') . ' E';

        return "{$ev} INNER JOIN {$cli} ON " . self::normCurpExpr('E.CURP') . ' = ' . self::normCurpExpr('C.CURP');
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
            TRIM({$c}.NUMERO) AS NUMERO,
            TRIM({$c}.COLONIA) AS CDGPAI,
            TRIM({$c}.CP) AS CODIGO_POSTAL,
            TRIM({$c}.MUNICIPIO) AS CDGMU,
            TRIM({$c}.ENTIDAD_FEDERATIVA) AS ESTADO_NOMBRE
        SQL;
    }

    /** Solo persona (fases 2–3: domicilio del evento viene de EVENTOS). */
    private static function selectClienteSoloPersona(string $alias = 'C'): string
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
            {$c}.SEXO AS SEXO
        SQL;
    }

    /** Dirección del evento administrativo (EVENTOS). */
    private static function selectEventoProyeccion(string $alias = 'E'): string
    {
        $e = $alias;

        return <<<SQL
            TRIM({$e}.TIPO_EVENTO) AS TIPO_EVENTO,
            TO_CHAR({$e}.FECHA_EVENTO, 'YYYY-MM-DD') AS FECHA_EVENTO,
            TRIM({$e}.CALLE) AS CALLE,
            TRIM({$e}.NUMERO) AS NUMERO,
            TRIM({$e}.COLONIA) AS CDGPAI,
            TRIM({$e}.CODIGO_POSTAL) AS CODIGO_POSTAL,
            TRIM({$e}.MUNICIPIO) AS CDGMU,
            TRIM({$e}.ENTIDAD_FEDERATIVA) AS ESTADO_NOMBRE
        SQL;
    }

    private static function nombreCompletoLikeExpr(string $alias = 'C'): string
    {
        $c = $alias;

        return "UPPER(TRIM({$c}.NOMBRE1) || ' ' || TRIM(NVL({$c}.NOMBRE2,'')) || ' ' || TRIM(NVL({$c}.APELLIDO1,'')) || ' ' || TRIM(NVL({$c}.APELLIDO2,'')))";
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
     * Fase 2: EVENTOS ⨝ CLIENTE, ventana sobre FECHA_EVENTO.
     *
     * @param string|null $fechaInicio YYYY-MM-DD
     * @param string|null $fechaFin    YYYY-MM-DD
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
     * POST /busqueda — solo CLIENTE (sin RFC en padrón: criterio RFC no devuelve filas).
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
        $from = self::fromCliente();
        $where = ['1=1'];
        $params = ['limite' => $limite];

        $nombreExpr = self::nombreCompletoLikeExpr('C');

        if ($curp !== null && trim($curp) !== '') {
            $where[] = self::normCurpExpr('C.CURP') . ' LIKE :curp_like';
            $params['curp_like'] = '%' . strtoupper(preg_replace('/\s+/', '', trim($curp)) ?? '') . '%';
        }
        if ($nombreFragmento !== null && trim($nombreFragmento) !== '') {
            $where[] = "{$nombreExpr} LIKE :nom_like";
            $params['nom_like'] = '%' . strtoupper(trim($nombreFragmento)) . '%';
        }
        if ($rfc !== null && trim($rfc) !== '') {
            $where[] = '1=0';
        }

        $sql = 'SELECT * FROM (SELECT '
            . self::selectClienteProyeccion('C')
            . " FROM {$from} WHERE " . implode(' AND ', $where)
            . ' ORDER BY C.ID) WHERE ROWNUM <= :limite';

        $rows = $db->queryAll($sql, $params);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Fase 3: EVENTOS ⨝ CLIENTE; filtro incremental por FECHA_EVENTO.
     *
     * @param string|null $watermarkIso Marca ISO (desde PUI_REPORTES_ACTIVOS); null = sin filtro incremental.
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
        $from = self::fromEventosJoinCliente();

        $nombreExpr = self::nombreCompletoLikeExpr('C');

        $where = [];
        $params = ['limite' => $limite];

        if ($fragmentoNombre !== '') {
            $where[] = "{$nombreExpr} LIKE :nom_like";
            $params['nom_like'] = '%' . strtoupper(trim($fragmentoNombre)) . '%';
        }
        if ($rfcOpcional !== null && $rfcOpcional !== '') {
            $where[] = '1=0';
        }
        if ($where === []) {
            $where[] = '1=1';
        }

        if ($watermarkIso !== null && trim($watermarkIso) !== '') {
            $wm = substr(trim($watermarkIso), 0, 32);
            $wm19 = strlen($wm) >= 19 ? substr($wm, 0, 19) : $wm;
            $op = $watermarkInclusive ? '>=' : '>';
            $where[] = 'E.FECHA_EVENTO ' . $op . " TO_TIMESTAMP(:wm, 'YYYY-MM-DD\"T\"HH24:MI:SS')";
            $params['wm'] = $wm19;
        }

        $sql = 'SELECT ' . self::selectClienteSoloPersona('C') . ', ' . self::selectEventoProyeccion('E')
            . " FROM {$from} WHERE " . implode(' AND ', $where)
            . ' ORDER BY E.FECHA_EVENTO, E.ROWID';

        $sql = 'SELECT * FROM (' . $sql . ') t WHERE ROWNUM <= :limite';

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
    ): array {
        $db = new Database();
        if ($db->db_activa === null) {
            return [];
        }
        $limite = max(1, min(50, $limite));
        $from = self::fromEventosJoinCliente();

        $nombreExpr = self::nombreCompletoLikeExpr('C');

        $where = [
            "{$nombreExpr} LIKE :nom_like",
        ];
        $params = [
            'nom_like' => '%' . strtoupper(trim($fragmento)) . '%',
            'limite' => $limite,
        ];

        if (
            $fechaInicio !== null
            && $fechaFin !== null
            && trim($fechaInicio) !== ''
            && trim($fechaFin) !== ''
        ) {
            $params['fecha_inicio'] = trim($fechaInicio);
            $params['fecha_fin'] = trim($fechaFin);
            $where[] = 'TRUNC(E.FECHA_EVENTO) BETWEEN TRUNC(TO_DATE(:fecha_inicio, \'YYYY-MM-DD\')) AND TRUNC(TO_DATE(:fecha_fin, \'YYYY-MM-DD\'))';
        }

        $sql = 'SELECT ' . self::selectClienteSoloPersona('C') . ', ' . self::selectEventoProyeccion('E')
            . " FROM {$from} WHERE " . implode(' AND ', $where)
            . ' ORDER BY E.FECHA_EVENTO, E.ROWID';

        $sql = 'SELECT * FROM (' . $sql . ') t WHERE ROWNUM <= :limite';

        $rows = $db->queryAll($sql, $params);

        return is_array($rows) ? $rows : [];
    }

    private function debugEnabled(): bool
    {
        $v = PuiConfig::get('PUI_DEBUG_CONFIG', '0');

        return $v === 1 || $v === '1' || $v === true;
    }
}
