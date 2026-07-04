param(
    [string] $Version = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$pluginSlug = 'feuer-kontaktform'
$mainFile = Join-Path $root 'feuer-kontaktform.php'

if ($Version -eq '') {
    $main = Get-Content -LiteralPath $mainFile -Raw
    if ($main -notmatch "FEUER_KONTAKTFORM_VERSION',\s*'([^']+)'") {
        throw 'Version was not provided and FEUER_KONTAKTFORM_VERSION was not found.'
    }

    $Version = $Matches[1]
} else {
    & (Join-Path $PSScriptRoot 'sync-version.ps1') -Version $Version
}

function Assert-InsideRoot {
    param(
        [string] $Path,
        [string] $RootPath
    )

    $resolved = [System.IO.Path]::GetFullPath($Path)
    $resolvedRoot = [System.IO.Path]::GetFullPath($RootPath).TrimEnd('\') + '\'

    if (-not $resolved.StartsWith($resolvedRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to touch path outside workspace: $resolved"
    }

    return $resolved
}

function New-CleanDirectory {
    param([string] $Path)

    $resolved = Assert-InsideRoot -Path $Path -RootPath $root
    if (Test-Path -LiteralPath $resolved) {
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }

    New-Item -ItemType Directory -Path $resolved -Force | Out-Null
    return $resolved
}

function Get-RelativeArchivePath {
    param([string] $Path)

    $rootPath = [System.IO.Path]::GetFullPath($root).TrimEnd('\') + '\'
    $filePath = [System.IO.Path]::GetFullPath($Path)
    $rootUri = [System.Uri]::new($rootPath)
    $fileUri = [System.Uri]::new($filePath)

    return [System.Uri]::UnescapeDataString($rootUri.MakeRelativeUri($fileUri).ToString()).Replace('\', '/')
}

function Should-Exclude {
    param([System.IO.FileInfo] $File)

    $relative = Get-RelativeArchivePath -Path $File.FullName
    $segments = $relative.Split('/')

    foreach ($segment in $segments) {
        if ($segment -in @('.git', '.idea', '.vscode', 'node_modules', 'vendor', 'release', 'Version', 'tools', 'config', 'docs', 'templates')) {
            return $true
        }
    }

    if ($File.Name -in @('.DS_Store', 'Thumbs.db', '.gitignore', '.gitattributes', 'composer.json', 'composer.lock', 'phpcs.xml.dist', 'phpstan.neon.dist', 'contactform.txt', "$pluginSlug.zip")) {
        return $true
    }

    if ($relative.StartsWith("$pluginSlug/", [System.StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    if ($relative.StartsWith('includes/', [System.StringComparison]::OrdinalIgnoreCase) -and
        -not $relative.StartsWith('includes/support/', [System.StringComparison]::OrdinalIgnoreCase) -and
        $relative -ne 'includes/class-feuer-kontaktform-updater.php') {
        return $true
    }

    if ($relative.StartsWith('assets/css/', [System.StringComparison]::OrdinalIgnoreCase) -and
        $File.Name -notin @('support-admin.css', 'support-frontend.css')) {
        return $true
    }

    if ($relative.StartsWith('assets/js/', [System.StringComparison]::OrdinalIgnoreCase) -and
        $File.Name -notin @('support-admin.js', 'support-frontend.js')) {
        return $true
    }

    return $false
}

$releaseRoot = Join-Path $root 'release'
$versionDir = New-CleanDirectory -Path (Join-Path $releaseRoot $Version)
$latestDir = New-CleanDirectory -Path (Join-Path $releaseRoot 'latest')

$versionZip = Join-Path $versionDir "$pluginSlug.zip"
$latestZip = Join-Path $latestDir "$pluginSlug.zip"

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$files = Get-ChildItem -LiteralPath $root -Recurse -File -Force |
    Where-Object { -not (Should-Exclude -File $_) } |
    Sort-Object FullName

$fixedTimestamp = [System.DateTimeOffset]::new(2026, 1, 1, 0, 0, 0, [System.TimeSpan]::Zero)

function Write-CleanZip {
    param(
        [string] $TargetZip,
        [object[]] $Files
    )

    $target = Assert-InsideRoot -Path $TargetZip -RootPath $root
    if (Test-Path -LiteralPath $target) {
        Remove-Item -LiteralPath $target -Force
    }

    $stream = [System.IO.File]::Open($target, [System.IO.FileMode]::CreateNew)
    try {
        $zip = [System.IO.Compression.ZipArchive]::new($stream, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            foreach ($file in $Files) {
                $relative = Get-RelativeArchivePath -Path $file.FullName
                $entryName = "$pluginSlug/$relative"
                $entry = $zip.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
                $entry.LastWriteTime = $fixedTimestamp
                $entry.ExternalAttributes = 0

                $entryStream = $entry.Open()
                try {
                    $sourceStream = [System.IO.File]::OpenRead($file.FullName)
                    try {
                        $sourceStream.CopyTo($entryStream)
                    } finally {
                        $sourceStream.Dispose()
                    }
                } finally {
                    $entryStream.Dispose()
                }
            }
        } finally {
            $zip.Dispose()
        }
    } finally {
        $stream.Dispose()
    }
}

function Test-CleanZip {
    param([string] $TargetZip)

    $zip = [System.IO.Compression.ZipFile]::OpenRead($TargetZip)
    try {
        foreach ($entry in $zip.Entries) {
            if ($entry.FullName.EndsWith('/')) {
                throw "ZIP contains directory entry: $($entry.FullName)"
            }

            if ($entry.FullName.Contains('\')) {
                throw "ZIP contains backslash path: $($entry.FullName)"
            }

            if (-not $entry.FullName.StartsWith("$pluginSlug/", [System.StringComparison]::Ordinal)) {
                throw "ZIP entry is outside plugin directory: $($entry.FullName)"
            }

            if ($entry.FullName.Contains('../') -or $entry.FullName.StartsWith('/')) {
                throw "ZIP contains unsafe path: $($entry.FullName)"
            }

            if ($entry.ExternalAttributes -ne 0) {
                throw "ZIP entry has external attributes: $($entry.FullName)"
            }
        }
    } finally {
        $zip.Dispose()
    }
}

Write-CleanZip -TargetZip $versionZip -Files $files
Write-CleanZip -TargetZip $latestZip -Files $files
Test-CleanZip -TargetZip $versionZip
Test-CleanZip -TargetZip $latestZip

[PSCustomObject]@{
    Version = $Version
    Files = $files.Count
    VersionZip = $versionZip
    LatestZip = $latestZip
}
