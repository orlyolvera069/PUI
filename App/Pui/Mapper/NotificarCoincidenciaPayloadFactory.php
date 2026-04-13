<?php

namespace App\Pui\Mapper;

use App\Pui\Reference\PuiAnexo5LugarNacimiento;
use App\Pui\Validation\ManualValidators;

/**
 * Construye el cuerpo JSON plano para POST /notificar-coincidencia (§7.2).
 * Sin objeto anidado "coincidencia": todos los campos van en el nivel raíz del manual.
 */
class NotificarCoincidenciaPayloadFactory
{
    /**
     * @param array<string,mixed> $row Fila padrón (mayúsculas) desde CultivaClienteRepository
     * @return array<string,mixed>
     */
    public static function desdeRegistroCl(
        array $row,
        string $idBusqueda,
        string $institucionId,
        string $faseBusqueda,
        string $tipoEvento,
        bool $incluirEventoFase3,
        ?string $fechaEvento = null
    ): array {
        $fase = (string) $faseBusqueda;
        $includeEventFields = $fase !== '1'; // Manual: fase 1 omite tipo_evento/fecha_evento/descripcion_lugar_evento/direccion_evento.

        $curp = ManualValidators::normalizeCurp((string) ($row['CURP'] ?? ''));
        $lugar = PuiAnexo5LugarNacimiento::desdeCurp($curp);

        // Manual permite nombre_completo como objeto opcional, pero en esta integración lo enviamos siempre.
        $n1 = trim((string) ($row['NOMBRE1'] ?? ''));
        $n2 = trim((string) ($row['NOMBRE2'] ?? ''));
        $nombreRaw = $n1 . ($n2 !== '' ? ' ' . $n2 : '');
        $nombre = self::sanitizeNombreCampo($nombreRaw);
        $paterno = self::sanitizeNombreCampo((string) ($row['PRIMAPE'] ?? ''));
        $materno = self::sanitizeNombreCampo((string) ($row['SEGAPE'] ?? ''));

        $nombreCompleto = [
            'nombre' => self::limitLen($nombre, 50),
            'primer_apellido' => self::limitLen($paterno, 50),
            'segundo_apellido' => self::limitLen($materno, 50),
        ];

        $domicilio = self::domicilioParaFila($row);
        $direccionEvento = self::direccionEventoParaFila($row, $domicilio);

        $sexo = self::mapSexo((string) ($row['SEXO'] ?? ''));

        $payload = [
            'curp' => $curp,
            'id' => $idBusqueda,
            'institucion_id' => strtoupper((string) $institucionId),
            // §7.2: cadena "1"|"2"|"3" en JSON (evita entero sin comillas que rompe validadores estrictos).
            'fase_busqueda' => $fase,
            'nombre_completo' => $nombreCompleto,
        ];

        $fn = (string) ($row['FECHA_NACIMIENTO'] ?? '');
        if ($fn !== '' && ManualValidators::fechaIso8601($fn)) {
            $payload['fecha_nacimiento'] = $fn;
        }

        $payload['lugar_nacimiento'] = $lugar;

        if ($sexo !== null) {
            $payload['sexo_asignado'] = $sexo;
        }

        $tel = self::telefonoDesdeRow($row);
        if ($tel !== null) {
            $payload['telefono'] = $tel;
        }

        if ($domicilio !== []) {
            $payload['domicilio'] = $domicilio;
        }

        if ($includeEventFields) {
            $payload['tipo_evento'] = function_exists('mb_substr') ? mb_substr($tipoEvento, 0, 500) : substr($tipoEvento, 0, 500);
            $fe = $fechaEvento ?? gmdate('Y-m-d');
            $payload['fecha_evento'] = $fe;
            $payload['descripcion_lugar_evento'] = self::descripcionLugarEvento($row);
            $payload['direccion_evento'] = $direccionEvento;
        }
        // Nota: $incluirEventoFase3 se mantiene por compatibilidad, pero el manual decide por fase.

        return $payload;
    }

    /**
     * Fase 1: una sola proyección CLIENTE (CALLE, CDGPAI, …).
     * Fases 2–3: DOM_* = CLIENTE, EV_* = EVENTO.
     *
     * @param array<string,mixed> $row
     */
    private static function domicilioParaFila(array $row): array
    {
        if (self::filaTieneDomicilioClienteEventoSeparados($row)) {
            return self::domicilioDesdePrefijo($row, 'DOM_');
        }

        return self::domicilioDesdeCl($row);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function direccionEventoParaFila(array $row, array $domicilioFallback): array
    {
        if (self::filaTieneDomicilioClienteEventoSeparados($row)) {
            return self::domicilioDesdePrefijo($row, 'EV_');
        }

        return $domicilioFallback;
    }

    /** @param array<string,mixed> $row */
    private static function filaTieneDomicilioClienteEventoSeparados(array $row): bool
    {
        return array_key_exists('EV_CALLE', $row) || array_key_exists('DOM_CALLE', $row);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function domicilioDesdePrefijo(array $row, string $pref): array
    {
        $mapped = [
            'CALLE' => $row[$pref . 'CALLE'] ?? '',
            'NUMERO' => $row[$pref . 'NUMERO'] ?? '',
            'CDGPAI' => $row[$pref . 'COLONIA'] ?? '',
            'CODIGO_POSTAL' => $row[$pref . 'CP'] ?? '',
            'CDGMU' => $row[$pref . 'MUNICIPIO'] ?? '',
            'ESTADO_NOMBRE' => $row[$pref . 'ENTIDAD'] ?? '',
        ];

        return self::domicilioDesdeCl($mapped);
    }

    /** @param array<string,mixed> $row */
    private static function telefonoDesdeRow(array $row): ?string
    {
        $t = trim((string) ($row['TELEFONO'] ?? ''));
        if ($t === '') {
            return null;
        }
        $d = preg_replace('/\D+/', '', $t);
        if ($d === '') {
            return null;
        }
        if (strlen($d) > 10) {
            $d = substr($d, -10);
        }

        return $d;
    }

    /** @param array<string,mixed> $row */
    private static function domicilioDesdeCl(array $row): array
    {
        // Manual: para evento/domicilio se requiere dirección, calle, número, colonia, código postal, municipio o alcaldía y entidad federativa.
        $calle = self::sanitizeDomicilioTexto((string) ($row['CALLE'] ?? ''), 500);
        $num = trim((string) ($row['NUMERO'] ?? ''));
        $numSan = $num !== '' ? self::sanitizeDomicilioTexto($num, 50) : '';
        $direccionPlano = trim($calle . ($numSan !== '' ? ' ' . $numSan : ''));
        $cp = trim((string) ($row['CODIGO_POSTAL'] ?? ''));
        $colonia = self::sanitizeDomicilioTexto((string) ($row['CDGPAI'] ?? ''), 50);
        $municipio = self::sanitizeDomicilioTexto((string) ($row['CDGMU'] ?? ''), 100);
        $entidad = self::sanitizeDomicilioTexto((string) ($row['ESTADO_NOMBRE'] ?? $row['CDGEF'] ?? ''), 40);

        // Manual regex permite campos con longitud mínima 0, por lo tanto, enviamos siempre claves aunque estén vacías.
        return [
            'direccion' => self::limitLen($direccionPlano !== '' ? $direccionPlano : $calle, 500),
            'calle' => self::limitLen($calle, 50),
            'numero' => self::limitLen($numSan, 20),
            'colonia' => self::limitLen($colonia, 50),
            'codigo_postal' => preg_match('/^\d{0,5}$/', $cp) ? $cp : '',
            'municipio_o_alcaldia' => self::limitLen($municipio, 100),
            'entidad_federativa' => self::limitLen($entidad, 40),
        ];
    }

    private static function mapSexo(string $sexo): ?string
    {
        $s = strtoupper(trim($sexo));
        if ($s === '' || $s === 'X') {
            return 'X';
        }
        if (in_array($s, ['M', 'H'], true)) {
            return $s;
        }
        if (in_array($s, ['F', 'E'], true)) {
            return 'M';
        }
        if ($s === 'MUJER' || $s === 'FEMENINO') {
            return 'M';
        }
        if ($s === 'HOMBRE' || $s === 'MASCULINO') {
            return 'H';
        }
        return 'X';
    }

    private static function limitLen(string $s, int $maxLen): string
    {
        $s = trim($s);
        if (function_exists('mb_strlen')) {
            if (mb_strlen($s) > $maxLen) {
                return mb_substr($s, 0, $maxLen);
            }
            return $s;
        }
        if (strlen($s) > $maxLen) {
            return substr($s, 0, $maxLen);
        }
        return $s;
    }

    private static function sanitizeNombreCampo(string $v, int $maxLen = 50): string
    {
        $v = trim($v);
        // Manual: sólo letras (incl. acentos), espacios, apóstrofe ' y guión -.
        $v = preg_replace("/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ '\\-]/u", '', $v);
        $v = self::limitLen((string) $v, $maxLen);
        // Si queda vacío, evitamos que falle la validación Min: 1 del manual.
        return $v !== '' ? $v : 'SIN NOMBRE';
    }

    private static function sanitizeDomicilioTexto(string $v, int $maxLen): string
    {
        $v = trim($v);
        // Manual: conjunto permitido para dirección/calle/colonia/municipio/entidad.
        $v = preg_replace("/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#'\\/:\(\)\\-]/u", '', $v);
        return self::limitLen((string) $v, $maxLen);
    }

    /**
     * Formato acordado con padrón: "Sucursal: {nombre}" (columna SUCURSAL en EVENTO).
     *
     * @param array<string,mixed> $row
     */
    private static function descripcionLugarEvento(array $row = []): string
    {
        $suc = trim((string) ($row['SUCURSAL'] ?? $row['sucursal'] ?? ''));
        $suc = $suc !== '' ? self::sanitizeDomicilioTexto($suc, 200) : '';

        return self::limitLen('Sucursal: ' . $suc, 500);
    }
}
