<?php

namespace App\Pui\Repository;

/**
 * Registro de coincidencias en memoria — modo simulación Manual Técnico PUI.
 */
class PuiCoincidenciaMemoryRepository
{
    /** @var list<array<string, mixed>> */
    private static array $eventos = [];

    /**
     * @param array<string,mixed> $linea
     */
    public function registrarCoincidencia(array $linea): void
    {
        self::$eventos[] = $linea;
    }

    public function existeCoincidenciaFasePorCurp(string $idReporte, string $faseBusqueda, string $curp): bool
    {
        $curp = strtoupper(trim($curp));
        if ($curp === '') {
            return false;
        }
        $needle = '"curp":"' . $curp . '"';
        foreach (self::$eventos as $ev) {
            $rid = (string) ($ev['reporte_id'] ?? $ev['id_reporte'] ?? '');
            $fase = (string) ($ev['fase_busqueda'] ?? '');
            $payload = (string) ($ev['payload_json'] ?? '');
            if ($rid === $idReporte && $fase === $faseBusqueda && str_contains($payload, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function existeNotificacionFase3PorEventoRowid(string $idReporte, string $eventoRowid): bool
    {
        $rid = strtoupper(trim($eventoRowid));
        if ($rid === '') {
            return false;
        }
        foreach (self::$eventos as $ev) {
            $r = (string) ($ev['reporte_id'] ?? $ev['id_reporte'] ?? '');
            $fase = (string) ($ev['fase_busqueda'] ?? '');
            $er = strtoupper(trim((string) ($ev['evento_rowid'] ?? '')));
            $http = (int) ($ev['http_status'] ?? 0);
            $ep = (string) ($ev['endpoint'] ?? '');
            if (
                $r === $idReporte
                && $fase === '3'
                && $er === $rid
                && $ep === 'notificar-coincidencia'
                && $http >= 200
                && $http < 300
            ) {
                return true;
            }
        }

        return false;
    }

    public function existeNotificacionExitosaPorEventoRowid(string $idReporte, string $eventoRowid): bool
    {
        $rid = strtoupper(trim($eventoRowid));
        if ($rid === '') {
            return false;
        }
        foreach (self::$eventos as $ev) {
            $r = (string) ($ev['reporte_id'] ?? $ev['id_reporte'] ?? '');
            $er = strtoupper(trim((string) ($ev['evento_rowid'] ?? '')));
            $http = (int) ($ev['http_status'] ?? 0);
            $ep = (string) ($ev['endpoint'] ?? '');
            if (
                $r === $idReporte
                && $er === $rid
                && $ep === 'notificar-coincidencia'
                && $http >= 200
                && $http < 300
            ) {
                return true;
            }
        }

        return false;
    }

    public function contarNotificacionesPorReporte(string $idReporte): int
    {
        $n = 0;
        foreach (self::$eventos as $ev) {
            $rid = (string) ($ev['reporte_id'] ?? $ev['id_reporte'] ?? '');
            $ep = (string) ($ev['endpoint'] ?? '');
            $http = (int) ($ev['http_status'] ?? 0);
            if ($rid === $idReporte && $ep === 'notificar-coincidencia' && $http >= 200 && $http < 300) {
                $n++;
            }
        }

        return $n;
    }
}
