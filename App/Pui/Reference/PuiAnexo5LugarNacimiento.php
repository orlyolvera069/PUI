<?php

namespace App\Pui\Reference;

/**
 * Anexo 5 — Tabla de mapeo de códigos de CURP (posiciones 12–13) a valor de lugar_nacimiento.
 * Manual Técnico PUI v1.0 (13/01/2026).
 */
class PuiAnexo5LugarNacimiento
{
    /** @var array<string,string> código CURP (2 letras) => valor oficial a enviar */
    private const MAPA = [
        'AS' => 'AGUASCALIENTES',
        'BC' => 'BAJA CALIFORNIA',
        'BS' => 'BAJA CALIFORNIA SUR',
        'CC' => 'CAMPECHE',
        'CS' => 'CHIAPAS',
        'CH' => 'CHIHUAHUA',
        'DF' => 'CDMX',
        'CL' => 'COAHUILA',
        'CM' => 'COLIMA',
        'DG' => 'DURANGO',
        'GT' => 'GUANAJUATO',
        'GR' => 'GUERRERO',
        'HG' => 'HIDALGO',
        'JC' => 'JALISCO',
        'MC' => 'MÉXICO',
        'MN' => 'MICHOACÁN',
        'MS' => 'MORELOS',
        'NT' => 'NAYARIT',
        'NL' => 'NUEVO LEÓN',
        'OC' => 'OAXACA',
        'PL' => 'PUEBLA',
        'QO' => 'QUERÉTARO',
        'QR' => 'QUINTANA ROO',
        'SP' => 'SAN LUIS POTOSÍ',
        'SL' => 'SINALOA',
        'SR' => 'SONORA',
        'TC' => 'TABASCO',
        'TS' => 'TAMAULIPAS',
        'TL' => 'TLAXCALA',
        'VZ' => 'VERACRUZ',
        'YN' => 'YUCATÁN',
        'ZS' => 'ZACATECAS',
        'NE' => 'FORÁNEO',
        'XX' => 'DESCONOCIDO',
    ];

    /**
     * Obtiene el valor de lugar_nacimiento a partir de la CURP (18 caracteres).
     * Si la CURP es inválida o el código no existe, retorna DESCONOCIDO (manual §7.2).
     */
    public static function desdeCurp(string $curp18): string
    {
        $c = strtoupper(trim($curp18));
        if (strlen($c) !== 18 || !preg_match('/^[A-Z0-9]{18}$/', $c)) {
            return 'DESCONOCIDO';
        }
        $cod = substr($c, 11, 2);
        return self::MAPA[$cod] ?? 'DESCONOCIDO';
    }

    /**
     * @return list<string>
     */
    public static function valoresPermitidos(): array
    {
        return array_values(self::MAPA);
    }

    public static function esValorPermitido(string $valor): bool
    {
        $v = trim($valor);
        if ($v === '') {
            return false;
        }
        return in_array($v, self::MAPA, true);
    }
}
