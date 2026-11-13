@echo off
setlocal
title Nearby Cafe - New Device Quick Setup
cd /d "%~dp0"

echo ==========================================================
echo NEARBY CAFE - NEW DEVICE CONFIGURATION
echo ==========================================================
echo.

set PHP_BIN=
for %%P in ("C:\xampp\php\php.exe" "D:\xampp\php\php.exe" "C:\Program Files\xampp\php\php.exe") do (
    if not defined PHP_BIN if exist %%~P set PHP_BIN=%%~P
)
if not defined PHP_BIN (
    where php >nul 2>&1
    if %errorlevel% equ 0 (
        set PHP_BIN=php
    ) else (
        echo [ERROR] PHP was not found in XAMPP folders or in system PATH.
        echo Please ensure XAMPP is installed.
        pause
        exit /b 1
    )
)

"%PHP_BIN%" "%~dp0setup_device.php"

echo.
pause
