param(
    [ValidateSet('staging', 'production')]
    [string]$Environment = 'production',
    [string]$ApiBaseUrl = $env:API_BASE_URL,
    [string]$ReverbHost = $env:REVERB_HOST,
    [string]$ReverbAppKey = $env:REVERB_APP_KEY,
    [ValidateSet('apk', 'appbundle')]
    [string]$Format = 'apk'
)

$ErrorActionPreference = 'Stop'

# Keep a release build reproducible on developer/CI machines without asking a
# student to enter a host, port, Flutter path or JDK path. Prefer an existing
# PATH installation, then common project/user locations. Android Studio's JBR
# is a valid JDK 17 source for the Android Gradle plugin.
$flutterExecutable = (Get-Command flutter -ErrorAction SilentlyContinue).Source
if ([string]::IsNullOrWhiteSpace($flutterExecutable)) {
    $flutterCandidates = @(
        if ($env:FLUTTER_ROOT) { Join-Path $env:FLUTTER_ROOT 'bin\\flutter.bat' }
        (Join-Path $env:USERPROFILE 'develop\\flutter\\bin\\flutter.bat')
        'C:\\flutter\\bin\\flutter.bat'
    ) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }
    $flutterExecutable = $flutterCandidates | Select-Object -First 1
}
if ([string]::IsNullOrWhiteSpace($flutterExecutable)) {
    throw 'Flutter SDK was not found. Install Flutter or set FLUTTER_ROOT before running this build script.'
}

$javaExecutable = if ($env:JAVA_HOME) { Join-Path $env:JAVA_HOME 'bin\\java.exe' } else { $null }
if (-not $javaExecutable -or -not (Test-Path -LiteralPath $javaExecutable)) {
    $javaCandidates = @(
        if ($env:ANDROID_STUDIO_HOME) { Join-Path $env:ANDROID_STUDIO_HOME 'jbr' }
        'C:\\Program Files\\Android\\Android Studio\\jbr'
    ) | Where-Object { $_ -and (Test-Path -LiteralPath (Join-Path $_ 'bin\\java.exe')) }
    $javaHome = $javaCandidates | Select-Object -First 1
    if ($javaHome) {
        $env:JAVA_HOME = $javaHome
        $env:PATH = "$(Join-Path $javaHome 'bin');$env:PATH"
    }
}

if ([string]::IsNullOrWhiteSpace($ApiBaseUrl)) {
    throw 'API_BASE_URL is required. Use the public HTTPS API URL, e.g. https://api.example.edu/api/v1.'
}

$uri = [Uri]$ApiBaseUrl
if ($uri.Scheme -ne 'https' -or $uri.Host -in @('localhost', '127.0.0.1', '0.0.0.0', '10.0.2.2')) {
    throw 'Release builds require a public HTTPS API_BASE_URL; a loopback/LAN URL cannot be embedded in an APK.'
}

$flutterRoot = Join-Path $PSScriptRoot '..\..\frontend'
$arguments = @(
    'build', $Format, '--release',
    '--dart-define=USE_REST_API=true',
    "--dart-define=APP_ENV=$Environment",
    "--dart-define=API_BASE_URL=$($uri.AbsoluteUri.TrimEnd('/'))"
)
if (-not [string]::IsNullOrWhiteSpace($ReverbHost)) {
    $arguments += "--dart-define=REVERB_HOST=$ReverbHost"
    $arguments += '--dart-define=REVERB_ENABLED=true'
}
if (-not [string]::IsNullOrWhiteSpace($ReverbAppKey)) { $arguments += "--dart-define=REVERB_APP_KEY=$ReverbAppKey" }

Push-Location $flutterRoot
try {
    & $flutterExecutable @arguments
    if ($LASTEXITCODE -ne 0) { throw "flutter build failed ($LASTEXITCODE)." }
} finally {
    Pop-Location
}
