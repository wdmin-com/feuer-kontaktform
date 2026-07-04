param(
    [Parameter(Mandatory = $true)]
    [string] $Version
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Update-TextFile {
    param(
        [string] $Path,
        [scriptblock] $Updater
    )

    $fullPath = Join-Path $root $Path
    $text = [System.IO.File]::ReadAllText($fullPath, $utf8NoBom)
    $updated = & $Updater $text
    if ($updated -ne $text) {
        [System.IO.File]::WriteAllText($fullPath, $updated, $utf8NoBom)
    }
}

Update-TextFile 'feuer-kontaktform.php' {
    param($text)
    $text = $text -replace 'Version:\s*[0-9.]+', "Version: $Version"
    $text -replace "FEUER_KONTAKTFORM_VERSION',\s*'[^']+'", "FEUER_KONTAKTFORM_VERSION', '$Version'"
}

Update-TextFile 'index.php' {
    param($text)
    $text -replace '\$version = ''[^'']+'';', "`$version = '$Version';"
}

Update-TextFile 'README.md' {
    param($text)
    $text -replace 'Version [0-9.]+ ist die aktuelle stabile Version', "Version $Version ist die aktuelle stabile Version"
}

Update-TextFile 'CHANGELOG.md' {
    param($text)
    if ($text -match "## \[$([regex]::Escape($Version))\]") {
        return $text
    }

    $entry = "## [$Version] - $(Get-Date -Format 'yyyy-MM-dd')`r`n`r`n- Release-Version synchronisiert.`r`n`r`n"
    $text -replace "(# Changelog\s*)", "`$1`r`n$entry"
}

$updatePath = Join-Path $root 'update.json'
$update = [System.IO.File]::ReadAllText($updatePath, $utf8NoBom) | ConvertFrom-Json
$update.version = $Version
$update.last_updated = Get-Date -Format 'yyyy-MM-dd'
$update.changelog = "Version ${Version}: Stabilitaets-, Sicherheits- und Betriebsverbesserungen."
$json = $update | ConvertTo-Json -Depth 8
[System.IO.File]::WriteAllText($updatePath, $json, $utf8NoBom)
