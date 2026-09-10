# Starts the image moderation service and keeps it running.
#
# This exists because the service being down is invisible from inside the
# app and looks exactly like a content problem: every photo and video
# upload correctly fails closed with a 503, so "Stories block everything"
# and "nobody started uvicorn" produce the same symptom for a student.
#
# Restarts on exit rather than dying quietly. A classifier that stops is
# not a smaller problem than one that never started: with fail-closed
# enforcement, either way all media uploads stop.
#
#   .\run.ps1              start on the default port
#   .\run.ps1 -Port 9000   start elsewhere
#   .\run.ps1 -Once        no restart loop (for debugging a crash)
#
# ASCII only, deliberately. Windows PowerShell 5.1 reads .ps1 as ANSI
# unless the file carries a BOM, so a stray em-dash in a string silently
# corrupts the parse and the script dies with an unrelated syntax error.

param(
    [int]$Port = 8801,
    [switch]$Once
)

# Deliberately NOT 'Stop': the dependency probe runs a native executable,
# and under 'Stop' any line Python writes to stderr becomes a terminating
# error, which killed this script on its first run even though the
# imports were fine.
$ErrorActionPreference = 'Continue'
Set-Location $PSScriptRoot

$python = Join-Path $PSScriptRoot '.venv\Scripts\python.exe'
if (-not (Test-Path $python)) {
    Write-Host "No virtualenv found. Create one first:" -ForegroundColor Red
    Write-Host "  python -m venv .venv"
    Write-Host "  .venv\Scripts\python.exe -m pip install -r requirements.txt"
    exit 1
}

# Fail loudly now rather than serving 503s later: a service that answers
# but cannot load its weights holds every upload just the same.
& $python -c "import torch, transformers, fastapi"
if ($LASTEXITCODE -ne 0) {
    Write-Host "Dependencies are missing. Run:" -ForegroundColor Red
    Write-Host "  .venv\Scripts\python.exe -m pip install -r requirements.txt"
    exit 1
}

do {
    $now = Get-Date -Format 'HH:mm:ss'
    Write-Host "[$now] starting image moderation on port $Port"

    & $python -m uvicorn app.main:app --host 127.0.0.1 --port $Port
    $code = $LASTEXITCODE

    if ($Once) {
        exit $code
    }

    # A crash loop should be visible, not a busy spin.
    $now = Get-Date -Format 'HH:mm:ss'
    Write-Host "[$now] service exited ($code). Restarting in 5s." -ForegroundColor Yellow
    Start-Sleep -Seconds 5
} while ($true)
