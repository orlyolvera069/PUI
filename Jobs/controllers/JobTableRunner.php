<?php

namespace Jobs\controllers;

use Core\Job;
use Jobs\models\JobTableRunner as RunnerDao;
use App\Pui\Repository\PuiJobOracleRepository;

defined('PROJECTPATH') || define('PROJECTPATH', dirname(__DIR__, 2));
defined('APPPATH') || define('APPPATH', PROJECTPATH . '/App');

require_once PROJECTPATH . '/Core/Job.php';
require_once dirname(__DIR__) . '/models/JobTableRunner.php';

/**
 * Runner CLI de jobs PUI fase 3.
 *
 * Uso:
 * php .\controllers\JobTableRunner.php run-once
 * php .\controllers\JobTableRunner.php run-batch
 * php .\controllers\JobTableRunner.php requeue-failed
 */
class JobTableRunner extends Job
{
    public function __construct()
    {
        parent::__construct('JobTableRunner');
        spl_autoload_register([$this, 'autoloadClasses']);
    }

    public function autoloadClasses(string $className): void
    {
        $filename = PROJECTPATH . '/' . str_replace('\\', '/', $className) . '.php';
        if (is_file($filename)) {
            include_once $filename;
        }
    }

    /**
     * Procesa la cola PUI (fase 3). La lógica de tomar job / ejecutar / liberar lock está en
     * {@see RunnerDao::runOnce} (`Jobs/models/JobTableRunner.php`).
     */
    public function runOnce(int $limit = 20): void
    {
        $worker = php_uname('n') . ':' . getmypid();
        $res = RunnerDao::runOnce($limit, $worker);
        $this->SaveLog('runOnce procesados=' . $res['procesados'] . ' errores=' . $res['errores']);
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    public function runBatch(int $iteraciones = 10, int $limit = 20): void
    {
        for ($i = 0; $i < $iteraciones; $i++) {
            $this->runOnce($limit);
            usleep(250000);
        }
    }

    public function requeueFailed(): void
    {
        $repo = new PuiJobOracleRepository();
        $repo->requeueFailed();
        $this->SaveLog('requeueFailed ejecutado');
        echo "OK\n";
    }

    public function runDaemon(int $sleepSeconds = 30, int $limit = 20, ?string $lockFile = null): void
    {
        $worker = php_uname('n') . ':' . getmypid();
        while (true) {
            $procesadosTick = 0;
            $erroresTick = 0;
            $vueltasTick = 0;
            do {
                $res = RunnerDao::runOnce($limit, $worker);
                $procesadosTick += (int) ($res['procesados'] ?? 0);
                $erroresTick += (int) ($res['errores'] ?? 0);
                $vueltasTick++;
            } while (((int) ($res['candidatos'] ?? 0)) > 0);

            $this->SaveLog(
                'runDaemon tick procesados=' . $procesadosTick
                . ' errores=' . $erroresTick
                . ' vueltas=' . $vueltasTick
            );
            if ($lockFile !== null) {
                $dir = dirname($lockFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                @file_put_contents($lockFile, (string) time(), LOCK_EX);
            }
            sleep(max(1, $sleepSeconds));
        }
    }
}

if (isset($argv[1])) {
    $runner = new JobTableRunner();
    switch ($argv[1]) {
        case 'run-once':
            $runner->runOnce(isset($argv[2]) ? (int) $argv[2] : 20);
            break;
        case 'run-batch':
            $runner->runBatch(isset($argv[2]) ? (int) $argv[2] : 10, isset($argv[3]) ? (int) $argv[3] : 20);
            break;
        case 'requeue-failed':
            $runner->requeueFailed();
            break;
        case 'run-daemon':
            $sleep = isset($argv[2]) ? (int) $argv[2] : 30;
            $lim = isset($argv[3]) ? (int) $argv[3] : 20;
            $lockFile = isset($argv[4]) ? (string) $argv[4] : null;
            $runner->runDaemon($sleep, $lim, $lockFile);
            break;
        case 'help':
            echo "Comandos disponibles:\n";
            echo "run-once [limit]\n";
            echo "run-batch [iteraciones] [limit]\n";
            echo "requeue-failed\n";
            echo "run-daemon [sleepSeconds] [limit=20] [lockFile] — sleep en segundos (ver PUI_FASE3_DAEMON_SLEEP_HOURS en pui.ini)\n";
            break;
        default:
            echo "Comando no reconocido. Usa 'help'.\n";
            break;
    }
} else {
    echo "Debe especificar comando. Usa 'help'.\n";
}
