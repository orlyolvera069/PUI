<?php

namespace App\Pui;

use App\Pui\Config\PuiConfig;
use App\Pui\Security\PuiAuthService;
use App\Pui\Service\PuiLoginService;
use App\Pui\Service\PuiReporteService;

/**
 * Simulación E2E (login §8.1 → activar prueba §8.3 → desactivar §8.4).
 */
class SimulacionE2e
{
    /**
     * @return array<string,mixed>
     */
    public static function ejecutar(): array
    {
        $requestId = bin2hex(random_bytes(8));
        $login = new PuiLoginService();
        $auth = new PuiAuthService();
        $reporte = new PuiReporteService();

        $cred = [
            'usuario' => (string) PuiConfig::get('PUI_LOGIN_USUARIO', 'PUI'),
            'clave' => (string) PuiConfig::get('PUI_LOGIN_CLAVE', ''),
        ];

        $lr = $login->login($cred);
        $pasos = ['login' => $lr];

        if (($lr['status'] ?? 0) !== 200) {
            $pasos['detenido_en'] = 'login';
            return $pasos;
        }

        $token = $lr['body']['token'] ?? null;
        if ($token === null) {
            $pasos['detenido_en'] = 'token_ausente';
            return $pasos;
        }

        $jwtPayload = $auth->decodeJwt($token);
        $pasos['jwt_payload'] = $jwtPayload;

        $reporteId = 'A1B2C3D4E5F6-550e8400-e29b-41d4-a716-446655440000';
        $bodyActivar = [
            'id' => $reporteId,
            'curp' => 'TEST010101HDFABC01',
            'nombre' => 'JUAN',
            'primer_apellido' => 'PEREZ',
            'segundo_apellido' => 'LOPEZ',
            'fecha_nacimiento' => '1990-01-01',
            'fecha_desaparicion' => '2024-12-15',
            'lugar_nacimiento' => 'CDMX',
            'sexo_asignado' => 'H',
            'telefono' => '5512345678',
            'correo' => 'juan.perez@example.com',
            'direccion' => 'CALLE FICTICIA 123, CENTRO',
            'calle' => 'CALLE FICTICIA',
            'numero' => '123',
            'colonia' => 'CENTRO',
            'codigo_postal' => '06000',
            'municipio_o_alcaldia' => 'CUAUHTÉMOC',
            'entidad_federativa' => 'CDMX',
        ];

        $ar = $reporte->activarReporte($requestId, $bodyActivar, true);
        $pasos['activar_reporte_prueba'] = $ar;

        if (($ar['status'] ?? 0) !== 200) {
            $pasos['detenido_en'] = 'activar_reporte';
            return $pasos;
        }

        if (isset($ar['deferred']) && is_array($ar['deferred'])) {
            $reporte->runPostActivacionFases1y2($ar['deferred']);
        }

        $des = $reporte->desactivarReporte($requestId, [
            'id' => $reporteId,
        ]);
        $pasos['desactivar_reporte'] = $des;

        $pasos['ok'] = true;
        return $pasos;
    }
}
