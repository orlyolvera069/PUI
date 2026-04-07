<?php

namespace App\Pui\Service;

use App\Pui\Config\PuiConfig;
use App\Pui\Repository\CultivaClienteRepository;
use App\Pui\Validation\ManualValidators;

/**
 * Servicio dedicado a consultas de persona y búsquedas generales.
 * Se mantiene separado de la orquestación de reportes para facilitar evolución a integración real PUI.
 */
class PuiConsultaService
{
    private CultivaClienteRepository $cl;

    public function __construct(?CultivaClienteRepository $cl = null)
    {
        $this->cl = $cl ?? new CultivaClienteRepository();
    }

    /**
     * Endpoint GET /persona/{curp}
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public function getPersonaByCurp(string $requestId, string $curp): array
    {
        $curp = ManualValidators::normalizeCurp($curp);
        if (!ManualValidators::curpOficial($curp)) {
            return $this->err($requestId, 400, 'PUI-VAL-400', 'CURP inválida. Debe cumplir ^[A-Z0-9]{18}$.');
        }

        $row = $this->cl->obtenerPersonaPorCurp($curp);
        if ($row === null) {
            if (PuiConfig::isSimulationMode()) {
                return [
                    'status' => 200,
                    'body' => [
                        'persona' => $this->personaMock($curp),
                    ],
                ];
            }
            return $this->err($requestId, 404, 'PUI-NOT-FOUND', 'No se encontró persona para la CURP proporcionada.');
        }

        return [
            'status' => 200,
            'body' => [
                'persona' => $this->mapPersonaSimple($row),
            ],
        ];
    }

    /**
     * Endpoint POST /busqueda
     *
     * @param array<string,mixed> $criterios
     * @return array{status:int, body:array<string,mixed>}
     */
    public function busquedaGeneral(string $requestId, array $criterios): array
    {
        $curp = isset($criterios['curp']) ? ManualValidators::normalizeCurp((string) $criterios['curp']) : null;
        $limite = isset($criterios['limite']) ? (int) $criterios['limite'] : 20;

        if ($curp === null || $curp === '') {
            return $this->err($requestId, 400, 'PUI-VAL-400', 'Debe enviar curp (único criterio de búsqueda permitido).');
        }
        if (!ManualValidators::curpOficial($curp)) {
            return $this->err($requestId, 400, 'PUI-VAL-400', 'CURP inválida en criterio de búsqueda.');
        }

        $rows = $this->cl->busquedaGeneral($curp, $limite);
        $resultados = [];
        foreach ($rows as $row) {
            $resultados[] = $this->mapPersonaSimple($row);
        }

        return [
            'status' => 200,
            'body' => [
                'total' => count($resultados),
                'resultados' => $resultados,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapPersonaSimple(array $row): array
    {
        $nombre = trim((string) ($row['NOMBRE1'] ?? ''));
        $nombre2 = trim((string) ($row['NOMBRE2'] ?? ''));
        $ape1 = trim((string) ($row['PRIMAPE'] ?? ''));
        $ape2 = trim((string) ($row['SEGAPE'] ?? ''));
        $nombreCompleto = trim(implode(' ', array_filter([$nombre, $nombre2, $ape1, $ape2], static fn ($v) => $v !== '')));
        if ($nombreCompleto === '') {
            $nombreCompleto = (string) ($row['NOMBRE_COMPLETO'] ?? '');
        }

        return [
            'nombre_completo' => $nombreCompleto !== '' ? $nombreCompleto : null,
            'curp' => $row['CURP'] ?? null,
            'rfc' => $row['RFC'] ?? null,
            'sexo' => $row['SEXO'] ?? null,
            'fecha_nacimiento' => $row['FECHA_NACIMIENTO'] ?? null,
            'domicilio' => [
                'calle' => $row['CALLE'] ?? null,
                'direccion' => $row['CALLE'] ?? null,
                'cdgpai' => $row['CDGPAI'] ?? null,
                'cdgef' => $row['CDGEF'] ?? null,
                'cdgmu' => $row['CDGMU'] ?? null,
            ],
        ];
    }

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    private function err(string $requestId, int $status, string $codigo, string $mensaje, ?string $detalle = null): array
    {
        return [
            'status' => $status,
            'body' => [
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
                'error' => ['codigo' => $codigo, 'mensaje' => $mensaje, 'detalle' => $detalle],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function personaMock(string $curp): array
    {
        return [
            'nombre_completo' => 'PERSONA MOCK PUI',
            'curp' => $curp,
            'rfc' => substr($curp, 0, 10) . 'MO1',
            'fecha_nacimiento' => '1990-01-01',
            'domicilio' => [
                'direccion' => 'DOMICILIO MOCK 123',
                'codigo_postal' => '06000',
                'estado' => 'CIUDAD DE MEXICO',
            ],
        ];
    }
}
