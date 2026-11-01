# Uninstall and clean reset for Nearby Cafe (VenueVue).
[CmdletBinding()]
param(
 [switch]$Elevated,
 [switch]$DropDatabase,
 [string]$OwnerDesktop
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Windows.Forms,System.Drawing
Import-Module (Join-Path $PSScriptRoot 'Windows.psm1') -Force
Import-Module (Join-Path $PSScriptRoot 'Setup.Helpers.psm1') -Force
$root=[IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
$brandName="Nearby Caf$([char]0x00E9)"

function Show-Dialog([string]$Title,[string]$Message,[bool]$IsError=$false){
 $icon=if($IsError){[Windows.Forms.MessageBoxIcon]::Error}else{[Windows.Forms.MessageBoxIcon]::Information}
 [void][Windows.Forms.MessageBox]::Show($Message,$Title,[Windows.Forms.MessageBoxButtons]::OK,$icon)
}

try{
 # 1. Auto-request Administrator elevation
 if(!$Elevated){
  $desktop=[Environment]::GetFolderPath('Desktop')
  $principal=New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
  if($principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){
   $Elevated=$true
   $OwnerDesktop=$desktop
  }else{
   $msg="This will uninstall $brandName from this computer:`r`n`r`n- Stop and remove Nearby Cafe Windows background services`r`n- Remove the Desktop shortcut and firewall rules`r`n- Reset config and launcher files`r`n- Optionally reset the database so you can test One-Click-Setup fresh`r`n`r`nAn Administrator prompt will follow. Continue?"
   $ans=[Windows.Forms.MessageBox]::Show($msg,"$brandName - Uninstall",[Windows.Forms.MessageBoxButtons]::YesNo,[Windows.Forms.MessageBoxIcon]::Warning)
   if($ans -ne [Windows.Forms.DialogResult]::Yes){exit 0}
   $argsList=@('-NoProfile','-STA','-ExecutionPolicy','Bypass','-File',$PSCommandPath,'-Elevated','-OwnerDesktop',$desktop)
   Start-Process -FilePath "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Verb RunAs -ArgumentList (($argsList|ForEach-Object{ConvertTo-NearbyArgument $_}) -join ' ')
   exit 0
  }
 }

 $principal=New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
 if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'ADMIN_REQUIRED'}
 if(!$OwnerDesktop -or -not(Test-Path -LiteralPath $OwnerDesktop -PathType Container)){$OwnerDesktop=[Environment]::GetFolderPath('Desktop')}

 # 2. Determine service names and firewall rule
 $sha=[Security.Cryptography.SHA256]::Create()
 try{$suffix=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant())))).Replace('-','').Substring(0,10).ToLowerInvariant()}finally{$sha.Dispose()}
 $webName='NearbyCafeWeb_'+$suffix
 $dbName='NearbyCafeDatabase_'+$suffix
 $ruleName='NearbyCafe_'+$suffix

 $cfgFile=Join-Path $root 'config\launcher.local.json'
 $phpExe='C:\xampp\php\php.exe'
 if(Test-Path -LiteralPath $cfgFile){
  try{
   $c=Get-NearbyConfig $root
   if($c.WebService){$webName=$c.WebService}
   if($c.DatabaseService){$dbName=$c.DatabaseService}
   if($c.Php -and (Test-Path -LiteralPath $c.Php -PathType Leaf)){$phpExe=$c.Php}
  }catch{}
 }

 # 3. Stop and remove Apache Web Service
 $webSvc=Get-Service -Name $webName -ErrorAction SilentlyContinue
 if($webSvc){
  if($webSvc.Status -ne 'Stopped'){
   Stop-Service -Name $webName -Force -ErrorAction SilentlyContinue
   for($i=0;$i -lt 20;$i++){
    $s=Get-Service -Name $webName -ErrorAction SilentlyContinue
    if(!$s -or $s.Status -eq 'Stopped'){break}
    Start-Sleep -Milliseconds 300
   }
  }
  [void](Invoke-NearbyExe "$env:SystemRoot\System32\sc.exe" @('delete',$webName))
 }

 # 4. Remove Windows Firewall Rule
 if(Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue){
  Remove-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue
 }

 # 5. Remove Desktop shortcuts and code files
 $shortcut=Join-Path $OwnerDesktop "$brandName.lnk"
 if(Test-Path -LiteralPath $shortcut){Remove-Item -LiteralPath $shortcut -Force -ErrorAction SilentlyContinue}
 $codeFile=Join-Path $OwnerDesktop 'NEARBY_CAFE_OWNER_SETUP_CODE.txt'
 if(Test-Path -LiteralPath $codeFile){Remove-Item -LiteralPath $codeFile -Force -ErrorAction SilentlyContinue}

 # 6. Ask whether to drop database for 100% clean test
 $dropMsg="Do you also want to delete the shop database (venuevue_rebuild)?`r`n`r`n- Click YES if you want to test One-Click-Setup completely from scratch (creates fresh database and owner code).`r`n- Click NO if you want to keep your existing sales, products, and owner account."
 $dropDbAns=[Windows.Forms.MessageBox]::Show($dropMsg,"$brandName - Reset Database?",[Windows.Forms.MessageBoxButtons]::YesNo,[Windows.Forms.MessageBoxIcon]::Question)

 if($dropDbAns -eq [Windows.Forms.DialogResult]::Yes){
  $dbSvc=Get-Service -Name $dbName -ErrorAction SilentlyContinue
  if(!$dbSvc){$dbSvc=Get-Service -Name 'mysql' -ErrorAction SilentlyContinue}
  if($dbSvc -and $dbSvc.Status -ne 'Running'){
   Start-Service -Name $dbSvc.Name -ErrorAction SilentlyContinue
   Start-Sleep -Seconds 2
  }
  if(Test-Path -LiteralPath $phpExe -PathType Leaf){
   $dropPhp='try { $pdo = new PDO("mysql:host=127.0.0.1;port=3306", "root", ""); $pdo->exec("DROP DATABASE IF EXISTS venuevue_rebuild"); echo "DROPPED"; } catch(Exception $e) { echo $e->getMessage(); }'
   [void](Invoke-NearbyExe $phpExe @('-r',$dropPhp))
  }
  $dedSvc=Get-Service -Name $dbName -ErrorAction SilentlyContinue
  if($dedSvc -and $dbName -like 'NearbyCafeDatabase_*'){
   Stop-Service -Name $dbName -Force -ErrorAction SilentlyContinue
   [void](Invoke-NearbyExe "$env:SystemRoot\System32\sc.exe" @('delete',$dbName))
  }
 }

 # 7. Remove local generated configuration and runtime files
 $filesToClean=@(
  (Join-Path $root 'config\local.php'),
  (Join-Path $root 'config\launcher.local.json'),
  (Join-Path $root 'config\nearby-httpd.conf'),
  (Join-Path $root 'storage\owner-setup.json')
 )
 foreach($f in $filesToClean){
  if(Test-Path -LiteralPath $f){Remove-Item -LiteralPath $f -Force -ErrorAction SilentlyContinue}
 }
 $launcherRuntime=Join-Path $root 'storage\launcher'
 if(Test-Path -LiteralPath $launcherRuntime){
  Get-ChildItem -LiteralPath $launcherRuntime -File -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
 }

 $doneMsg="$brandName has been cleanly uninstalled and reset!`r`n`r`n- Services, shortcuts, and generated configs have been removed.`r`n- You can now double-click 'One-Click-Setup.bat' to test a brand-new 1-click installation from scratch."
 Show-Dialog "$brandName - Uninstalled" $doneMsg $false
}catch{
 $errMsg=$_.Exception.Message
 Show-Dialog "$brandName - Uninstall Error" ("Uninstall encountered an issue:`r`n"+$errMsg) $true
 exit 1
}
