<?php

namespace Core;

include_once dirname(__DIR__) . "/Core/App.php";

use PDO;

/**
 * @class Conn
 */

class Database
{
    private $configuracion;
    public $db_activa;

    /** Una vez por proceso si PUI_DB_SESSION_DEBUG=1: USER, CURRENT_SCHEMA, COUNT(EVENTO). */
    private static bool $oracleSessionDiagLogged = false;

    function __construct($s = null, $u = null, $p = null)
    {
        $this->configuracion = App::getConfig();
        $this->Conecta($s, $u, $p);
    }

    private function Conecta($s = null, $u = null, $p = null)
    {
        $s = $this->configuracion[$s] ?? $s;
        $servidor = $s ?? $this->configuracion['SERVIDOR'];
        $esquema = $this->configuracion['ESQUEMA'] ?? 'ESIACOM';
        $puerto = $this->configuracion['PUERTO'] ?? 1521;
        $puerto = is_numeric($puerto) ? (int) $puerto : 1521;
        if ($puerto < 1 || $puerto > 65535) {
            $puerto = 1521;
        }

        $cadena = "oci:dbname=//$servidor:$puerto/$esquema;charset=UTF8";
        $usuario = $u ?? $this->configuracion['USUARIO'];
        $password = $p ?? $this->configuracion['PASSWORD'];
        try {
            $this->db_activa =  new PDO($cadena, $usuario, $password);
            $this->maybeLogOracleSessionDiagnostics($servidor, $puerto, (string) $esquema, (string) $usuario, $cadena);
        } catch (\PDOException $e) {
            $this->db_activa = null;
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            // API JSON (/api/...): no romper el envelope; los repositorios tratan db_activa === null.
            if (stripos($uri, '/api/') !== false) {
                error_log('[Database] PDO: ' . $e->getMessage());
                return;
            }
            self::baseNoDisponible("{$e->getMessage()}\nDatos de conexión: $cadena\nUsuario: $usuario\nPassword: (oculto)");
        }
    }

    /**
     * Diagnóstico: misma sesión que ejecuta las queries del padrón (USER, schema efectivo, filas en EVENTO).
     * Activar con PUI_DB_SESSION_DEBUG=1 en App/config/pui.ini.
     *
     * @param mixed $servidor
     */
    private function maybeLogOracleSessionDiagnostics(
        $servidor,
        int $puerto,
        string $serviceName,
        string $usuarioIni,
        string $dsn
    ): void {
        if ($this->db_activa === null || self::$oracleSessionDiagLogged) {
            return;
        }
        if (!class_exists(\App\Pui\Config\PuiConfig::class) || !class_exists(\App\Pui\Http\PuiLogger::class)) {
            return;
        }
        try {
            $enabled = \App\Pui\Config\PuiConfig::get('PUI_DB_SESSION_DEBUG', '0');
        } catch (\Throwable $e) {
            return;
        }
        if ($enabled !== '1' && $enabled !== 1 && $enabled !== true) {
            return;
        }

        self::$oracleSessionDiagLogged = true;

        $rid = \App\Pui\Http\PuiLogger::requestContextId();
        $padronSchema = '';
        try {
            $padronSchema = trim((string) \App\Pui\Config\PuiConfig::get('PUI_PADRON_SCHEMA', ''));
        } catch (\Throwable $e) {
            $padronSchema = '';
        }

        $ctxBase = [
            'config_host' => (string) $servidor,
            'config_puerto' => $puerto,
            'config_service_name' => $serviceName,
            'config_usuario_ini' => $usuarioIni,
            'dsn_sin_password' => $dsn,
            'pui_padron_schema_ini' => $padronSchema !== '' ? $padronSchema : null,
        ];
        \App\Pui\Http\PuiLogger::info($rid, 'oracle_db_conexion_config', $ctxBase);

        $user = $this->oracleScalar("SELECT USER FROM DUAL");
        $currSchema = $this->oracleScalar("SELECT SYS_CONTEXT('USERENV','CURRENT_SCHEMA') FROM DUAL");

        \App\Pui\Http\PuiLogger::info($rid, 'oracle_db_session_identity', [
            'SESSION_USER' => $user,
            'CURRENT_SCHEMA' => $currSchema,
        ]);

        $countUnqual = $this->oracleCountSafe('SELECT COUNT(*) AS CNT FROM EVENTO');
        \App\Pui\Http\PuiLogger::info($rid, 'oracle_db_count_evento', [
            'tabla' => 'EVENTO',
            'resultado' => $countUnqual,
        ]);

        foreach (['PUI', 'ESIACOM'] as $sch) {
            $q = "SELECT COUNT(*) AS CNT FROM {$sch}.EVENTO";
            $r = $this->oracleCountSafe($q);
            \App\Pui\Http\PuiLogger::info($rid, 'oracle_db_count_evento', [
                'tabla' => "{$sch}.EVENTO",
                'resultado' => $r,
            ]);
        }

        if ($padronSchema !== '' && preg_match('/^[A-Za-z0-9_]+$/', $padronSchema)) {
            $ps = strtoupper($padronSchema);
            $q = "SELECT COUNT(*) AS CNT FROM {$ps}.EVENTO";
            $r = $this->oracleCountSafe($q);
            \App\Pui\Http\PuiLogger::info($rid, 'oracle_db_count_evento', [
                'tabla' => "{$ps}.EVENTO (PUI_PADRON_SCHEMA)",
                'resultado' => $r,
            ]);
        }
    }

    private function oracleScalar(string $sql): ?string
    {
        try {
            $stmt = $this->db_activa->query($sql);
            if ($stmt === false) {
                return null;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row === []) {
                return null;
            }

            return (string) reset($row);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{ok:bool, count?:int, error?:string}
     */
    private function oracleCountSafe(string $sql): array
    {
        try {
            $stmt = $this->db_activa->query($sql);
            if ($stmt === false) {
                return ['ok' => false, 'error' => 'query devolvió false'];
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row === []) {
                return ['ok' => false, 'error' => 'sin fila'];
            }
            $v = reset($row);

            return ['ok' => true, 'count' => (int) $v];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function baseNoDisponible($mensaje)
    {
        http_response_code(503);
        echo <<<HTML
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Sistema fuera de línea</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        text-align: center;
                        background-color: #f4f4f4;
                        color: #333;
                        margin: 0;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                    }
                    .container {
                        background-color: #fff;
                        padding: 20px;
                        border-radius: 10px;
                        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                    }
                    h1 {
                        font-size: 2em;
                        color: #d9534f;
                    }
                    p {
                        font-size: 1.2em;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>Sistema fuera de línea</h1>
                    <p>Estamos trabajando para resolver la situación. Por favor, vuelva a intentarlo más tarde.</p>
                </div>
                <input type="hidden" id="baseNoDisponible" value="$mensaje">
            </body>
            <script>
                window.onload = () => {
                    console.log(document.getElementById('baseNoDisponible').value)
                }
            </script>
            </html>
        HTML;
        exit();
    }

    public function AutoCommitOff()
    {
        $this->db_activa->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);
    }

    public function AutoCommitOn()
    {
        $this->db_activa->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
    }

    public function IniciaTransaccion()
    {
        $this->db_activa->beginTransaction();
    }

    public function CancelaTransaccion()
    {
        $this->db_activa->rollBack();
    }

    public function ConfirmaTransaccion()
    {
        $this->db_activa->commit();
    }

    private function muestraError($e, $sql = null, $parametros = null)
    {
        $error = "Error en DB: " . $e->getMessage();

        if ($sql != null) $error .= "\nSql: " . $sql;
        if ($parametros != null) $error .= "\nDatos: " . print_r($parametros, 1);
        echo $error . "\n";
        return $error;
    }

    public function queryOne($sql, $params = [])
    {
        try {
            $stmt = $this->db_activa->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_shift($rows);
        } catch (\PDOException $e) {
            self::muestraError($e, $sql, $params);
            return [];
        }
    }

    public function queryAll($sql, $params = [])
    {
        try {
            $stmt = $this->db_activa->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            self::muestraError($e, $sql, $params);
            return [];
        }
    }

    public function insert($sql, $params = [])
    {
        try {
            $stmt = $this->db_activa->prepare($sql);
            if (!$stmt->execute($params)) {
                return false;
            }
            $err = $stmt->errorInfo();
            $sqlState = $err[0] ?? null;
            // PDO OCI: tras MERGE/INSERT/UPDATE correctos el SQLSTATE a veces viene vacío o null
            // (no '00000'); tratarlo como éxito si execute() ya tuvo éxito.
            if ($sqlState !== null && $sqlState !== '' && $sqlState !== '00000') {
                throw new \PDOException("Error en insert: " . print_r($err, 1) . "\nSql: $sql \nDatos: " . print_r($params, 1));
            }

            return true;
        } catch (\PDOException $e) {
            self::muestraError($e, $sql, $params);
            return false;
        }
    }

    public function insertarBlob($sql, $datos, $blob = [], $clob = [])
    {
        if (!is_array($sql)) {
            $sql = [$sql];
            $datos = [$datos];
        }

        try {
            $this->db_activa->beginTransaction();

            foreach ($sql as $i => $value) {
                $stmt = $this->db_activa->prepare($sql[$i]);
                foreach ($datos[$i] as $key => $value) {
                    if (in_array($key, $blob)) $stmt->bindParam($key, $datos[$i][$key], PDO::PARAM_LOB);
                    else if (in_array($key, $clob)) $stmt->bindParam($key, $datos[$i][$key], PDO::PARAM_STR, strlen($datos[$i][$key]));
                    else $stmt->bindParam($key, $datos[$i][$key]);
                }

                if (!$stmt->execute()) throw new \Exception("Error en insertarBlob: " . print_r($this->db_activa->errorInfo(), 1) . "\nSql : $sql[$i] \nDatos : " . print_r($datos[$i], 1));
                if ($stmt->errorInfo()[0] != '00000') throw new \Exception("Error en insertarBlob: " . print_r($stmt->errorInfo(), 1) . "\nSql : $sql[$i] \nDatos : " . print_r($datos[$i], 1));
            }
            $this->db_activa->commit();
        } catch (\PDOException $e) {
            $this->db_activa->rollBack();
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            $this->db_activa->rollBack();
            throw new \Exception($e->getMessage());
        }
    }

    public function insertar($sql, $datos)
    {
        try {
            if (!$this->db_activa->prepare($sql)->execute($datos)) {
                throw new \Exception("Error en insertar: " . print_r($this->db_activa->errorInfo(), 1) . "\nSql : $sql \nDatos : " . print_r($datos, 1));
            }
        } catch (\PDOException $e) {
            throw new \Exception("Error en insertar: " . $e->getMessage() . "\nSql: $sql \nDatos: " . print_r($datos, 1));
        }
    }

    public function insertCheques($sql, $parametros)
    {
        $stmt = $this->db_activa->prepare($sql);
        $result = $stmt->execute($parametros);

        if ($result) return $result;

        $arr = $stmt->errorInfo();
        return "PDOStatement::errorInfo():\n" . json_encode($arr);
    }

    public function insertaMultiple($sql, $registros, $validacion = null)
    {
        try {
            $this->db_activa->beginTransaction();
            foreach ($registros as $i => $valores) {
                $stmt = $this->db_activa->prepare($sql[$i]);
                $result = $stmt->execute($valores);
                $err = $stmt->errorInfo();
                if (!$result || $err[0] != '00000')
                    throw new \PDOException("Error: " . print_r($err, 1) . "\nSql: " . $sql[$i] . "\nDatos: " . print_r($valores, 1));
            }

            if ($validacion != null) {
                $stmt = $this->db_activa->prepare($validacion['query']);
                $stmt->execute($validacion['datos']);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $resValidacion = $validacion['funcion']($result);
                if ($resValidacion['success'] == false) {
                    $this->db_activa->rollBack();
                    throw new \PDOException($resValidacion['mensaje']);
                }
            }

            return $this->db_activa->commit();
        } catch (\PDOException $e) {
            $this->db_activa->rollBack();
            self::muestraError($e);
            return false;
        }
    }

    public function eliminar($sql)
    {
        try {
            $stmt = $this->db_activa->prepare($sql);
            $stmt->execute();
            $err = $stmt->errorInfo();

            if ($err[0] != '00000')
                throw new \PDOException("Error en delete: " . print_r($err, 1) . "\nSql: $sql");

            return true;
        } catch (\PDOException $e) {
            self::muestraError($e, $sql);
            return false;
        }
    }

    public function EjecutaSP($sp, $parametros)
    {
        try {
            $sp = (strpos($sp, 'CALL ') === false ? 'CALL ' : '') . $sp;
            $stmt = $this->db_activa->prepare($sp);

            foreach ($parametros as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }

            $output = '';
            $stmt->bindParam(':output', $output, \PDO::PARAM_STR | \PDO::PARAM_INPUT_OUTPUT, 4000);
            $stmt->execute();

            return $output;
        } catch (\PDOException $e) {
            throw new \Exception("Error en EjecutaSP: " . $e->getMessage() . "\nSP: $sp \nDatos: " . print_r($parametros, 1));
        }
    }
}
