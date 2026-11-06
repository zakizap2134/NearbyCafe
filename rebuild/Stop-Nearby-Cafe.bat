@echo off
rem 1. Gracefully stop Nearby Cafe through its launcher (also triggers Stop-NearbySync)
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -STA -ExecutionPolicy Bypass -File "%~dp0launcher\NearbyCafe.ps1" -Stop

rem 2. Ensure any lingering sync bridge daemon process is stopped
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"CommandLine LIKE '%%infinityfree_sync_bridge.mjs%%'\" -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }" >nul 2>&1

rem 3. Ensure any dedicated Nearby Cafe database service is stopped so XAMPP port 3306 is freed
for /f "tokens=*" %%s in ('powershell -NoProfile -Command "Get-Service -Name 'NearbyCafeDatabase*' -ErrorAction SilentlyContinue | Where-Object { $_.Status -eq 'Running' } | Select-Object -ExpandProperty Name"') do (
    net stop "%%s" >nul 2>&1
)

echo Nearby Cafe and its database have stopped. Port 3306 is free.
