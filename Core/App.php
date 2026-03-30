<?php

namespace Core;

/**
 * Configuración mínima para Oracle (mismas claves que MCM: SERVIDOR, ESQUEMA, USUARIO, PASSWORD).
 * En este repo se lee solo App/config/database.ini (no configuracion.ini de Cultiva).
 */
class App
{
    /**
     * @return array<string,mixed>
     */
    public static function getConfig(): array
    {
        $path = dirname(__DIR__) . '/App/config/database.ini';
        if (!is_readable($path)) {
            return [];
        }
        $parsed = @parse_ini_file($path, false, INI_SCANNER_TYPED);
        return is_array($parsed) ? $parsed : [];
    }
}
