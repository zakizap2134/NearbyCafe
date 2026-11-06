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

$targetZip = Join-Path $repoDir "venuevue-nearbycafe.zip"
if (Test-Path $targetZip) { Remove-Item -Force $targetZip }

Compress-Archive -Path "$staging\*" -DestinationPath $targetZip
Remove-Item -Recurse -Force $staging

Write-Host "Successfully packaged release archive: $targetZip" -ForegroundColor Green
Get-Item $targetZip | Select-Object Name, Length, LastWriteTime
