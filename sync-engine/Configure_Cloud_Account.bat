@echo off
setlocal
title Nearby Cafe - Configure Cloud Target Account
cd /d "%~dp0"

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
        pause
        exit /b 1
    )
)

"%PHP_BIN%" "%~dp0configure_cloud.php" %*

echo.
pause
