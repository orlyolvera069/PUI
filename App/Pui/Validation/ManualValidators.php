<?php

namespace App\Pui\Validation;

use App\Pui\Reference\PuiAnexo5LugarNacimiento;

/**
 * Validaciones según Manual Técnico PUI v1.0 (13/01/2026) — DOF.
 */
class ManualValidators
{
    /** §7.2 y §8.2 — CURP persona desaparecida / no localizada */
    public const REGEX_CURP = '/^[A-Z0-9]{18}$/';

    /** §7.2 — institucion_id: min 4, max 13, ^[A-Z0-9]{4,13}$ */
    public const REGEX_INSTITUCION_ID = '/^[A-Z0-9]{4,13}$/';

    /** §7.2 — fase_busqueda como cadena de un dígito */
    public const REGEX_FASE_BUSQUEDA = '/^[1-3]$/';

    /**
     * §7.2 / §7.3 / §8.2 / §8.4 — id: <FUB>-<UUID4>, longitud 36–75 (mensajes de error oficiales en §7.2).
     * Caracteres: alfanuméricos y guiones (incluye ejemplos §7.2 y §8.3).
     */
    public const REGEX_ID_BUSQUEDA = '/^[A-Za-z0-9]{1,38}-[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    public static function normalizeCurp(string $curp): string
    {
        return strtoupper(trim($curp));
    }

    public static function curpOficial(string $curp): bool
    {
        return (bool) preg_match(self::REGEX_CURP, self::normalizeCurp($curp));
    }

    public static function idBusqueda(string $id): bool
    {
        $id = trim($id);
        $len = strlen($id);
        if ($len < 36 || $len > 75) {
            return false;
        }
        return (bool) preg_match(self::REGEX_ID_BUSQUEDA, $id);
    }

    public static function institucionId(string $rfc): bool
    {
        $r = strtoupper(trim($rfc));
        $len = strlen($r);
        if ($len < 4 || $len > 13) {
            return false;
        }
        return (bool) preg_match(self::REGEX_INSTITUCION_ID, $r);
    }

    /** fase_busqueda es String en JSON: "1", "2" o "3" (§7.2) */
    public static function faseBusquedaCadena(string $fase): bool
    {
        return (bool) preg_match(self::REGEX_FASE_BUSQUEDA, $fase);
    }

    /**
     * lugar_nacimiento — enum max 20; valores Anexo 5 o DESCONOCIDO / FORÁNEO (§8.2 nota NE→FORÁNEO).
     */
    public static function lugarNacimientoValor(string $valor): bool
    {
        $v = trim($valor);
        if ($v === '') {
            return false;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
        if ($len > 20) {
            return false;
        }
        if (PuiAnexo5LugarNacimiento::esValorPermitido($v)) {
            return true;
        }
        // strtoupper() no es seguro en UTF-8 (Anexo 5: MÉXICO, MICHOACÁN, …).
        if (function_exists('mb_strtoupper')) {
            return PuiAnexo5LugarNacimiento::esValorPermitido(mb_strtoupper($v, 'UTF-8'));
        }

        return PuiAnexo5LugarNacimiento::esValorPermitido(strtoupper($v));
    }

    /** §8.1 — usuario fijo "PUI" */
    public static function usuarioPuiInstitucion(string $usuario): bool
    {
        return $usuario === 'PUI';
    }

    /**
     * §8.1 — clave 16–20 caracteres, al menos una mayúscula, un dígito y un especial permitido.
     */
    public static function claveInstitucion(string $clave): bool
    {
        if (strlen($clave) < 16 || strlen($clave) > 20) {
            return false;
        }
        if (!preg_match('/[A-Z]/', $clave)) {
            return false;
        }
        if (!preg_match('/[0-9]/', $clave)) {
            return false;
        }
        if (!preg_match('/[!@#$%^&*()\-_.+]/', $clave)) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9!@#$%^&*()\-_.+]{16,20}$/', $clave);
    }

    /** Fecha ISO YYYY-MM-DD (§7.2 fecha_nacimiento, fecha_evento) */
    public static function fechaIso8601(string $fecha): bool
    {
        if (strlen($fecha) !== 10) {
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }
        $dt = \DateTime::createFromFormat('!Y-m-d', $fecha);
        if (!$dt instanceof \DateTime) {
            return false;
        }
        return $dt->format('Y-m-d') === $fecha;
    }

    /**
     * Deriva lugar_nacimiento coherente con CURP cuando el activador no lo envía (solo integración interna).
     */
    public static function lugarNacimientoDesdeCurp(string $curp): string
    {
        return PuiAnexo5LugarNacimiento::desdeCurp($curp);
    }

    /**
     * Sección 10 — validación de entradas: no permitir %, <, >, ', ", / en cadenas de texto libre
     * (nombre, apellidos, teléfono, correo). Vacío = válido.
     */
    public static function seguridadCiber10TextoLibre(string $s): bool
    {
        if ($s === '') {
            return true;
        }
        return !preg_match('/[%<>\'\"\/]/u', $s);
    }

    /**
     * Misma regla §10 pero permite / y ' para campos de domicilio alineados al regex §7.2.
     */
    public static function seguridadCiber10Domicilio(string $s): bool
    {
        if ($s === '') {
            return true;
        }
        return !preg_match('/[%<>\x22]/u', $s);
    }
}
