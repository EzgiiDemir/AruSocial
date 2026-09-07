param(
    [Parameter(Mandatory = $true)]
    [string]$ApiBaseUrl,
    [string]$ReverbHost,
    [int]$ReverbPort = 443,
    [string]$OsrmBaseUrl,
    [int]$TimeoutSeconds = 15
)

$ErrorActionPreference = 'Stop'

function Assert-PublicHttpsUrl([string]$Value, [string]$Name) {
    $uri = [Uri]$Value
    if ($uri.Scheme -ne 'https' -or $uri.Host -in @('localhost', '127.0.0.1', '0.0.0.0', '10.0.2.2')) {
        throw "$Name must be a public HTTPS URL; loopback and LAN development URLs are not release targets."
    }
    return $uri
}

function Test-TcpEndpoint([string]$HostName, [int]$Port, [string]$Label) {
    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $task = $client.ConnectAsync($HostName, $Port)
        if (-not $task.Wait([TimeSpan]::FromSeconds($TimeoutSeconds))) {
            throw "$Label timed out after $TimeoutSeconds seconds."
        }
        if (-not $client.Connected) {
            throw "$Label is not reachable."
        }
        Write-Host "PASS  $Label ($HostName`:$Port)" -ForegroundColor Green
    } finally {
        $client.Dispose()
    }
}

function Test-JsonEndpoint([Uri]$BaseUri, [string]$Path) {
    $target = "$($BaseUri.AbsoluteUri.TrimEnd('/'))/$Path"
    try {
        $response = Invoke-WebRequest -Uri $target -TimeoutSec $TimeoutSeconds -Headers @{ Accept = 'application/json' } -UseBasicParsing
    } catch {
        throw "GET $target failed: $($_.Exception.Message)"
    }
    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 300) {
        throw "GET $target returned HTTP $($response.StatusCode)."
    }
    try {
        $null = $response.Content | ConvertFrom-Json -ErrorAction Stop
    } catch {
        throw "GET $target did not return JSON."
    }
    Write-Host "PASS  GET /$Path returns JSON" -ForegroundColor Green
}

$api = Assert-PublicHttpsUrl $ApiBaseUrl 'ApiBaseUrl'
Write-Host "Checking release target $($api.AbsoluteUri.TrimEnd('/'))" -ForegroundColor Cyan

$apiPort = if ($api.IsDefaultPort) { 443 } else { $api.Port }
Test-TcpEndpoint $api.Host $apiPort 'API TLS endpoint'
Test-JsonEndpoint $api 'events'
Test-JsonEndpoint $api 'places'

if (-not [string]::IsNullOrWhiteSpace($ReverbHost)) {
    if ($ReverbHost -match '://') {
        $reverb = Assert-PublicHttpsUrl $ReverbHost 'ReverbHost'
        Test-TcpEndpoint $reverb.Host $ReverbPort 'Reverb endpoint'
    } else {
        if ($ReverbHost -in @('localhost', '127.0.0.1', '0.0.0.0', '10.0.2.2')) {
            throw 'ReverbHost must not be a loopback host for a release target.'
        }
        Test-TcpEndpoint $ReverbHost $ReverbPort 'Reverb endpoint'
    }
} else {
    Write-Warning 'SKIP  ReverbHost was not supplied; websocket reachability was not checked.'
}

if (-not [string]::IsNullOrWhiteSpace($OsrmBaseUrl)) {
    $osrm = Assert-PublicHttpsUrl $OsrmBaseUrl 'OsrmBaseUrl'
    # Two campus-area coordinates; this verifies a genuine OSRM-compatible
    # route response without involving a user's live location.
    $route = "$($osrm.AbsoluteUri.TrimEnd('/'))/route/v1/driving/33.3193,35.3402;33.3210,35.3425?overview=false"
    try {
        $response = Invoke-WebRequest -Uri $route -TimeoutSec $TimeoutSeconds -Headers @{ Accept = 'application/json' } -UseBasicParsing
        $body = $response.Content | ConvertFrom-Json -ErrorAction Stop
        if ($body.code -ne 'Ok' -or -not $body.routes) { throw 'OSRM returned no route.' }
        Write-Host 'PASS  OSRM route endpoint returns a route' -ForegroundColor Green
    } catch {
        throw "OSRM route verification failed: $($_.Exception.Message)"
    }
} else {
    Write-Warning 'SKIP  OsrmBaseUrl was not supplied; routing was not checked.'
}

Write-Host 'Release endpoint verification passed.' -ForegroundColor Green
