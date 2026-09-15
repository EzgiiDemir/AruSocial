# Registers the moderation classifier as a Windows scheduled task so it
# starts on boot and stays started.
#
# run.ps1 already restarts the service when it exits, so the gap this
# closes is narrower and more boring than "supervision": that loop only
# lives as long as somebody's console window. Reboot the machine, or log
# off, and the classifier is gone with no sign that anything happened.
#
# That matters more than it sounds. With the classifier down, recall on
# unseen text drops from about 55% to 31% and media uploads fail closed,
# so students see uploads refused while moderators see nothing wrong. The
# hourly `moderation:status` check is what reports it, and that only runs
# if the Laravel scheduler is installed too (backend/scripts/install-scheduler.ps1).
#
#   .\install-service.ps1            register and start it
#   .\install-service.ps1 -Remove    unregister
#
# ASCII only, like run.ps1: Windows PowerShell 5.1 reads .ps1 as ANSI
# unless the file carries a BOM, and a stray dash corrupts the parse.

[CmdletBinding()]
param(
    [string]$TaskName = 'ARUVERSE-Moderation',
    [int]$Port = 8801,
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'

if ($Remove) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Removed scheduled task '$TaskName'."
    } else {
        Write-Host "No scheduled task named '$TaskName'."
    }
    return
}

# supervise.ps1, not run.ps1: the task fires on a timer and knows nothing
# about what is already running, so the thing it runs has to check first.
# Pointing it straight at run.ps1 launched a second uvicorn on every tick,
# which could not bind the port and then retried every five seconds.
$runner = Join-Path $PSScriptRoot 'supervise.ps1'
if (-not (Test-Path $runner)) {
    throw "supervise.ps1 not found next to this script."
}
if (-not (Test-Path (Join-Path $PSScriptRoot 'run.ps1'))) {
    throw "run.ps1 not found next to this script."
}

$venv = Join-Path $PSScriptRoot '.venv\Scripts\python.exe'
if (-not (Test-Path $venv)) {
    throw "No virtualenv at $venv. Create it first: python -m venv .venv"
}

Write-Host "Runner: $runner"
Write-Host "Port:   $Port"

# -WindowStyle Hidden and -NonInteractive: this runs unattended, and a
# console appearing on every boot is noise nobody will thank us for.
$action = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument "-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$runner`" -Port $Port" `
    -WorkingDirectory $PSScriptRoot

# Checked every few minutes rather than hooked to boot or logon.
#
# -AtStartup and -AtLogOn both need administrator rights, and an install
# that demands a UAC prompt is an install that quietly does not happen.
# A repeating trigger needs none, and combined with MultipleInstances
# IgnoreNew below it is genuinely better supervision than either: if the
# service is already running the tick does nothing, and if it has died --
# crashed, killed, wedged, or never started after a reboot -- the next
# tick brings it back. run.ps1's own restart loop covers a clean exit;
# this covers the loop itself going away.
$elevated = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

$triggers = @(
    New-ScheduledTaskTrigger -Once -At (Get-Date) `
        -RepetitionInterval (New-TimeSpan -Minutes 5)
)

# No ExecutionTimeLimit: this is a long-running service, and the default
# three days would kill it. RestartOnFailure covers the case run.ps1's own
# loop cannot -- the whole PowerShell host dying.
# IgnoreNew is what turns a repeating trigger into supervision: a tick
# that arrives while the service is up is discarded instead of starting
# a second copy fighting for port 8801.
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Write-Host "Task '$TaskName' already exists; replacing it."
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $triggers `
    -Settings $settings `
    -Description 'Keeps the ARUVERSE moderation classifier running on port 8801.' | Out-Null

# Register-ScheduledTask can fail without stopping the script. An install
# script that says "registered" when it registered nothing leaves the
# exact silence it was written to prevent.
if (-not (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue)) {
    throw "Registration reported no error but '$TaskName' does not exist."
}

Write-Host ""
Write-Host "Registered '$TaskName', checked every 5 minutes."
Write-Host ""
Write-Host "Check it is actually answering:"
Write-Host "    Invoke-WebRequest http://127.0.0.1:$Port/health -UseBasicParsing"
Write-Host ""
if (-not $elevated) {
    Write-Host "Registered without administrator rights, so it starts with"
    Write-Host "your session rather than at boot. For a server that must serve"
    Write-Host "before anyone signs in, re-run this from an admin prompt and"
    Write-Host "register it to run as SYSTEM."
}
