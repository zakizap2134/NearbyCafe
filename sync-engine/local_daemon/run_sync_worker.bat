@echo off
title VenueVue - Local Store-and-Forward Sync Daemon
cd /d "%~dp0"

echo ==============================================================================
echo VenueVue: Local Shop Synchronization Daemon (Nearby Cafe)
echo Architecture: Local-Authoritative Store-and-Forward
echo ==============================================================================
echo.

if exist "C:\xampp\php\php.exe" (
    set PHP_EXE=C:\xampp\php\php.exe
) else (
    set PHP_EXE=php
)

echo Starting sync daemon with continuous monitoring...
echo Press Ctrl+C at any time to stop.
echo.

"%PHP_EXE%" sync_worker.php

pause
