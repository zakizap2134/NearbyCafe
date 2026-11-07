$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoDir = Resolve-Path (Join-Path $scriptDir "..\..")
$rebuildDir = Resolve-Path (Join-Path $scriptDir "..")

$staging = Join-Path $env:TEMP "venuevue_cloud_pkg"
if (Test-Path $staging) { Remove-Item -Recurse -Force $staging }
New-Item -ItemType Directory -Path $staging | Out-Null

Copy-Item -Path "$rebuildDir\public\*" -Destination $staging -Recurse
New-Item -ItemType Directory -Path "$staging\app" | Out-Null
Copy-Item -Path "$rebuildDir\app\*" -Destination "$staging\app" -Recurse

$productsDir = "$rebuildDir\storage\uploads\products"
if (Test-Path $productsDir) {
    New-Item -ItemType Directory -Path "$staging\storage\uploads\products" -Force | Out-Null
    Copy-Item -Path "$productsDir\*" -Destination "$staging\storage\uploads\products" -Recurse -Force
}

$releasesDir = Join-Path $repoDir "releases"
if (-not (Test-Path $releasesDir)) { New-Item -ItemType Directory -Path $releasesDir -Force | Out-Null }
$targetZip = Join-Path $releasesDir "venuevue-nearbycafe.zip"
if (Test-Path $targetZip) { Remove-Item -Force $targetZip }

Compress-Archive -Path "$staging\*" -DestinationPath $targetZip
Remove-Item -Recurse -Force $staging

Write-Host "Successfully packaged release archive: $targetZip" -ForegroundColor Green
Get-Item $targetZip | Select-Object Name, Length, LastWriteTime
