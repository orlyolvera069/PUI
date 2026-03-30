<?php

namespace App\Pui\Validation;

/**
 * Validación de payloads JSON según §7.2, §7.3 y §8.2 del Manual Técnico PUI v1.0.
 *
 * @return list<string> lista de mensajes de error (vacía = válido)
 */
class PuiManualPayloadValidator
{
    /** §7.2 Notificar coincidencia — campos obligatorios: curp, lugar_nacimiento, id, institucion_id, fase_busqueda */
    public static function notificarCoincidencia(array $p): array
    {
        $e = [];
        if (empty($p['curp']) || !ManualValidators::curpOficial((string) $p['curp'])) {
            $e[] = 'curp obligatorio, 18 caracteres, ^[A-Z0-9]{18}$';
        }
        if (empty($p['lugar_nacimiento']) || !ManualValidators::lugarNacimientoValor((string) $p['lugar_nacimiento'])) {
            $e[] = 'lugar_nacimiento obligatorio, máx. 20 caracteres (Anexo 5 o DESCONOCIDO)';
        }
        if (empty($p['id']) || !ManualValidators::idBusqueda((string) $p['id'])) {
            $e[] = 'id obligatorio, formato <FUB>-<UUID4>, longitud 36–75';
        }
        if (empty($p['institucion_id']) || !ManualValidators::institucionId((string) $p['institucion_id'])) {
            $e[] = 'institucion_id obligatorio, RFC 4–13, ^[A-Z0-9]{4,13}$';
        }
        if (!isset($p['fase_busqueda']) || !ManualValidators::faseBusquedaCadena((string) $p['fase_busqueda'])) {
            $e[] = 'fase_busqueda obligatorio, cadena "1", "2" o "3"';
        }

        $fase = isset($p['fase_busqueda']) ? (string) $p['fase_busqueda'] : '';
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

        // nombre_completo (se valida estrictamente cuando se incluye; en esta integración se envía siempre).
        if (!isset($p['nombre_completo']) || !is_array($p['nombre_completo'])) {
            $e[] = 'nombre_completo debe ser objeto JSON';
        } else {
            $nc = $p['nombre_completo'];
            foreach (['nombre', 'primer_apellido', 'segundo_apellido'] as $k) {
                if (!array_key_exists($k, $nc)) {
                    $e[] = 'nombre_completo.' . $k . ' es obligatorio';
                    continue;
                }
                $v = (string) $nc[$k];
                if (strlen($v) < 1 || strlen($v) > 50) {
                    $e[] = 'nombre_completo.' . $k . ' longitud debe estar en 1..50';
                    continue;
                }
                if (!preg_match("/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ '\\-]{1,50}$/u", $v)) {
                    $e[] = 'nombre_completo.' . $k . ' no cumple regex manual';
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
            if (isset($obj['direccion']) && !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,500}$/u', (string) $obj['direccion'])) {
                $e[] = $label . '.direccion no cumple regex manual';
            }
            if (isset($obj['calle']) && !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,50}$/u', (string) $obj['calle'])) {
                $e[] = $label . '.calle no cumple regex manual';
            }
            if (isset($obj['numero']) && !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,20}$/u', (string) $obj['numero'])) {
                $e[] = $label . '.numero no cumple regex manual';
            }
            if (isset($obj['colonia']) && !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,50}$/u', (string) $obj['colonia'])) {
                $e[] = $label . '.colonia no cumple regex manual';
            }
            if (isset($obj['codigo_postal']) && !preg_match('/^\\d{0,5}$/', (string) $obj['codigo_postal'])) {
                $e[] = $label . '.codigo_postal no cumple regex manual';
            }
            if (isset($obj['municipio_o_alcaldia']) && !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,100}$/u', (string) $obj['municipio_o_alcaldia'])) {
                $e[] = $label . '.municipio_o_alcaldia no cumple regex manual';
            }
            if (isset($obj['entidad_federativa']) && !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .,#\'\\/:()\\-]{0,40}$/u', (string) $obj['entidad_federativa'])) {
                $e[] = $label . '.entidad_federativa no cumple regex manual';
            }
        };

        if (!isset($p['domicilio'])) {
            $e[] = 'domicilio es obligatorio';
        } else {
            $validateDomicilio($p['domicilio'], 'domicilio');
        }
        if ($includeEventFields && isset($p['direccion_evento'])) {
            $validateDomicilio($p['direccion_evento'], 'direccion_evento');
        }

        if (isset($p['fecha_nacimiento']) && (string) $p['fecha_nacimiento'] !== '' && !ManualValidators::fechaIso8601((string) $p['fecha_nacimiento'])) {
            $e[] = 'fecha_nacimiento debe ser YYYY-MM-DD';
        }
        if (isset($p['fecha_evento']) && (string) $p['fecha_evento'] !== '' && !ManualValidators::fechaIso8601((string) $p['fecha_evento'])) {
            $e[] = 'fecha_evento debe ser YYYY-MM-DD';
        }
        if (isset($p['sexo_asignado']) && (string) $p['sexo_asignado'] !== '' && !preg_match('/^[MHX]$/', (string) $p['sexo_asignado'])) {
            $e[] = 'sexo_asignado debe ser H, M o X';
        }
        if (isset($p['descripcion_lugar_evento']) && strlen((string) $p['descripcion_lugar_evento']) > 500) {
            $e[] = 'descripcion_lugar_evento máx. 500 caracteres';
        }

        return $e;
    }

    /** §7.3 Búsqueda finalizada — solo id e institucion_id */
    public static function busquedaFinalizada(array $p): array
    {
        $e = [];
        if (empty($p['id']) || !ManualValidators::idBusqueda((string) $p['id'])) {
            $e[] = 'id obligatorio, formato y longitud según manual';
        }
        if (empty($p['institucion_id']) || !ManualValidators::institucionId((string) $p['institucion_id'])) {
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
            'rfc_criterio',
        ];
        foreach (array_keys($p) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                $e[] = 'campo no permitido en activar-reporte: ' . $k;
            }
        }
        if (empty($p['id']) || !ManualValidators::idBusqueda((string) $p['id'])) {
            $e[] = 'id obligatorio';
        }
        if (empty($p['curp']) || !ManualValidators::curpOficial((string) $p['curp'])) {
            $e[] = 'curp obligatorio';
        }
        if (!isset($p['lugar_nacimiento']) || trim((string) $p['lugar_nacimiento']) === '') {
            $e[] = 'lugar_nacimiento obligatorio';
        } elseif (!ManualValidators::lugarNacimientoValor((string) $p['lugar_nacimiento'])) {
            $e[] = 'lugar_nacimiento longitud inválida (máx. 20)';
        }
        if (isset($p['fecha_nacimiento']) && (string) $p['fecha_nacimiento'] !== '' && !ManualValidators::fechaIso8601((string) $p['fecha_nacimiento'])) {
            $e[] = 'fecha_nacimiento formato YYYY-MM-DD';
        }
        if (isset($p['fecha_desaparicion']) && (string) $p['fecha_desaparicion'] !== '' && !ManualValidators::fechaIso8601((string) $p['fecha_desaparicion'])) {
            $e[] = 'fecha_desaparicion formato YYYY-MM-DD';
        }

        foreach (['nombre', 'primer_apellido', 'segundo_apellido', 'telefono', 'correo'] as $k) {
            if (isset($p[$k]) && (string) $p[$k] !== '' && !ManualValidators::seguridadCiber10TextoLibre((string) $p[$k])) {
                $e[] = $k . ' contiene caracteres no permitidos (§10 ciberseguridad)';
            }
        }
        foreach (['direccion', 'calle', 'numero', 'colonia', 'codigo_postal', 'municipio_o_alcaldia', 'entidad_federativa', 'rfc_criterio'] as $k) {
            if (isset($p[$k]) && (string) $p[$k] !== '' && !ManualValidators::seguridadCiber10Domicilio((string) $p[$k])) {
                $e[] = $k . ' contiene caracteres no permitidos (§10 ciberseguridad)';
            }
        }

        return $e;
    }

    /** §8.4 Desactivar — solo id */
    public static function desactivarReporte(array $p): array
    {
        $e = [];
        if (empty($p['id']) || !ManualValidators::idBusqueda((string) $p['id'])) {
            $e[] = 'id obligatorio';
        }
        return $e;
    }
}
