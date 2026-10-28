@echo off
setlocal
cd /d "%~dp0..\.."
set "VV_PHP=C:\xampp\php\php.exe"
if not exist "%VV_PHP%" (
 echo XAMPP PHP was not found at C:\xampp\php\php.exe.
 echo Ask the host administrator to set VV_PHP in this launcher to the installed PHP path.
 echo No recovery action has been run.
 pause
 exit /b 1
)
echo Reset your Owner password using the current offline recovery code.
echo You need your owner username and code. Enter the new password twice.
echo Start MySQL in XAMPP before continuing.
echo Console input is visible. Use this privately and do not share screenshots.
echo Close this window now to cancel, or press a key to continue.
pause >nul
"%VV_PHP%" "tools\recover-owner.php"
if errorlevel 1 (
 echo Recovery did not report success. Read the error above and OWNER-RECOVERY-GUIDE.txt.
 echo Keep the current recovery code. Do not delete accounts or reinstall the database.
 pause
 exit /b 1
)
echo Record the replacement code offline before closing this window. Then sign in with your new password.
pause
exit /b 0
