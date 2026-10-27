# One-time developer installation. Does not initialize, migrate or reset the database.
[CmdletBinding()]
param([string]$XamppRoot='C:\xampp',[int]$WebPort=5174,[switch]$LocalOnly,[switch]$KeepDatabaseRunning,[switch]$Elevated,[string]$OwnerSid,[string]$OwnerDesktop)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Windows.Forms
Import-Module (Join-Path $PSScriptRoot 'Windows.psm1') -Force
Import-Module (Join-Path $PSScriptRoot 'Setup.Helpers.psm1') -Force
$root=[IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent));$setupGuard=$null
function Show-SetupMessage([string]$Text,[bool]$Failed=$false){
 $icon=if($Failed){[Windows.Forms.MessageBoxIcon]::Error}else{[Windows.Forms.MessageBoxIcon]::Information}
 [void][Windows.Forms.MessageBox]::Show($Text,'Nearby Cafe setup',[Windows.Forms.MessageBoxButtons]::OK,$icon)
}
try{
 if(!$Elevated){
  $answer=[Windows.Forms.MessageBox]::Show('Developer setup will configure a dedicated Nearby Cafe web service, reuse or register the existing XAMPP database service, and create a desktop shortcut. Database files and application settings are preserved. An administrator prompt will follow. Continue?','Nearby Cafe - one-time setup',[Windows.Forms.MessageBoxButtons]::YesNo,[Windows.Forms.MessageBoxIcon]::Information)
  if($answer -ne [Windows.Forms.DialogResult]::Yes){exit}
  $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value;$desktop=[Environment]::GetFolderPath('Desktop')
  $arguments=@('-NoProfile','-STA','-ExecutionPolicy','Bypass','-File',$PSCommandPath,'-Elevated','-XamppRoot',$XamppRoot,'-WebPort',[string]$WebPort,'-OwnerSid',$sid,'-OwnerDesktop',$desktop)
  if($LocalOnly){$arguments+='-LocalOnly'}
  if($KeepDatabaseRunning){$arguments+='-KeepDatabaseRunning'}
  Start-Process -FilePath "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Verb RunAs -ArgumentList (($arguments|ForEach-Object{ConvertTo-NearbyArgument $_}) -join ' ')
  exit
 }
 $principal=New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
 if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'ADMIN_REQUIRED'}
 if($OwnerSid -notmatch '^S-1-5-21-(\d+-){3}\d+$' -or -not(Test-Path -LiteralPath $OwnerDesktop -PathType Container)){throw 'OWNER_WINDOWS_ACCOUNT_REQUIRED'}
 $x=[IO.Path]::GetFullPath($XamppRoot).TrimEnd('\')
 $php=Join-Path $x 'php\php.exe';$httpd=Join-Path $x 'apache\bin\httpd.exe';$dbexe=Join-Path $x 'mysql\bin\mysqld.exe';$ini=Join-Path $x 'mysql\bin\my.ini'
 foreach($file in @($php,$httpd,$dbexe,$ini,(Join-Path $x 'php\php8apache2_4.dll'),(Join-Path $root 'config\local.php'),(Join-Path $root 'public\index.html'),(Join-Path $root 'launcher\icons\nearby-cafe.ico'))){if(-not(Test-Path -LiteralPath $file -PathType Leaf)){throw 'REQUIRED_SETUP_FILE_MISSING'}}
 if($WebPort -lt 1024 -or $WebPort -gt 65535){throw 'WEB_PORT_INVALID'}
 $probe=Get-NearbyProbe ([pscustomobject]@{Php=$php;Root=$root}) 'config'
 if(-not $probe.ok){throw $probe.code}
 if($probe.data.host -notin @('localhost','127.0.0.1')){throw 'LOCAL_DATABASE_REQUIRED'}
 $options=Get-NearbyDatabaseOptions $ini
 $data=[IO.Path]::GetFullPath($options.datadir.Replace('/','\')).TrimEnd('\')
 if(-not(Test-Path -LiteralPath (Join-Path $data 'mysql') -PathType Container)){throw 'EXISTING_DATABASE_DIRECTORY_REQUIRED'}
 if([int]$options.port -ne [int]$probe.data.port){throw 'DATABASE_PORT_MISMATCH'}
 $running=Get-NearbyProbe ([pscustomobject]@{Php=$php;Root=$root}) 'database'
 if($running.ok){if(([string]$running.data.datadir).Replace('/','\').TrimEnd('\') -ne $data){throw 'DATABASE_DIRECTORY_MISMATCH'}}elseif($running.code -ne 'DB_UNAVAILABLE'){throw $running.code}
 $sha=[Security.Cryptography.SHA256]::Create()
 try{$suffix=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant())))).Replace('-','').Substring(0,10).ToLowerInvariant()}finally{$sha.Dispose()}
 $webName='NearbyCafeWeb_'+$suffix;$dedicatedDb='NearbyCafeDatabase_'+$suffix
 $configFile=Join-Path $root 'config\launcher.local.json';$old=$null
 if(Test-Path -LiteralPath $configFile){$old=Get-NearbyConfig $root}
 $all=@(Get-CimInstance Win32_Service)
 $dbServices=@($all|Where-Object{
  if($_.PathName -match '^\s*(?:"([^"]+)"|(\S+))'){$candidate=if($Matches[1]){$Matches[1]}else{$Matches[2]};$candidate.Replace('/','\') -eq $dbexe}else{$false}
 })
 if($dbServices.Count -gt 1){throw 'MULTIPLE_DATABASE_SERVICES'}
 $dbCreated=$false
 if($dbServices.Count -eq 1){
  $dbService=$dbServices[0]
  if($dbService.PathName.Replace('/','\') -notmatch ('(?i)(?:^|\s|")--defaults-file="?'+[regex]::Escape($ini)+'"?(?=\s|$)') -or $dbService.PathName -match '(?i)--(datadir|port|defaults-extra-file|no-defaults)(=|\s|")'){throw 'DATABASE_SERVICE_CONFIG_UNVERIFIED'}
  $dbName=$dbService.Name
 }else{
  $dbName=$dedicatedDb;if(Get-Service -Name $dbName -ErrorAction SilentlyContinue){throw 'DATABASE_SERVICE_NAME_CONFLICT'};$dbCreated=$true
 }
 $web=Get-Service -Name $webName -ErrorAction SilentlyContinue
 if($web){
  $record=Get-CimInstance Win32_Service -Filter "Name='$webName'"
  $expectedConfig=Join-Path $root 'config\nearby-httpd.conf'
  $expectedBinary='"'+$httpd+'" -k runservice'
  if($record.PathName -ine $expectedBinary){throw 'WEB_SERVICE_NAME_CONFLICT'}
  $registered=@(Get-NearbyWebArguments $webName)
  if($registered.Count -ne 2 -or $registered[0] -ne '-f' -or $registered[1].Replace('/','\') -ine $expectedConfig){throw 'WEB_SERVICE_CONFIG_UNVERIFIED'}
  if($old -and $record.PathName -cne $old.WebCommandLine){throw 'SERVICE_CHANGED'}
  if($web.Status -ne 'Stopped'){throw 'STOP_NEARBY_BEFORE_SETUP'}
 }
 if(Test-NearbyPort '127.0.0.1' $WebPort){throw 'PORT_BUSY'}
 $runtime=Join-Path $root 'storage\launcher';[void][IO.Directory]::CreateDirectory($runtime)
 try{$setupGuard=[IO.File]::Open((Join-Path $runtime 'operation.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)}catch{throw 'STOP_NEARBY_BEFORE_SETUP'}
 foreach($path in @($root,(Join-Path $x 'apache'),(Join-Path $x 'php'))){Set-NearbyDirectoryAccess $path 'S-1-5-19' 'ReadAndExecute'}
 Set-NearbyDirectoryAccess (Join-Path $root 'storage') 'S-1-5-19' 'Modify'
 Set-NearbyDirectoryAccess $runtime $OwnerSid 'Modify'
 $httpdConfig=Join-Path $root 'config\nearby-httpd.conf'
 [IO.File]::WriteAllText($httpdConfig,(New-NearbyApacheConfig $root $x $WebPort (-not $LocalOnly)),(New-Object Text.UTF8Encoding($false)))
 $r=Invoke-NearbyExe $httpd @('-t','-f',$httpdConfig);if($r.Exit -ne 0){throw 'APACHE_CONFIGURATION_FAILED'}
 if(!$web){$r=Invoke-NearbyExe $httpd @('-k','install','-n',$webName,'-f',$httpdConfig);if($r.Exit -ne 0){throw 'WEB_SERVICE_INSTALL_FAILED'}}
 $r=Invoke-NearbyExe "$env:SystemRoot\System32\sc.exe" @('config',$webName,'start=','demand','obj=','NT AUTHORITY\LocalService','password=','');if($r.Exit -ne 0){throw 'WEB_SERVICE_CONFIG_FAILED'}
 if($dbCreated){
  $r=Invoke-NearbyExe $dbexe @('--install-manual',$dbName,('--defaults-file='+$ini));if($r.Exit -ne 0){throw 'DATABASE_SERVICE_INSTALL_FAILED'}
 }
 # Also completes a partially interrupted install of this exact dedicated service.
 if($dbName -eq $dedicatedDb){
  $dbState=Get-Service -Name $dbName
  if($dbState.Status -ne 'Stopped' -and $dbState.Status -ne 'Running'){throw 'DATABASE_SERVICE_BUSY'}
  Set-NearbyDirectoryAccess (Join-Path $x 'mysql') 'S-1-5-19' 'ReadAndExecute'
  Set-NearbyDirectoryAccess $data 'S-1-5-19' 'Modify'
  if($options.ContainsKey('tmpdir')){Set-NearbyDirectoryAccess ($options.tmpdir.Replace('/','\')) 'S-1-5-19' 'Modify'}
  $r=Invoke-NearbyExe "$env:SystemRoot\System32\sc.exe" @('config',$dbName,'start=','demand','obj=','NT AUTHORITY\LocalService','password=','');if($r.Exit -ne 0){throw 'DATABASE_SERVICE_CONFIG_FAILED'}
 }
 Set-NearbyServiceAccess $webName $OwnerSid;Set-NearbyServiceAccess $dbName $OwnerSid
 $webArguments=@(Get-NearbyWebArguments $webName)
 $wr=Get-CimInstance Win32_Service -Filter "Name='$webName'";$dr=Get-CimInstance Win32_Service -Filter "Name='$dbName'"
 $id=if($old){$old.InstallationId}else{[guid]::NewGuid().ToString('N')}
 $stopDb=if($KeepDatabaseRunning){$false}elseif($old){$old.StopOwnedDatabase}else{$dbName -eq $dedicatedDb}
 $config=[ordered]@{Version=1;Root=$root;Php=$php;Httpd=$httpd;DatabaseExe=$dbexe;DatabaseIni=$ini;DataDirectory=$data;DatabaseHost=$probe.data.host;DatabasePort=[int]$probe.data.port;WebPort=$WebPort;WebService=$webName;DatabaseService=$dbName;WebCommandLine=$wr.PathName;WebArguments=$webArguments;DatabaseCommandLine=$dr.PathName;InstallationId=$id;StopOwnedDatabase=$stopDb;Attempts=40;LanEnabled=(-not $LocalOnly)}
 [IO.File]::WriteAllText($configFile,($config|ConvertTo-Json -Depth 5),(New-Object Text.UTF8Encoding($false)))
 $ruleName='NearbyCafe_'+$suffix
 if(Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue){Remove-NetFirewallRule -Name $ruleName}
 if(!$LocalOnly){New-NetFirewallRule -Name $ruleName -DisplayName 'Nearby Cafe - shop Wi-Fi' -Direction Inbound -Action Allow -Protocol TCP -LocalPort $WebPort -Program $httpd -Profile Private -RemoteAddress LocalSubnet|Out-Null}
 $shell=New-Object -ComObject WScript.Shell;$link=$shell.CreateShortcut((Join-Path $OwnerDesktop 'Nearby Café.lnk'))
 $link.TargetPath="$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
 $link.Arguments=(@('-NoProfile','-STA','-WindowStyle','Hidden','-ExecutionPolicy','Bypass','-File',(Join-Path $PSScriptRoot 'NearbyCafe.ps1'))|ForEach-Object{ConvertTo-NearbyArgument $_}) -join ' '
 $link.WorkingDirectory=$root;$link.IconLocation=(Join-Path $PSScriptRoot 'icons\nearby-cafe.ico')+',0';$link.Description='Start Nearby Cafe';$link.Save()
 Write-NearbyEvent $root 'SETUP_COMPLETE';$networkNote=''
 if(!$LocalOnly){$public=@(Get-NearbyPublicNetworks);if($public.Count -gt 0){Write-NearbyEvent $root 'NETWORK_PROFILE_PUBLIC';$networkNote="`r`n`r`nWARNING: Windows marks this network as Public: "+($public -join ', ')+". Tablets will be blocked until the shop Wi-Fi is set to Private (Settings > Network & internet > Wi-Fi > network properties > Private). Then run CHECK-SHOP-NETWORK.bat."}}
 Show-SetupMessage ('Setup is complete. Double-click Nearby Café on the desktop. Keep XAMPP installed; daily use does not need its control panel. Read WINDOWS_LAUNCHER_SETUP.md for first-use checks.'+$networkNote)
}catch{
 $code=$_.Exception.Message;if($code -notmatch '^[A-Z0-9_]+$'){$code='SETUP_FAILED'}
 Write-NearbyEvent $root $code
 Show-SetupMessage ("Setup stopped. Reference: "+$code+"`r`nRead WINDOWS_LAUNCHER_SETUP.md or ask your developer. Database files have not been reset.") $true
 exit 1
}finally{if($setupGuard){$setupGuard.Dispose()}}
