#Requires -Version 5.1
<#
.SYNOPSIS
    Arranca el servidor PHP de la API (localhost:8080) y el daemon de fase 3 (JobTableRunner).
    Evita duplicados si ya hay procesos equivalentes en ejecución.
.PARAMETER Restart
    Detiene primero la API y el daemon de ESTE proyecto y luego los vuelve a levantar.
#>

param(
    [switch]$Restart
)

$ErrorActionPreference = 'Stop'

$ProjectRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = (Get-Location).Path
}

# --- Lock fase 3 (opcional): directorio y archivo vacío si no existen ---
$lockDir = Join-Path $ProjectRoot 'App\storage\pui'
$lockFilePath = Join-Path $lockDir 'pui_fase3_runner.lock'
if (-not (Test-Path -LiteralPath $lockDir)) {
    New-Item -ItemType Directory -Path $lockDir -Force | Out-Null
}
if (-not (Test-Path -LiteralPath $lockFilePath)) {
    New-Item -ItemType File -Path $lockFilePath -Force | Out-Null
}

if ($Restart) {
    # La línea de comando de php -S suele no incluir la ruta absoluta del repo; se identifica como en Test-*.
    $procs = Get-CimInstance -ClassName Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue
    if ($null -ne $procs) {
        if ($procs -isnot [array]) {
            $procs = @($procs)
        }
        foreach ($p in $procs) {
            $cmd = $p.CommandLine
            if ($null -eq $cmd) { continue }
            $isApi = ($cmd -match '-S\s+localhost:8080') -and ($cmd -match 'router\.php')
            $isDaemon = ($cmd -like '*run-daemon*') -and ($cmd -like '*JobTableRunner*')
            if ($isApi -or $isDaemon) {
                Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
                Write-Host "Proceso detenido (PID $($p.ProcessId))"
            }
        }
    }
    Start-Sleep -Seconds 1
    Write-Host 'Reinicio: procesos API :8080/router y run-daemon JobTableRunner finalizados.'
}

function Get-PhpCommandLines {
    $procs = Get-CimInstance -ClassName Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue
    if ($null -eq $procs) {
        return @()
    }
    if ($procs -isnot [array]) {
        $procs = @($procs)
    }
    return $procs | ForEach-Object { $_.CommandLine }
}

function Test-ApiPhpServerRunning {
    $lines = Get-PhpCommandLines
    foreach ($line in $lines) {
        if ($null -eq $line) { continue }
        if ($line -match '-S\s+localhost:8080' -and $line -match 'router\.php') {
            return $true
        }
    }
    return $false
}

function Test-Fase3DaemonRunning {
    # Misma heurística que en -Restart: evita falso positivo con otro php que lleve "run-daemon" en la línea.
    $lines = Get-PhpCommandLines
    foreach ($line in $lines) {
        if ($null -eq $line) { continue }
        if (($line -like '*run-daemon*') -and ($line -like '*JobTableRunner*')) {
            return $true
        }
    }
    return $false
}

function Get-Fase3DaemonSleepSeconds {
    param([string]$ProjectRoot)
    $iniPath = Join-Path $ProjectRoot 'App\config\pui.ini'
    $defaultHours = 1.0
    if (-not (Test-Path -LiteralPath $iniPath)) {
        return [Math]::Max(1, [int][Math]::Round($defaultHours * 3600))
    }
    $content = Get-Content -LiteralPath $iniPath -ErrorAction SilentlyContinue
    if ($null -eq $content) {
        return [Math]::Max(1, [int][Math]::Round($defaultHours * 3600))
    }
    foreach ($line in $content) {
        if ($line -match '^\s*PUI_FASE3_DAEMON_SLEEP_HOURS\s*=\s*([0-9]*\.?[0-9]+)') {
            $h = [double]$Matches[1]
            if ($h -le 0) { $h = $defaultHours }
            return [Math]::Max(1, [int][Math]::Round($h * 3600))
        }
    }
    foreach ($line in $content) {
        if ($line -match '^\s*PUI_FASE3_DAEMON_SLEEP_SECONDS\s*=\s*(\d+)') {
            return [Math]::Max(1, [int]$Matches[1])
        }
    }
    return [Math]::Max(1, [int][Math]::Round($defaultHours * 3600))
}

# --- API ---
if (Test-ApiPhpServerRunning) {
    Write-Host 'API ya está corriendo'
} else {
    $apiArgs = @(
        '-S', 'localhost:8080',
        '-t', 'public',
        'public/router.php'
    )
    Start-Process -FilePath 'php' -ArgumentList $apiArgs -WorkingDirectory $ProjectRoot -WindowStyle Hidden
    Write-Host 'API iniciada'
}

# --- Daemon fase 3 ---
if (Test-Fase3DaemonRunning) {
    Write-Host 'Daemon ya está corriendo'
} else {
    $daemonSleepSec = Get-Fase3DaemonSleepSeconds -ProjectRoot $ProjectRoot
    $daemonArgs = @(
        '-f', 'Jobs/controllers/JobTableRunner.php',
        'run-daemon', "$daemonSleepSec", '20',
        'App/storage/pui/pui_fase3_runner.lock'
    )
    Start-Process -FilePath 'php' -ArgumentList $daemonArgs -WorkingDirectory $ProjectRoot -WindowStyle Hidden
    Write-Host 'Daemon iniciado'
}

Write-Host "Listo. API: http://localhost:8080"
