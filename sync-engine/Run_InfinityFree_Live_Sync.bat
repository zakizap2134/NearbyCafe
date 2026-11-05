@echo off
title VenueVue · Live InfinityFree Cloud Sync Bridge
cd /d "%~dp0"

echo ==============================================================================
echo VenueVue: Live Store-and-Forward Sync Bridge (InfinityFree ^<--^> Local)
echo Shop: Nearby Cafe (Legaspi St., Intramuros)
echo Cloud Target: https://nearbycafe.infinityfreeapp.com
echo ==============================================================================
echo.

node "local_daemon\infinityfree_sync_bridge.mjs"

pause
