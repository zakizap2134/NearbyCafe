<#
.SYNOPSIS
    Deploy updated Nearby Café build assets and backend PHP endpoints to InfinityFree Cloud.
.DESCRIPTION
    Uploads the production Vite bundle (HTML, JS, CSS, logo) and updated PHP API endpoints
    directly to the InfinityFree htdocs web root via secure FTP without overwriting local database configs.
.EXAMPLE
    powershell -File .\deploy_to_cloud.ps1
#>

param(
    [string]$FtpUser = "if0_43331399",
    [string]$FtpPass = "callmepapaton2x",
    [string]$FtpHost = "ftp://ftpupload.net"
)

$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rebuildDir = Resolve-Path (Join-Path $scriptDir "..")

function Upload-FtpFile($localPath, $remoteRelativePath) {
    $uri = "$FtpHost/$remoteRelativePath"
    Write-Host "Uploading $localPath -> $uri ..." -ForegroundColor Cyan
    
    $request = [System.Net.FtpWebRequest]::Create($uri)
    $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $request.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
    $request.UseBinary = $true
    $request.UsePassive = $true
    $request.KeepAlive = $false
    
    $fileBytes = [System.IO.File]::ReadAllBytes($localPath)
    $request.ContentLength = $fileBytes.Length
    
    $requestStream = $request.GetRequestStream()
    $requestStream.Write($fileBytes, 0, $fileBytes.Length)
    $requestStream.Close()
    
    $response = $request.GetResponse()
    Write-Host "  Success: $($response.StatusDescription.Trim())" -ForegroundColor Green
    $response.Close()
}

# Read current assets referenced in index.html
$indexHtmlPath = "$rebuildDir\public\index.html"
if (-not (Test-Path -LiteralPath $indexHtmlPath)) {
    throw "index.html not found at $indexHtmlPath. Run 'npm run build' first."
}

$indexContent = Get-Content -Path $indexHtmlPath -Raw
$assetMatches = [regex]::Matches($indexContent, '(?:src|href)="\./assets/([^"]+)"')
foreach ($m in $assetMatches) {
    $relAsset = $m.Groups[1].Value
    if (-not (Test-Path -LiteralPath "$rebuildDir\public\assets\$relAsset")) {
        throw "Referenced build asset not found at $rebuildDir\public\assets\$relAsset. Run 'npm run build' first."
    }
}

$filesToUpload = @(
    @{ Local = $indexHtmlPath; Remote = "htdocs/index.html" }
)

if (Test-Path -LiteralPath "$rebuildDir\public\.htaccess") {
    $filesToUpload += @{ Local = "$rebuildDir\public\.htaccess"; Remote = "htdocs/.htaccess" }
}

if (Test-Path -LiteralPath "$rebuildDir\public\assets") {
    Get-ChildItem -Path "$rebuildDir\public\assets" -File | ForEach-Object {
        $filesToUpload += @{ Local = $_.FullName; Remote = "htdocs/assets/$($_.Name)" }
    }
}

if (Test-Path -LiteralPath "$rebuildDir\app") {
    Get-ChildItem -Path "$rebuildDir\app" -File | ForEach-Object {
        $filesToUpload += @{ Local = $_.FullName; Remote = "htdocs/app/$($_.Name)" }
    }
}

if (Test-Path -LiteralPath "$rebuildDir\public\api") {
    Get-ChildItem -Path "$rebuildDir\public\api" -File | ForEach-Object {
        $filesToUpload += @{ Local = $_.FullName; Remote = "htdocs/api/$($_.Name)" }
    }
}

# Upload product catalogue images (e.g. Espresso, etc.)
$productsDir = "$rebuildDir\storage\uploads\products"
if (Test-Path -LiteralPath $productsDir) {
    Get-ChildItem -Path $productsDir -File | ForEach-Object {
        $filesToUpload += @{ Local = $_.FullName; Remote = "htdocs/storage/uploads/products/$($_.Name)" }
    }
}

Write-Host "Starting deployment to Nearby Café InfinityFree Cloud..." -ForegroundColor Yellow
foreach ($item in $filesToUpload) {
    if (Test-Path -LiteralPath $item.Local) {
        Upload-FtpFile $item.Local $item.Remote
    } else {
        Write-Warning "File not found locally: $($item.Local)"
    }
}
Write-Host "All changes successfully uploaded and live at https://nearbycafe.infinityfreeapp.com !" -ForegroundColor Green
