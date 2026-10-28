@echo off
rem Ensure any dedicated Nearby Cafe database service is started before launching the stack
for /f "tokens=*" %%s in ('powershell -NoProfile -Command "Get-Service -Name 'NearbyCafeDatabase*' -ErrorAction SilentlyContinue | Where-Object { $_.Status -eq 'Stopped' } | Select-Object -ExpandProperty Name"') do (
    net start "%%s" >nul 2>&1
)
start "" "%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File "%~dp0launcher\NearbyCafe.ps1"