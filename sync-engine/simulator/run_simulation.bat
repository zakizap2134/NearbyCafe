@echo off
title VenueVue - Network Outage Simulation Test
cd /d "%~dp0"

echo ==============================================================================
echo VenueVue: Network Outage and Idempotency Verification Test
echo ==============================================================================
echo.

python test_outage_simulation.py

echo.
echo Test execution complete.
pause
