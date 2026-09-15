# ARUVERSE device simulator.
#
# Builds the web app, serves it, and rebuilds whenever a Dart file changes.
# The simulator page polls the served bundle and reloads itself when a new
# build lands, so a save is the only action needed to see a change.
#
# Why a build-and-serve loop rather than `flutter run -d web-server`:
# that command serves a debug bundle which only starts once a debug service
# has attached. Inside an iframe it never attaches, so the phone frame stays
# blank. A built bundle has no such dependency and renders immediately.
#
# Usage:
#   cd frontend
#   powershell -ExecutionPolicy Bypass -File tool/simulator.ps1
#   powershell -ExecutionPolicy Bypass -File tool/simulator.ps1 -ApiHost 192.168.1.50

param(
  [string]$ApiHost = "",
  [int]$ApiPort = 4000,
  [int]$ReverbPort = 8091,
  [int]$Port = 5599
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# This simulator and Laravel run on the same computer. Auto-selecting the
# first Windows adapter can pick WSL/Hyper-V/VPN (for example 172.25.x.x),
# producing a build that can never reach the API. A real phone remains
# supported by explicitly passing -ApiHost with the computer's Wi-Fi IP.
if (-not $ApiHost) { $ApiHost = "127.0.0.1" }

$api = "http://${ApiHost}:${ApiPort}/api/v1"
Write-Host "API      : $api" -ForegroundColor Cyan
Write-Host "Realtime : ws://${ApiHost}:$ReverbPort" -ForegroundColor Cyan
Write-Host "Simulator: http://localhost:$Port/simulator.html" -ForegroundColor Green

function Build-App {
  Write-Host "[build] compiling..." -ForegroundColor DarkGray
  $previousErrorActionPreference = $ErrorActionPreference
  $ErrorActionPreference = "Continue"
  $output = & flutter build web --profile `
    --dart-define=API_BASE_URL=$api `
    --dart-define=USE_REST_API=true `
    --dart-define=LOCK_API_BASE_URL=true `
    --dart-define=REVERB_ENABLED=true `
    --dart-define=REVERB_APP_KEY=arucad-local-key `
    --dart-define=REVERB_HOST=$ApiHost `
    --dart-define=REVERB_PORT=$ReverbPort `
    --dart-define=REVERB_SCHEME=http 2>&1
  $exitCode = $LASTEXITCODE
  $ErrorActionPreference = $previousErrorActionPreference
  $output |
    Select-String -Pattern "Built|Error|error|Exception|Wasm" |
    ForEach-Object { $_.Line }
  if ($exitCode -ne 0) {
    throw "Flutter web build failed with exit code $exitCode."
  }
}

Build-App

# Free the port if a previous run is still holding it.
Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
  Select-Object -ExpandProperty OwningProcess -Unique |
  ForEach-Object { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue }

$serve = Start-Process -PassThru -WindowStyle Hidden -FilePath "php" `
  -ArgumentList @("-S", "0.0.0.0:$Port", "-t", "build/web")

Start-Sleep -Seconds 1
$simulatorUrl = "http://localhost:$Port/simulator.html?apiHost=$ApiHost&apiPort=$ApiPort&reverbPort=$ReverbPort"
Start-Process $simulatorUrl

# Rebuild on save. A short settle window collapses the burst of events an
# editor emits for a single save into one build.
$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = Join-Path $root "lib"
$watcher.IncludeSubdirectories = $true
$watcher.Filter = "*.dart"
$watcher.EnableRaisingEvents = $true

Write-Host "Watching lib/ - save a file to rebuild. Ctrl+C to stop." -ForegroundColor Yellow

try {
  while ($true) {
    $change = $watcher.WaitForChanged([System.IO.WatcherChangeTypes]::All, 1000)
    if ($change.TimedOut) { continue }
    Start-Sleep -Milliseconds 400
    while (-not $watcher.WaitForChanged([System.IO.WatcherChangeTypes]::All, 300).TimedOut) { }
    Write-Host "[change] $($change.Name)" -ForegroundColor DarkGray
    Build-App
  }
} finally {
  if ($serve -and -not $serve.HasExited) { Stop-Process -Id $serve.Id -Force }
  $watcher.Dispose()
}
