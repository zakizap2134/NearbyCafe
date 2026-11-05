@echo off
setlocal
title Nearby Cafe - New Device Quick Setup
cd /d "%~dp0"

echo ==========================================================
echo NEARBY CAFE - NEW DEVICE CONFIGURATION
echo ==========================================================
echo.

set PHP_BIN=C:\xampp\php\php.exe
if not exist "%PHP_BIN%" (
    where php >nul 2>&1
    if %errorlevel% equ 0 (
        set PHP_BIN=php
    ) else (
        echo [ERROR] PHP was not found at C:\xampp\php\php.exe or in system PATH.
        echo Please ensure XAMPP is installed at C:\xampp.
        pause
        exit /b 1
    )
)

"%PHP_BIN%" "%~dp0setup_device.php"

echo.
pause
