@echo off
setlocal
cd /d "%~dp0"
set "VV_PHP=C:\xampp\php\php.exe"
if not exist "%VV_PHP%" (
 echo PHP not found. Install XAMPP in C:\xampp or edit VV_PHP in this launcher.
 pause
 exit /b 1
)
"%VV_PHP%" tools\preflight.php --allow-owner-setup
if errorlevel 1 (
 echo Startup stopped. Resolve the preflight message above; see README.md.
 pause
 exit /b 1
)
where node >nul 2>&1
if errorlevel 1 (
 echo Node.js is missing. Install Node 22.12 or newer and run npm ci in this folder.
 pause
 exit /b 1
)
if not exist node_modules\vite\bin\vite.js (
 echo Dependencies are missing. Run npm ci in the rebuild folder first.
 pause
 exit /b 1
)
start "VenueVue rebuild API" /D "%~dp0" "%VV_PHP%" -S 127.0.0.1:8081 -t public tools\router.php
start "VenueVue rebuild frontend" /D "%~dp0" cmd /k npm run dev
echo Open http://localhost:5174 after Vite reports ready.
echo Tablets use http://YOUR-HOST-LAN-IP:5174 on the same Wi-Fi.
echo Close these two server windows to stop. MySQL stays under XAMPP control.
pause
