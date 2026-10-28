@echo off
rem Read-only check of the host side of the shop network. Changes nothing.
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\launcher\NetworkCheck.ps1"
echo.
echo Share a photo of this window with your developer if a FAIL line remains.
pause
