<#
.SYNOPSIS
    Registers the Windows scheduled task that runs Laravel's scheduler.

.DESCRIPTION
    Four commands are defined in routes/console.php and none of them has
    ever run, because nothing invokes `php artisan schedule:run`. Laravel's
    scheduler is not a daemon: it decides what is due *when it is called*,
    so without a task calling it every minute the schedule is inert and
    silent. `schedule:list` still prints "Next Due: 44 minutes from now",
    which is what made this easy to miss.

    What has not been running:
      campus:sync-360-directory     hourly   360 tour directory sync
      stories:purge-expired         15 min   stories past 24 hours
      moderation:status             hourly   classifier health check
      moderation:purge-excerpts     03:30    retention of refused content

    The moderation health check is the one that matters most: with the
    classifier down, recall on unseen text falls from 55% to 31%, and this
    is the only thing that says so out loud.

    On Linux the equivalent is one crontab line, in MODERATION_RUNBOOK.md.

.PARAMETER TaskName
    Name to register. Defaults to ARUVERSE-Scheduler.

.PARAMETER Remove
    Unregister the task instead of creating it.

.EXAMPLE
    # From an elevated PowerShell prompt:
    .\scripts\install-scheduler.ps1

.EXAMPLE
    .\scripts\install-scheduler.ps1 -Remove
#>
[CmdletBinding()]
param(
    [string]$TaskName = 'ARUVERSE-Scheduler',
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'

# The backend directory, derived from this script's own location rather
# than from wherever it happens to be invoked.
$BackendPath = Split-Path -Parent $PSScriptRoot

if ($Remove) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Removed scheduled task '$TaskName'."
    } else {
        Write-Host "No scheduled task named '$TaskName'."
    }
    return
}

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    throw 'php was not found on PATH. Install PHP or run this with php on PATH.'
}

if (-not (Test-Path (Join-Path $BackendPath 'artisan'))) {
    throw "No artisan file at $BackendPath - this script must stay in backend/scripts/."
}

Write-Host "PHP:     $php"
Write-Host "Backend: $BackendPath"

# -WindowStyle Hidden so a console does not flash on screen every minute
# on a machine somebody is actually using.
$action = New-ScheduledTaskAction `
    -Execute $php `
    -Argument 'artisan schedule:run' `
    -WorkingDirectory $BackendPath

# Every minute forever. Laravel expects to be called this often and decides
# for itself what is actually due; calling it less often silently skips
# anything scheduled more finely than the interval.
# No -RepetitionDuration: omitting it means "repeat indefinitely". Passing
# [TimeSpan]::MaxValue looks like it should mean the same and does not —
# it serialises to P99999999DT23H59M59S, which Task Scheduler rejects as
# out of range, and the registration fails.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes 1)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Write-Host "Task '$TaskName' already exists; replacing it."
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description 'Runs the ARUVERSE Laravel scheduler once a minute.' | Out-Null

# Register-ScheduledTask can fail without stopping the script, and an
# install script that prints "Registered" when it registered nothing is
# worse than one that crashes: the scheduled commands stay silent either
# way, and this way nobody goes looking.
if (-not (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue)) {
    throw "Registration reported no error but '$TaskName' does not exist. Nothing is scheduled."
}

Write-Host ""
Write-Host "Registered '$TaskName', running every minute."
Write-Host ""
Write-Host "Verify with:"
Write-Host "    Get-ScheduledTask -TaskName '$TaskName'"
Write-Host "    Get-ScheduledTaskInfo -TaskName '$TaskName'   # LastRunTime, LastTaskResult"
Write-Host ""
Write-Host "LastTaskResult 0 means the last run succeeded. Confirm the schedule"
Write-Host "itself with:  php artisan schedule:list"
