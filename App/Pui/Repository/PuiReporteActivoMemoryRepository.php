<?php

namespace App\Pui\Repository;

/**
 * Persistencia en memoria (proceso) para modo simulación del Manual Técnico PUI — sin Oracle.
 */
class PuiReporteActivoMemoryRepository
{
    /** @var array<string, array<string, mixed>> */
    private static array $rows = [];

    /**
     * @param array<string,mixed> $registro
     */
    public function guardar(string $id, array $registro): void
    {
        $prev = self::$rows[$id] ?? [];
        $activo = !array_key_exists('activo', $registro) || !empty($registro['activo']) ? 1 : 0;
        $row = [
            'ID' => $id,
            'CURP' => strtoupper(trim((string) ($registro['curp'] ?? $prev['CURP'] ?? ''))),
            'INSTITUCION_ID' => strtoupper(trim((string) ($registro['institucion_id'] ?? $prev['INSTITUCION_ID'] ?? ''))),
            'CRITERIO_NOMBRE' => trim((string) ($registro['nombre'] ?? $prev['CRITERIO_NOMBRE'] ?? '')),
            'PRIMER_APELLIDO' => trim((string) ($registro['primer_apellido'] ?? $prev['PRIMER_APELLIDO'] ?? '')),
            'SEGUNDO_APELLIDO' => trim((string) ($registro['segundo_apellido'] ?? $prev['SEGUNDO_APELLIDO'] ?? '')),
            'RFC_CRITERIO' => strtoupper(trim((string) ($registro['rfc_criterio'] ?? $prev['RFC_CRITERIO'] ?? ''))),
            'ESTADO' => strtoupper(trim((string) ($registro['estado'] ?? $prev['ESTADO'] ?? 'ACTIVO'))),
            'ES_PRUEBA' => !empty($registro['es_prueba']) ? 1 : (int) ($prev['ES_PRUEBA'] ?? 0),
            'ACTIVO' => $activo,
            'FECHA_ACTIVACION' => $prev['FECHA_ACTIVACION'] ?? gmdate('Y-m-d\TH:i:s'),
            'ULTIMA_BUSQUEDA' => gmdate('Y-m-d\TH:i:s'),
            'FECHA_DESACTIVACION' => $activo === 0 ? gmdate('Y-m-d\TH:i:s') : null,
            'FECHA_FIN_FASE2' => $prev['FECHA_FIN_FASE2'] ?? null,
            'ULTIMA_EJECUCION_FASE3' => $prev['ULTIMA_EJECUCION_FASE3'] ?? null,
            'NUM_COINCIDENCIAS' => (int) ($prev['NUM_COINCIDENCIAS'] ?? 0),
        ];
        self::$rows[$id] = $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function obtener(string $id): ?array
    {
        return self::$rows[$id] ?? null;
    }

    public function estaActivo(string $id): bool
    {
        $row = $this->obtener($id);
        if ($row === null) {
            return false;
        }
        return (int) ($row['ACTIVO'] ?? 0) === 1;
    }

    public function marcarInactivo(string $id): void
    {
        if (!isset(self::$rows[$id])) {
            return;
        }
        self::$rows[$id]['ACTIVO'] = 0;
        self::$rows[$id]['ESTADO'] = 'CERRADO';
        self::$rows[$id]['FECHA_DESACTIVACION'] = gmdate('Y-m-d\TH:i:s');
        self::$rows[$id]['ULTIMA_BUSQUEDA'] = gmdate('Y-m-d\TH:i:s');
    }

    public function marcarFechaFinFase2(string $idReporte): void
    {
        if (!isset(self::$rows[$idReporte])) {
            return;
        }
        self::$rows[$idReporte]['FECHA_FIN_FASE2'] = gmdate('Y-m-d\TH:i:s');
    }

    public function actualizarUltimaEjecucionFase3(string $idReporte): void
    {
        if (!isset(self::$rows[$idReporte])) {
            return;
        }
        self::$rows[$idReporte]['ULTIMA_EJECUCION_FASE3'] = gmdate('Y-m-d\TH:i:s');
    }

    public function incrementarNumCoincidencias(string $idReporte): void
    {
        if (!isset(self::$rows[$idReporte])) {
            return;
        }
        self::$rows[$idReporte]['NUM_COINCIDENCIAS'] = (int) (self::$rows[$idReporte]['NUM_COINCIDENCIAS'] ?? 0) + 1;
        self::$rows[$idReporte]['ULTIMA_BUSQUEDA'] = gmdate('Y-m-d\TH:i:s');
    }
}
