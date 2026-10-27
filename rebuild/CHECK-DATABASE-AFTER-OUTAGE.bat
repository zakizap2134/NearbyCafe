@echo off
setlocal
cd /d "%~dp0"
rem Read-only database health check after a power cut or forced shutdown.
rem It never repairs, deletes, reinstalls or force-starts anything.
set "VV_PHP=C:\xampp\php\php.exe"
set "VV_DATA=C:\xampp\mysql\data"
if not exist "%VV_PHP%" (
 echo XAMPP PHP was not found at C:\xampp\php\php.exe.
 echo Ask the host administrator to set VV_PHP in this file to the installed PHP path.
 echo No check has been run.
 pause
 exit /b 1
)
echo ============================================================
echo  Nearby Cafe - database check after a power cut
echo ============================================================
echo 1. Start Nearby Cafe on THIS host first, using the desktop shortcut.
echo    Starting the database replays its own safety log automatically.
echo 2. Ask baristas NOT to take orders until this check says HEALTHY.
echo 3. Do NOT delete ib_logfile, ibdata or any file in the mysql folder.
echo.
echo Close this window to cancel, or press a key to run the read-only check.
pause >nul
echo.
echo --- Recent database start-up messages -----------------------
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -Command "$d=$env:VV_DATA;$c='config\launcher.local.json';if(Test-Path $c){try{$d=(Get-Content $c -Raw|ConvertFrom-Json).DataDirectory}catch{}};$log=Join-Path $d 'mysql_error.log';if(-not(Test-Path $log)){'INFO database log not found at '+$log;exit 0};$hits=@(Get-Content $log -Tail 300|Select-String -Pattern 'crash recovery','Starting final batch','corrupt','\[ERROR\]','Assertion','force_recovery');if($hits.Count){$hits|Select-Object -Last 15|ForEach-Object{$_.Line}}else{'INFO no crash-recovery or error lines in the last 300 log lines.'}"
echo.
echo --- Integrity check ------------------------------------------
"%VV_PHP%" "tools\integrity-check.php"
set "VV_RESULT=%errorlevel%"
echo.
if "%VV_RESULT%"=="0" (
 echo HEALTHY. Check each tablet's Pending orders, then reopen the register.
 pause
 exit /b 0
)
if "%VV_RESULT%"=="2" (
 echo ATTENTION. Do not take orders. Do not clear tablet browser data.
 echo Take a photo of this window and contact your developer.
 echo See BACKUP_RESTORE.md, section "After a power cut".
 pause
 exit /b 2
)
echo The check could not run. Is Nearby Cafe started on this host?
echo Read the FAIL line above and BACKUP_RESTORE.md, section "After a power cut".
pause
exit /b 1
