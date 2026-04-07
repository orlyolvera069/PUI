<?php

namespace App\Pui\Validation;

/**
 * Validación de payloads JSON según §7.2, §7.3 y §8.2 del Manual Técnico PUI v1.0.
 *
 * @return list<string> lista de mensajes de error (vacía = válido)
 */
class PuiManualPayloadValidator
{
    private static function hasString(array $p, string $key): bool
    {
        return array_key_exists($key, $p) && is_string($p[$key]);
    }

    private static function isOptionalStringFieldValid(array $p, string $key, callable $validator): bool
    {
        if (!array_key_exists($key, $p)) {
            return true;
        }
        if (!is_string($p[$key])) {
            return false;
        }
        $v = $p[$key];
        if ($v === '') {
            return true;
        }
        return (bool) $validator($v);
    }

    /** §7.2 Notificar coincidencia — campos obligatorios: curp, lugar_nacimiento, id, institucion_id, fase_busqueda */
    public static function notificarCoincidencia(array $p): array
    {
        $e = [];
        $allowed = [
            'curp',
            'nombre_completo',
            'fecha_nacimiento',
            'lugar_nacimiento',
            'sexo_asignado',
            'telefono',
            'correo',
            'domicilio',
            'fotos',
            'formato_fotos',
            'huellas',
            'formato_huellas',
            'id',
            'institucion_id',
            'tipo_evento',
            'fecha_evento',
            'descripcion_lugar_evento',
            'direccion_evento',
            'fase_busqueda',
        ];
        foreach (array_keys($p) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                $e[] = 'campo no permitido en notificar-coincidencia: ' . $k;
            }
        }
        if (!self::hasString($p, 'curp') || $p['curp'] === '' || !ManualValidators::curpOficial($p['curp'])) {
            $e[] = 'curp obligatorio, 18 caracteres, ^[A-Z0-9]{18}$';
        }
        if (!self::hasString($p, 'lugar_nacimiento') || $p['lugar_nacimiento'] === '' || !ManualValidators::lugarNacimientoValor($p['lugar_nacimiento'])) {
            $e[] = 'lugar_nacimiento obligatorio, máx. 20 caracteres (Anexo 5 o DESCONOCIDO)';
        }
        if (!self::hasString($p, 'id') || $p['id'] === '' || !ManualValidators::idBusqueda($p['id'])) {
            $e[] = 'id obligatorio, formato <FUB>-<UUID4>, longitud 36–75';
        }
        if (!self::hasString($p, 'institucion_id') || $p['institucion_id'] === '' || !ManualValidators::institucionId($p['institucion_id'])) {
            $e[] = 'institucion_id obligatorio, RFC 4–13, ^[A-Z0-9]{4,13}$';
        }
        if (!self::hasString($p, 'fase_busqueda') || !ManualValidators::faseBusquedaCadena($p['fase_busqueda'])) {
            $e[] = 'fase_busqueda obligatorio, cadena "1", "2" o "3"';
        }

        $fase = self::hasString($p, 'fase_busqueda') ? $p['fase_busqueda'] : '';
        $includeEventFields = $fase !== '1';

        // Manual: fase 1 omite tipo_evento/fecha_evento/descripcion_lugar_evento/direccion_evento.
        if ($includeEventFields === false) {
            foreach (['tipo_evento', 'fecha_evento', 'descripcion_lugar_evento', 'direccion_evento'] as $k) {
                if (array_key_exists($k, $p)) {
                    $e[] = 'fase_busqueda=1 debe omitir ' . $k;
                }
            }
        }

        // Manual: para fase 2/3 deben incluirse campos de evento.
        if ($includeEventFields) {
            foreach (['tipo_evento', 'fecha_evento', 'descripcion_lugar_evento', 'direccion_evento'] as $k) {
                if (!array_key_exists($k, $p)) {
                    $e[] = 'fase_busqueda=' . $fase . ' requiere ' . $k;
                }
            }
        }

        // nombre_completo es opcional en §7.2; si viene, debe cumplir estructura.
        if (isset($p['nombre_completo'])) {
            if (!is_array($p['nombre_completo'])) {
                $e[] = 'nombre_completo debe ser objeto JSON';
            } else {
            $nc = $p['nombre_completo'];
            foreach (['nombre', 'primer_apellido', 'segundo_apellido'] as $k) {
                if (!array_key_exists($k, $nc)) {
                    $e[] = 'nombre_completo.' . $k . ' es obligatorio';
                    continue;
                }
                if (!is_string($nc[$k])) {
                    $e[] = 'nombre_completo.' . $k . ' debe ser cadena';
                    continue;
                }
                $v = $nc[$k];
                if (strlen($v) < 1 || strlen($v) > 50) {
                    $e[] = 'nombre_completo.' . $k . ' longitud debe estar en 1..50';
                    continue;
                }
                if (!preg_match("/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ '\\-]{1,50}$/u", $v)) {
                    $e[] = 'nombre_completo.' . $k . ' no cumple regex manual';
                }
            }
            }
        }

        // domicilio / direccion_evento
        $validateDomicilio = function (mixed $obj, string $label) use (&$e): void {
            if (!is_array($obj)) {
                $e[] = $label . ' debe ser objeto JSON';
                return;
            }
            $requiredKeys = [
                'direccion',
                'calle',
                'numero',
                'colonia',
                'codigo_postal',
                'municipio_o_alcaldia',
                'entidad_federativa',
            ];
            foreach ($requiredKeys as $k) {
                if (!array_key_exists($k, $obj)) {
                    $e[] = $label . '.' . $k . ' es obligatorio';
                }
            }
            // Regex de manual para campos; se evalúa sólo si la clave existe.
            if (isset($obj['direccion']) && (!is_string($obj['direccion']) || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,500}$/u', $obj['direccion']))) {
                $e[] = $label . '.direccion no cumple regex manual';
            }
            if (isset($obj['calle']) && (!is_string($obj['calle']) || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,50}$/u', $obj['calle']))) {
                $e[] = $label . '.calle no cumple regex manual';
            }
            if (isset($obj['numero']) && (!is_string($obj['numero']) || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,20}$/u', $obj['numero']))) {
                $e[] = $label . '.numero no cumple regex manual';
            }
            if (isset($obj['colonia']) && (!is_string($obj['colonia']) || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,50}$/u', $obj['colonia']))) {
                $e[] = $label . '.colonia no cumple regex manual';
            }
            if (isset($obj['codigo_postal']) && (!is_string($obj['codigo_postal']) || !preg_match('/^\\d{0,5}$/', $obj['codigo_postal']))) {
                $e[] = $label . '.codigo_postal no cumple regex manual';
            }
            if (isset($obj['municipio_o_alcaldia']) && (!is_string($obj['municipio_o_alcaldia']) || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,100}$/u', $obj['municipio_o_alcaldia']))) {
                $e[] = $label . '.municipio_o_alcaldia no cumple regex manual';
            }
            if (isset($obj['entidad_federativa']) && (!is_string($obj['entidad_federativa']) || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,40}$/u', $obj['entidad_federativa']))) {
                $e[] = $label . '.entidad_federativa no cumple regex manual';
            }
        };

        // domicilio es opcional en §7.2; si viene, validar estructura/regex.
        if (array_key_exists('domicilio', $p)) {
            $validateDomicilio($p['domicilio'], 'domicilio');
        }
        if ($includeEventFields && array_key_exists('direccion_evento', $p)) {
            $validateDomicilio($p['direccion_evento'], 'direccion_evento');
        }

        if (!self::isOptionalStringFieldValid($p, 'fecha_nacimiento', static fn(string $v): bool => ManualValidators::fechaIso8601($v))) {
            $e[] = 'fecha_nacimiento debe ser YYYY-MM-DD';
        }
        if (!self::isOptionalStringFieldValid($p, 'fecha_evento', static fn(string $v): bool => ManualValidators::fechaIso8601($v))) {
            $e[] = 'fecha_evento debe ser YYYY-MM-DD';
        }
        if (!self::isOptionalStringFieldValid($p, 'sexo_asignado', static fn(string $v): bool => (bool) preg_match('/^[MHX]$/', $v))) {
            $e[] = 'sexo_asignado debe ser H, M o X';
        }
        if (array_key_exists('descripcion_lugar_evento', $p) && (!is_string($p['descripcion_lugar_evento']) || strlen($p['descripcion_lugar_evento']) > 500)) {
            $e[] = 'descripcion_lugar_evento máx. 500 caracteres';
        }

        return $e;
    }

    /**
     * §7.2 — fase_busqueda e institucion_id como cadenas en JSON (evita enteros por INI u orígenes externos).
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function normalizarNotificarCoincidencia(array $p): array
    {
        $out = $p;
        // id: solo cadena JSON; no trim (paridad con id almacenado en la PUI/simulador, §7.2).
        if (array_key_exists('id', $out)) {
            $out['id'] = (string) $out['id'];
        }
        if (array_key_exists('curp', $out)) {
            $out['curp'] = ManualValidators::normalizeCurp((string) $out['curp']);
        }
        if (array_key_exists('fase_busqueda', $out)) {
            $out['fase_busqueda'] = trim((string) $out['fase_busqueda']);
        }
        if (array_key_exists('institucion_id', $out)) {
            $out['institucion_id'] = strtoupper(trim((string) $out['institucion_id']));
        }

        return $out;
    }

    /**
     * §7.3 — Cuerpo JSON con id e institucion_id siempre como cadenas (sin curp).
     * INI_SCANNER_TYPED puede cargar un RFC solo numérico como int; json_encode lo emitiría sin comillas y la PUI rechaza el cuerpo.
     *
     * @param array<string,mixed> $p
     * @return array{id:string, institucion_id:string}
     */
    public static function normalizarBusquedaFinalizada(array $p): array
    {
        return [
            'id' => (string) ($p['id'] ?? ''),
            'institucion_id' => strtoupper(trim((string) ($p['institucion_id'] ?? ''))),
        ];
    }

    /** §7.3 Búsqueda finalizada — solo id e institucion_id */
    public static function busquedaFinalizada(array $p): array
    {
        $e = [];
        $allowed = ['id', 'institucion_id'];
        foreach (array_keys($p) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                $e[] = 'campo no permitido en busqueda-finalizada: ' . $k;
            }
        }
        if (!self::hasString($p, 'id') || $p['id'] === '' || !ManualValidators::idBusqueda($p['id'])) {
            $e[] = 'id obligatorio, formato y longitud según manual';
        }
        if (!self::hasString($p, 'institucion_id') || $p['institucion_id'] === '' || !ManualValidators::institucionId($p['institucion_id'])) {
            $e[] = 'institucion_id obligatorio';
        }
        return $e;
    }

    /**
     * §8.2 Activar reporte — obligatorios: id, curp, lugar_nacimiento (nota 5: campos omitibles si no existen en padrón).
     * Para recepción estricta validamos los tres como obligatorios cuando la PUI envía solicitud completa.
     */
    public static function activarReporte(array $p): array
    {
        $e = [];
        $allowed = [
            'id',
            'curp',
            'institucion_id',
            'nombre',
            'primer_apellido',
            'segundo_apellido',
            'fecha_nacimiento',
            'fecha_desaparicion',
            'lugar_nacimiento',
            'sexo_asignado',
            'telefono',
            'correo',
            'direccion',
            'calle',
            'numero',
            'colonia',
            'codigo_postal',
            'municipio_o_alcaldia',
            'entidad_federativa',
        ];
        foreach (array_keys($p) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                $e[] = 'campo no permitido en activar-reporte: ' . $k;
            }
        }
        // Opcional: RFC institución (§8.2 no lo exige; simuladores/pruebas envían el mismo id que en login / §7.3).
        if (array_key_exists('institucion_id', $p)) {
            if (!is_string($p['institucion_id'])) {
                $e[] = 'institucion_id debe ser cadena';
            } else {
                $ii = trim($p['institucion_id']);
                if ($ii === '') {
                    $e[] = 'institucion_id vacío: omita el campo o use INSTITUCION_RFC en pui.ini';
                } elseif (!ManualValidators::institucionId($ii)) {
                    $e[] = 'institucion_id obligatorio RFC 4–13, ^[A-Z0-9]{4,13}$';
                }
            }
        }
        if (!self::hasString($p, 'id') || $p['id'] === '' || !ManualValidators::idBusqueda($p['id'])) {
            $e[] = 'id obligatorio';
        }
        if (!self::hasString($p, 'curp') || $p['curp'] === '' || !ManualValidators::curpOficial($p['curp'])) {
            $e[] = 'curp obligatorio';
        }
        if (!self::hasString($p, 'lugar_nacimiento') || trim($p['lugar_nacimiento']) === '') {
            $e[] = 'lugar_nacimiento obligatorio';
        } elseif (!ManualValidators::lugarNacimientoValor($p['lugar_nacimiento'])) {
            $e[] = 'lugar_nacimiento longitud inválida (máx. 20)';
        }
        if (!self::isOptionalStringFieldValid($p, 'fecha_nacimiento', static fn(string $v): bool => ManualValidators::fechaIso8601($v))) {
            $e[] = 'fecha_nacimiento formato YYYY-MM-DD';
        }
        if (!self::isOptionalStringFieldValid($p, 'fecha_desaparicion', static fn(string $v): bool => ManualValidators::fechaIso8601($v))) {
            $e[] = 'fecha_desaparicion formato YYYY-MM-DD';
        }

        foreach (['nombre', 'primer_apellido', 'segundo_apellido', 'telefono', 'correo'] as $k) {
            if (!self::isOptionalStringFieldValid($p, $k, static fn(string $v): bool => ManualValidators::seguridadCiber10TextoLibre($v))) {
                $e[] = $k . ' contiene caracteres no permitidos (§10 ciberseguridad)';
            }
        }
        foreach (['direccion', 'calle', 'numero', 'colonia', 'codigo_postal', 'municipio_o_alcaldia', 'entidad_federativa'] as $k) {
            if (!self::isOptionalStringFieldValid($p, $k, static fn(string $v): bool => ManualValidators::seguridadCiber10Domicilio($v))) {
                $e[] = $k . ' contiene caracteres no permitidos (§10 ciberseguridad)';
            }
        }

        return $e;
    }

    /** §8.4 Desactivar — solo id */
    public static function desactivarReporte(array $p): array
    {
        $e = [];
        $allowed = ['id'];
        foreach (array_keys($p) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                $e[] = 'campo no permitido en desactivar-reporte: ' . $k;
            }
        }
        if (!self::hasString($p, 'id') || $p['id'] === '' || !ManualValidators::idBusqueda($p['id'])) {
            $e[] = 'id obligatorio';
        }
        return $e;
    }
}
