Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
Import-Module (Join-Path $PSScriptRoot 'Stack.psm1') -Force
function ConvertTo-NearbyArgument([string]$Value){
 if($Value -match '[\x00\r\n]'){Stop-NearbyError 'CONFIG_INVALID'}
 $escaped=[regex]::Replace($Value,'(\\*)"','$1$1\"')
 $escaped=[regex]::Replace($escaped,'(\\+)$','$1$1')
 return '"'+$escaped+'"'
}
function Invoke-NearbyExe([string]$File,[string[]]$Arguments,[int]$Timeout=20){
 $info=New-Object Diagnostics.ProcessStartInfo
 $info.FileName=$File;$info.Arguments=($Arguments|ForEach-Object{ConvertTo-NearbyArgument $_}) -join ' '
 $info.UseShellExecute=$false;$info.CreateNoWindow=$true;$info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
 $p=New-Object Diagnostics.Process;$p.StartInfo=$info
 try{
  if(-not $p.Start()){Stop-NearbyError 'PROBE_FAILED'}
  $stdout=$p.StandardOutput.ReadToEndAsync();$stderr=$p.StandardError.ReadToEndAsync()
  if(-not $p.WaitForExit($Timeout*1000)){Stop-NearbyError 'SERVICE_TIMEOUT'}
  return @{Exit=$p.ExitCode;Output=$stdout.Result;Error=$stderr.Result}
 }finally{$p.Dispose()}
}
function Get-NearbyConfig([string]$Root){
 $file=Join-Path $Root 'config\launcher.local.json'
 if(-not(Test-Path -LiteralPath $file)){Stop-NearbyError 'CONFIG_MISSING'}
 try{$c=Get-Content -LiteralPath $file -Raw -Encoding UTF8|ConvertFrom-Json}catch{Stop-NearbyError 'CONFIG_INVALID'}
 foreach($key in @('Version','Root','Php','Httpd','DatabaseExe','DatabaseIni','DataDirectory','DatabaseHost','DatabasePort','WebPort','WebService','DatabaseService','WebCommandLine','WebArguments','DatabaseCommandLine','InstallationId','StopOwnedDatabase','Attempts','LanEnabled')){
  if($null -eq $c.PSObject.Properties[$key]){Stop-NearbyError 'CONFIG_INVALID'}
 }
 if($c.Version -ne 1 -or $c.LanEnabled -isnot [bool] -or $c.StopOwnedDatabase -isnot [bool] -or
 [IO.Path]::GetFullPath($c.Root).TrimEnd('\') -ne [IO.Path]::GetFullPath($Root).TrimEnd('\') -or
 $c.WebPort -lt 1024 -or $c.WebPort -gt 65535 -or $c.DatabasePort -lt 1 -or $c.DatabasePort -gt 65535 -or
 $c.Attempts -lt 1 -or $c.Attempts -gt 60 -or $c.DatabaseHost -notin @('127.0.0.1','localhost') -or
 $c.InstallationId -notmatch '^[a-f0-9]{32}$' -or $c.WebService -notmatch '^[a-zA-Z0-9_-]{1,80}$' -or $c.DatabaseService -notmatch '^[a-zA-Z0-9_-]{1,80}$'){Stop-NearbyError 'CONFIG_INVALID'}
 return $c
}
function Get-NearbyWebArguments([string]$Name){
 try{return @((Get-ItemProperty -LiteralPath ("HKLM:\SYSTEM\CurrentControlSet\Services\"+$Name+'\Parameters') -Name ConfigArgs -ErrorAction Stop).ConfigArgs)}catch{Stop-NearbyError 'SERVICE_CHANGED'}
}
function Get-NearbyServiceInfo($Config,[string]$Kind){
 $name=if($Kind -eq 'web'){$Config.WebService}else{$Config.DatabaseService}
 $expected=if($Kind -eq 'web'){$Config.WebCommandLine}else{$Config.DatabaseCommandLine}
 $s=Get-CimInstance Win32_Service -Filter "Name='$name'" -ErrorAction Stop
 if($null -eq $s){Stop-NearbyError 'SERVICE_MISSING'}
 if($s.PathName -cne $expected){Stop-NearbyError 'SERVICE_CHANGED'}
 if($Kind -eq 'web' -and ((Get-NearbyWebArguments $name|ConvertTo-Json -Compress) -cne ($Config.WebArguments|ConvertTo-Json -Compress))){Stop-NearbyError 'SERVICE_CHANGED'}
 $start=''
 if([int]$s.ProcessId -gt 0){
  $p=Get-CimInstance Win32_Process -Filter "ProcessId=$($s.ProcessId)" -ErrorAction Stop
  if($null -eq $p -or -not $p.CreationDate){Stop-NearbyError 'SERVICE_ACCESS'}
  $start=$p.CreationDate.ToUniversalTime().Ticks.ToString()
  $exe=if($Kind -eq 'web'){$Config.Httpd}else{$Config.DatabaseExe}
  if($p.ExecutablePath -and [IO.Path]::GetFullPath($p.ExecutablePath) -ne [IO.Path]::GetFullPath($exe)){Stop-NearbyError 'SERVICE_CHANGED'}
 }
 return @{Status=(Get-Service -Name $name).Status.ToString();Pid=[int]$s.ProcessId;Start=$start;Binary=$s.PathName;Name=$name}
}
function Test-NearbyPort([string]$HostName,[int]$Port){
 $c=New-Object Net.Sockets.TcpClient
 try{$task=$c.ConnectAsync($HostName,$Port);if(-not $task.Wait(700)){return $false};return $c.Connected}catch{return $false}finally{$c.Dispose()}
}
function Get-NearbyProbe($Config,[string]$Command){
 try{$r=Invoke-NearbyExe $Config.Php @((Join-Path $Config.Root 'tools\launcher-probe.php'),$Command) 8;return ($r.Output|ConvertFrom-Json)}catch{return [pscustomobject]@{ok=$false;code='PROBE_FAILED'}}
}
function Get-NearbyOwnership([string]$Root){
 $state=@{web=$null;database=$null};$file=Join-Path $Root 'storage\launcher\ownership.json'
 if(Test-Path -LiteralPath $file){
  try{$v=Get-Content -LiteralPath $file -Raw -Encoding UTF8|ConvertFrom-Json
   foreach($key in @('web','database')){if($v.PSObject.Properties[$key]){$state[$key]=$v.$key}}
  }catch{$state=@{web=$null;database=$null}}
 }
 return $state
}
function Save-NearbyState([string]$Root,$State){
 $file=Join-Path $Root 'storage\launcher\ownership.json';$tmp=$file+'.'+[guid]::NewGuid().ToString('N')+'.tmp'
 try{
  [IO.File]::WriteAllText($tmp,($State|ConvertTo-Json -Depth 5),(New-Object Text.UTF8Encoding($false)))
  # PowerShell 5.1 otherwise converts $null to an empty backup path.
  if(Test-Path -LiteralPath $file){
   [IO.File]::Replace($tmp,$file,[System.Management.Automation.Language.NullString]::Value)
  }else{[IO.File]::Move($tmp,$file)}
 }finally{
  if(Test-Path -LiteralPath $tmp){Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue}
 }
}
function Write-NearbyEvent([string]$Root,[string]$Code){
 if($Code -notmatch '^[A-Z0-9_]{1,60}$'){$Code='LAUNCHER_FAILED'}
 $folder=Join-Path $Root 'storage\launcher';if(-not(Test-Path -LiteralPath $folder)){return}
 $log=Join-Path $folder 'launcher.log'
 if((Test-Path -LiteralPath $log) -and (Get-Item -LiteralPath $log).Length -gt 262144){Move-Item -LiteralPath $log -Destination ($log+'.old') -Force}
 Add-Content -LiteralPath $log -Encoding UTF8 -Value ((Get-Date).ToString('s')+' '+$Code)
}
function Get-NearbyAdapters($Config){
 $root=$Config.Root
 return @{
 Validate={
  if(-not(Test-Path -LiteralPath $Config.Php -PathType Leaf)){Stop-NearbyError 'PHP_MISSING'}
  if(-not(Test-Path -LiteralPath $Config.DatabaseExe -PathType Leaf)){Stop-NearbyError 'DB_BINARY_MISSING'}
  foreach($file in @('public\index.html','public\favicon.ico','public\launcher-health.php','tools\launcher-probe.php','tools\launcher-log.php')){
   if(-not(Test-Path -LiteralPath (Join-Path $root $file) -PathType Leaf)){Stop-NearbyError 'ASSETS_MISSING'}
  }
  $html=Get-Content -LiteralPath (Join-Path $root 'public\index.html') -Raw
  $assets=[regex]::Matches($html,'(?:src|href)="\./(assets/[^"]+)"')
  if($assets.Count -lt 2){Stop-NearbyError 'ASSETS_MISSING'}
  foreach($a in $assets){if(-not(Test-Path -LiteralPath (Join-Path $root ('public\'+$a.Groups[1].Value)))){Stop-NearbyError 'ASSETS_MISSING'}}
  if(-not(Test-Path -LiteralPath $Config.Httpd)){Stop-NearbyError 'ASSETS_MISSING'}
  $d=Get-NearbyProbe $Config 'config';if(-not $d.ok){Stop-NearbyError $d.code}
  if($d.data.host -ne $Config.DatabaseHost -or [int]$d.data.port -ne [int]$Config.DatabasePort){Stop-NearbyError 'CONFIG_INVALID'}
  $test=Invoke-NearbyExe $Config.Httpd @('-t','-f',(Join-Path $root 'config\nearby-httpd.conf')) 15
  if($test.Exit -ne 0){Stop-NearbyError 'CONFIG_INVALID'}
 }.GetNewClosure()
 Database={Get-NearbyProbe $Config 'database'}.GetNewClosure()
 Preflight={
  $r=Invoke-NearbyExe $Config.Php @((Join-Path $root 'tools\preflight.php'),'--allow-owner-setup') 15
  if($r.Exit -ne 0){
   $code=if(($r.Error+$r.Output) -match 'FAIL ([A-Z0-9_]+):'){$Matches[1]}else{'PROBE_FAILED'}
   return [pscustomobject]@{ok=$false;code=$code}
  }
  Get-NearbyProbe $Config 'ready'
 }.GetNewClosure()
 CheckDatabaseIdentity={param($Data)
  if(([string]$Data.datadir).Replace('/','\').TrimEnd('\') -ne $Config.DataDirectory.TrimEnd('\') -or [int]$Data.port -ne [int]$Config.DatabasePort){Stop-NearbyError 'DB_IDENTITY'}
 }.GetNewClosure()
 DatabasePortOpen={Test-NearbyPort '127.0.0.1' $Config.DatabasePort}.GetNewClosure()
 WebPortOpen={Test-NearbyPort '127.0.0.1' $Config.WebPort}.GetNewClosure()
 DatabaseProcessExists={return @(Get-CimInstance Win32_Process -Filter "Name='mysqld.exe' OR Name='mariadbd.exe'" -ErrorAction Stop).Count -gt 0}.GetNewClosure()
 Service={param($Kind) Get-NearbyServiceInfo $Config $Kind}.GetNewClosure()
 Start={param($Kind)
  $before=Get-NearbyServiceInfo $Config $Kind
  if($before.Status -ne 'Stopped'){return $false}
  try{$c=New-Object ServiceProcess.ServiceController($before.Name);try{$c.Start();return $true}finally{$c.Dispose()}}
  catch{if((Get-NearbyServiceInfo $Config $Kind).Status -in @('Running','StartPending')){return $false};Stop-NearbyError 'SERVICE_ACCESS'}
 }.GetNewClosure()
 Stop={param($Kind,$Recorded)
  $current=Get-NearbyServiceInfo $Config $Kind
  if(-not(Test-NearbyOwned $Recorded $current)){Stop-NearbyError 'SERVICE_CHANGED'}
  # SCM stop targets only this service; unlike ServiceController.Stop, no recursive dependent stops.
  $r=Invoke-NearbyExe "$env:SystemRoot\System32\sc.exe" @('stop',$current.Name)
  if($r.Exit -ne 0){Stop-NearbyError 'STOP_INCOMPLETE'}
 }.GetNewClosure()
 Pause={Start-Sleep -Milliseconds 500}
 Save={param($State) Save-NearbyState $root $State}.GetNewClosure()
 HttpReady={param($Epoch)
  try{$r=Invoke-WebRequest -Uri ('http://127.0.0.1:'+$Config.WebPort+'/launcher-health.php') -UseBasicParsing -TimeoutSec 3 -MaximumRedirection 0
   $d=$r.Content|ConvertFrom-Json;return ($d.ok -and $d.installation -ceq $Config.InstallationId -and $d.epoch -ceq $Epoch)
  }catch{return $false}
 }.GetNewClosure()
 Open={try{Start-Process ('http://localhost:'+$Config.WebPort)}catch{Write-NearbyEvent $root 'BROWSER_OPEN_FAILED'}}.GetNewClosure()
 }
}
Export-ModuleMember -Function *-Nearby*
