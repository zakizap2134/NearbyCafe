param(
    [switch]$Silent
)
$ErrorActionPreference = 'Stop'

$repoRoot = [IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
$rebuildDir = Join-Path $repoRoot 'rebuild'
$launcherPs1 = Join-Path $rebuildDir 'launcher\NearbyCafe.ps1'
$setupBat = Join-Path $PSScriptRoot 'One-Click-Setup.bat'
$iconFile = Join-Path $rebuildDir 'launcher\icons\nearby-cafe.ico'
$desktopDir = [Environment]::GetFolderPath('Desktop')

$brand = "Nearby Caf$([char]0x00E9)"
$psExe = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$psArgs = "-NoProfile -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$launcherPs1`""

# Remove any legacy mojibake shortcut on Desktop (e.g. "Nearby CafÃ©.lnk")
$staleNames = @(
    "Nearby Caf$([char]0x00C3)$([char]0x00A9).lnk",
    'Nearby CafAc.lnk'
)
foreach ($staleName in $staleNames) {
    $staleDesktopShortcut = Join-Path $desktopDir $staleName
    if (Test-Path -LiteralPath $staleDesktopShortcut) {
        Remove-Item -LiteralPath $staleDesktopShortcut -Force -ErrorAction SilentlyContinue
    }
}

$shell = New-Object -ComObject WScript.Shell

# 1. Desktop App Shortcut: "Nearby Café.lnk"
if (Test-Path -LiteralPath $desktopDir) {
    $deskApp = $shell.CreateShortcut((Join-Path $desktopDir "$brand.lnk"))
    $deskApp.TargetPath = $psExe
    $deskApp.Arguments = $psArgs
    $deskApp.WorkingDirectory = $rebuildDir
    if (Test-Path -LiteralPath $iconFile) {
        $deskApp.IconLocation = "$iconFile,0"
    }
    $deskApp.Description = "Start $brand POS Counter & Live Cloud Sync"
    $deskApp.Save()

    # 2. Desktop Workspace Folder Shortcut: "Nearby Café Folder.lnk"
    $deskFolder = $shell.CreateShortcut((Join-Path $desktopDir "$brand Folder.lnk"))
    $deskFolder.TargetPath = "$env:SystemRoot\explorer.exe"
    $deskFolder.Arguments = "`"$repoRoot`""
    $deskFolder.WorkingDirectory = $repoRoot
    $deskFolder.IconLocation = "$env:SystemRoot\System32\shell32.dll,4"
    $deskFolder.Description = "Open $brand Project Folder ($repoRoot)"
    $deskFolder.Save()
}

# 3. Project Root App Shortcut: "C:\Projects\NearbyCafe\Nearby Café.lnk"
$rootApp = $shell.CreateShortcut((Join-Path $repoRoot "$brand.lnk"))
$rootApp.TargetPath = $psExe
$rootApp.Arguments = $psArgs
$rootApp.WorkingDirectory = $rebuildDir
if (Test-Path -LiteralPath $iconFile) {
    $rootApp.IconLocation = "$iconFile,0"
}
$rootApp.Description = "Start $brand POS Counter & Live Cloud Sync"
$rootApp.Save()

# 4. Project Root Setup Shortcut: "C:\Projects\NearbyCafe\One-Click Setup.lnk"
$rootSetup = $shell.CreateShortcut((Join-Path $repoRoot 'One-Click Setup.lnk'))
$rootSetup.TargetPath = $setupBat
$rootSetup.WorkingDirectory = $PSScriptRoot
if (Test-Path -LiteralPath $iconFile) {
    $rootSetup.IconLocation = "$iconFile,0"
}
$rootSetup.Description = "Run 1-Click Automated Setup for $brand"
$rootSetup.Save()

if (-not $Silent) {
    Write-Host "[OK] Created '$brand' & '$brand Folder' shortcuts on Desktop and inside $repoRoot" -ForegroundColor Green
}
