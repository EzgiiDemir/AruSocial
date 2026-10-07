param(
    [string] $Source = (Join-Path $PSScriptRoot '..\frontend\assets\images\ARUVERSE LOGO.png')
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$sourcePath = (Resolve-Path -LiteralPath $Source).Path
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$displayLogoPath = Join-Path $root 'frontend\assets\images\aruverse_mark.png'
$backendLogoPath = Join-Path $root 'backend\public\images\aruverse-logo.png'

# The approved master has a large transparent artboard. Keep that original
# untouched, but produce a centred square derivative with modest safety
# padding so the exact same artwork renders prominently in headers, avatars,
# favicons and launcher icons.
$masterImage = [System.Drawing.Bitmap]::FromFile($sourcePath)
try {
    $step = 4
    $minX = $masterImage.Width
    $minY = $masterImage.Height
    $maxX = -1
    $maxY = -1
    for ($y = 0; $y -lt $masterImage.Height; $y += $step) {
        for ($x = 0; $x -lt $masterImage.Width; $x += $step) {
            if ($masterImage.GetPixel($x, $y).A -gt 8) {
                if ($x -lt $minX) { $minX = $x }
                if ($x -gt $maxX) { $maxX = $x }
                if ($y -lt $minY) { $minY = $y }
                if ($y -gt $maxY) { $maxY = $y }
            }
        }
    }
    if ($maxX -lt 0 -or $maxY -lt 0) {
        throw 'The master ARUVERSE logo contains no visible pixels.'
    }

    $contentWidth = $maxX - $minX + $step
    $contentHeight = $maxY - $minY + $step
    $contentSide = [Math]::Max($contentWidth, $contentHeight)
    $padding = [Math]::Ceiling($contentSide * 0.08)
    $cropSide = [Math]::Min($contentSide + (2 * $padding), [Math]::Min($masterImage.Width, $masterImage.Height))
    $centerX = ($minX + $maxX) / 2
    $centerY = ($minY + $maxY) / 2
    $cropX = [Math]::Max(0, [Math]::Min($masterImage.Width - $cropSide, [Math]::Floor($centerX - ($cropSide / 2))))
    $cropY = [Math]::Max(0, [Math]::Min($masterImage.Height - $cropSide, [Math]::Floor($centerY - ($cropSide / 2))))

    $displayLogo = [System.Drawing.Bitmap]::new(1024, 1024, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $displayGraphics = [System.Drawing.Graphics]::FromImage($displayLogo)
    try {
        $displayGraphics.Clear([System.Drawing.Color]::Transparent)
        $displayGraphics.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
        $displayGraphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $displayGraphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
        $displayGraphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $destination = [System.Drawing.Rectangle]::new(0, 0, 1024, 1024)
        $sourceRectangle = [System.Drawing.Rectangle]::new($cropX, $cropY, $cropSide, $cropSide)
        $displayGraphics.DrawImage($masterImage, $destination, $sourceRectangle, [System.Drawing.GraphicsUnit]::Pixel)
        $displayLogo.Save($displayLogoPath, [System.Drawing.Imaging.ImageFormat]::Png)
    } finally {
        $displayGraphics.Dispose()
        $displayLogo.Dispose()
    }
} finally {
    $masterImage.Dispose()
}

Copy-Item -LiteralPath $displayLogoPath -Destination $backendLogoPath -Force
$sourceImage = [System.Drawing.Image]::FromFile($displayLogoPath)

function Write-BrandPng {
    param(
        [Parameter(Mandatory)] [string] $Path,
        [Parameter(Mandatory)] [int] $Width,
        [Parameter(Mandatory)] [int] $Height,
        [switch] $WhiteBackground
    )

    $pixelFormat = [System.Drawing.Imaging.PixelFormat]::Format32bppArgb
    $bitmap = [System.Drawing.Bitmap]::new($Width, $Height, $pixelFormat)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    try {
        if ($WhiteBackground) {
            $graphics.Clear([System.Drawing.Color]::White)
        } else {
            $graphics.Clear([System.Drawing.Color]::Transparent)
        }
        $graphics.CompositingMode = [System.Drawing.Drawing2D.CompositingMode]::SourceOver
        $graphics.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
        $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
        $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $graphics.DrawImage($sourceImage, 0, 0, $Width, $Height)
        $bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    } finally {
        $graphics.Dispose()
        $bitmap.Dispose()
    }
}

try {
    $webTargets = @{
        'frontend\web\favicon.png' = 32
        'frontend\web\icons\Icon-192.png' = 192
        'frontend\web\icons\Icon-512.png' = 512
        'frontend\web\icons\Icon-maskable-192.png' = 192
        'frontend\web\icons\Icon-maskable-512.png' = 512
    }
    foreach ($entry in $webTargets.GetEnumerator()) {
        Write-BrandPng -Path (Join-Path $root $entry.Key) -Width $entry.Value -Height $entry.Value
    }

    Get-ChildItem -LiteralPath (Join-Path $root 'frontend\android\app\src\main\res') -Recurse -File -Filter 'ic_launcher*.png' |
        ForEach-Object {
            $existing = [System.Drawing.Image]::FromFile($_.FullName)
            try {
                $width = $existing.Width
                $height = $existing.Height
            } finally {
                $existing.Dispose()
            }
            $isForeground = $_.BaseName -eq 'ic_launcher_foreground'
            Write-BrandPng -Path $_.FullName -Width $width -Height $height -WhiteBackground:(-not $isForeground)
        }

    Get-ChildItem -LiteralPath (Join-Path $root 'frontend\ios\Runner\Assets.xcassets\AppIcon.appiconset') -File -Filter '*.png' |
        ForEach-Object {
            $existing = [System.Drawing.Image]::FromFile($_.FullName)
            try {
                $width = $existing.Width
                $height = $existing.Height
            } finally {
                $existing.Dispose()
            }
            Write-BrandPng -Path $_.FullName -Width $width -Height $height -WhiteBackground
        }
} finally {
    $sourceImage.Dispose()
}

Write-Host 'ARUVERSE display mark plus web, Android, and iOS icons regenerated from the approved master logo.'
