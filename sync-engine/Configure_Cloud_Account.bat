@echo off
setlocal
title Nearby Cafe - Configure Cloud Target Account
cd /d "%~dp0"

set PHP_BIN=C:\xampp\php\php.exe
if not exist "%PHP_BIN%" (
    where php >nul 2>&1
    if %errorlevel% equ 0 (
        set PHP_BIN=php
    ) else (
        echo [ERROR] PHP was not found at C:\xampp\php\php.exe or in system PATH.
        pause
        exit /b 1
    )
)

"%PHP_BIN%" "%~dp0configure_cloud.php" %*

echo.
pause
