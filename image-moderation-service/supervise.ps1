# One supervision tick: start the classifier only if it is not answering.
#
# This is what the scheduled task actually runs, every few minutes. It has
# to be idempotent, because the task fires on a timer and knows nothing
# about what is already running -- including a copy somebody started by
# hand in a console, which is the normal case during development.
#
# Without the health check, every tick launched a second uvicorn that could
# not bind port 8801, and run.ps1's restart loop then retried it every five
# seconds forever: a busy crash loop caused entirely by the thing meant to
# keep the service up.
#
#   .\supervise.ps1             one tick
#   .\supervise.ps1 -Port 9000  elsewhere
#
# ASCII only, like run.ps1 and install-service.ps1.

param(
    [int]$Port = 8801,
    [int]$TimeoutSec = 5
)

$ErrorActionPreference = 'Continue'
Set-Location $PSScriptRoot

function Test-Healthy {
    param([int]$Port, [int]$TimeoutSec)

    try {
        $response = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/health" `
            -UseBasicParsing -TimeoutSec $TimeoutSec
        return $response.StatusCode -eq 200
    } catch {
        return $false
    }
}

$now = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'

if (Test-Healthy -Port $Port -TimeoutSec $TimeoutSec) {
    Write-Host "[$now] classifier is answering on $Port; nothing to do."
    exit 0
}

Write-Host "[$now] classifier is not answering on $Port; starting it." -ForegroundColor Yellow

# run.ps1 owns the restart loop and the dependency checks. This script
# only decides whether it should be running at all, so that the two
# concerns do not end up half-implemented in both files.
& (Join-Path $PSScriptRoot 'run.ps1') -Port $Port
