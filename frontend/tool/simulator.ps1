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
  [int]$Port = 5599
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# Default to this machine's LAN address so the simulated app talks to the
# same backend a real phone would, rather than a loopback that only works
# on this computer.
if (-not $ApiHost) {
  $ApiHost = (Get-NetIPAddress -AddressFamily IPv4 |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
    Select-Object -First 1 -ExpandProperty IPAddress)
}
if (-not $ApiHost) { $ApiHost = "127.0.0.1" }

$api = "http://${ApiHost}:${ApiPort}/api/v1"
Write-Host "API      : $api" -ForegroundColor Cyan
Write-Host "Simulator: http://localhost:$Port/simulator.html" -ForegroundColor Green

function Build-App {
  Write-Host "[build] compiling..." -ForegroundColor DarkGray
  & flutter build web --profile `
    --dart-define=API_BASE_URL=$api `
    --dart-define=USE_REST_API=true 2>&1 |
    Select-String -Pattern "Built|Error|error|Exception" | ForEach-Object { $_.Line }
}

Build-App

# Free the port if a previous run is still holding it.
Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
  Select-Object -ExpandProperty OwningProcess -Unique |
  ForEach-Object { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue }

$serve = Start-Process -PassThru -WindowStyle Hidden -FilePath "php" `
  -ArgumentList @("-S", "0.0.0.0:$Port", "-t", "build/web")

Start-Sleep -Seconds 1
Start-Process "http://localhost:$Port/simulator.html"

# Rebuild on save. A short settle window collapses the burst of events an
# editor emits for a single save into one build.
$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = Join-Path $root "lib"
$watcher.IncludeSubdirectories = $true
$watcher.Filter = "*.dart"
$watcher.EnableRaisingEvents = $true

Write-Host "Watching lib/ — save a file to rebuild. Ctrl+C to stop." -ForegroundColor Yellow

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
