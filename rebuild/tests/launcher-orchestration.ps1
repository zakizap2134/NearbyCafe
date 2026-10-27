# Pure orchestration tests with simulated SCM adapters. No real services or data touched.
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
Import-Module "$PSScriptRoot/../launcher/Stack.psm1" -Force
Import-Module "$PSScriptRoot/../launcher/Windows.psm1" -Force
Import-Module "$PSScriptRoot/../launcher/Setup.Helpers.psm1" -Force
function Assert($Condition,$Message){if(!$Condition){throw $Message}}
function Fixture{
 $f=@{web=@{Status='Stopped';Pid=11;Start='1';Binary='web'};database=@{Status='Stopped';Pid=12;Start='1';Binary='database'};calls=[Collections.Generic.List[string]]::new();failure='';dbPort=$false;webPort=$false;otherDb=$false;dbReady=$true;http=$true;identity=$true;preflight='';stopFail='';startStalls='';saveCount=0}
 $o=@{
 Validate={if($f.failure){throw $f.failure}}.GetNewClosure()
 Database={if($f.database.Status -eq 'Running' -and $f.dbReady){return @{ok=$true;data=@{}}};return @{ok=$false;code='DB_UNAVAILABLE'}}.GetNewClosure()
 Preflight={if($f.preflight){return @{ok=$false;code=$f.preflight}};return @{ok=$true;data=@{epoch='test'}}}.GetNewClosure()
 CheckDatabaseIdentity={param($Data)if(!$f.identity){throw 'DB_IDENTITY'}}.GetNewClosure()
 DatabasePortOpen={return $f.dbPort}.GetNewClosure()
 WebPortOpen={return $f.webPort}.GetNewClosure()
 DatabaseProcessExists={return $f.otherDb}.GetNewClosure()
 Service={param($Kind)return $f[$Kind]}.GetNewClosure()
 Start={param($Kind)$f.calls.Add('start '+$Kind);if($f.startStalls -ne $Kind){$f[$Kind].Status='Running'};return $true}.GetNewClosure()
 Stop={param($Kind,$Recorded)$f.calls.Add('stop '+$Kind);if($f.stopFail -eq $Kind){throw 'STOP_INCOMPLETE'};$f[$Kind].Status='Stopped'}.GetNewClosure()
 Pause={}
 Save={param($State)$f.saveCount++}.GetNewClosure()
 HttpReady={param($Epoch)return $f.http}.GetNewClosure()
 Open={$f.calls.Add('open')}.GetNewClosure()
 }
 return @{f=$f;o=$o;s=@{web=$null;database=$null};c=@{Attempts=2;StopOwnedDatabase=$true}}
}
function Start-F($x){Start-NearbyStack $x.c $x.s $x.o {param($a,$b)}|Out-Null}
function Stop-F($x){Stop-NearbyStack $x.c $x.s $x.o {param($a,$b)}|Out-Null}
function Fails($x,$code){try{Start-F $x;throw 'Expected failure'}catch{Assert ($_.Exception.Message -eq $code) ('Expected '+$code+' got '+$_.Exception.Message)};Assert (-not $x.f.calls.Contains('open')) 'Browser opened before ready'}
$x=Fixture;Start-F $x;Assert (($x.f.calls -join ',') -eq 'start database,start web,open') 'Cold start ordering';Start-F $x;Assert (($x.f.calls|Where-Object{$_ -like 'start*'}).Count -eq 2) 'Duplicate start';Stop-F $x;Assert (($x.f.calls|Select-Object -Last 2) -join ',' -eq 'stop web,stop database') 'Stop order';'PASS cold start, duplicate start, browser readiness and owned graceful-stop ordering'
$x=Fixture;$x.f.database.Status='Running';Start-F $x;Stop-F $x;Assert ($x.f.database.Status -eq 'Running') 'Shared database stopped'
$x=Fixture;$x.f.database.Status='Running';$x.f.web.Status='Running';Start-F $x;Stop-F $x;Assert ($x.f.calls.Count -eq 1) 'Externally started service affected';'PASS pre-existing database and application preserved'
$x=Fixture;Start-F $x;$x.f.web.Start='replacement';Stop-F $x;Assert ($x.f.database.Status -eq 'Running' -and !$x.f.calls.Contains('stop web')) 'PID reuse stop';'PASS externally restarted process / reused PID not owned'
$x=Fixture;Start-F $x;$x.c.StopOwnedDatabase=$false;Stop-F $x;Assert ($x.f.database.Status -eq 'Running') 'Shared service policy ignored';'PASS shared service policy preserves even launcher-started shared database'
foreach($code in @('PHP_MISSING','CONFIG_MISSING','ASSETS_MISSING','DB_BINARY_MISSING')){$x=Fixture;$x.f.failure=$code;Fails $x $code};'PASS missing executable/configuration/assets diagnostics'
$x=Fixture;$x.f.dbPort=$true;Fails $x 'DB_BUSY';$x=Fixture;$x.f.otherDb=$true;Fails $x 'DB_BUSY';$x=Fixture;$x.f.webPort=$true;Fails $x 'PORT_BUSY';'PASS occupied ports and existing mysqld process reject duplicate starts'
$x=Fixture;$x.f.identity=$false;Fails $x 'DB_IDENTITY';foreach($code in @('OWNER_MISSING','SCHEMA_MISMATCH')){$x=Fixture;$x.f.preflight=$code;Fails $x $code};'PASS database identity and incomplete setup blocked'
$x=Fixture;$x.f.startStalls='database';Fails $x 'DB_TIMEOUT';$x=Fixture;$x.f.dbReady=$false;Fails $x 'DB_TIMEOUT';$x=Fixture;$x.f.http=$false;Fails $x 'APP_TIMEOUT';'PASS bounded service/SQL/HTTP readiness failures'
foreach($kind in @('web','database')){
 $x=Fixture;Start-F $x;$x.f.stopFail=$kind
 try{Stop-F $x;throw 'Expected stop failure'}catch{Assert ($_.Exception.Message -eq 'STOP_INCOMPLETE') 'Stop error'}
 Assert ($x.f.database.Status -eq 'Running') 'Database force stopped'
 if($kind -eq 'web'){Assert (!$x.f.calls.Contains('stop database')) 'DB stopped before web'}
};'PASS shutdown failures preserve database; no force-stop path'
# Persistence faults must not publish ownership or stop shared services.
foreach($kind in @('web','database')){
 $x=Fixture
 if($kind -eq 'web'){$x.f.database.Status='Running'}
 $x.o.Save={param($State)throw 'Injected persistence failure'}
 Fails $x 'OWNERSHIP_SAVE_FAILED'
 Assert ($null -eq $x.s[$kind]) 'Failed save falsely claimed service ownership'
 Assert ($x.f[$kind].Status -eq 'Running') 'Save failure terminated service'
 Assert (@($x.f.calls|Where-Object{$_ -like 'stop*'}).Count -eq 0) 'Save failure stopped a service'
}
'PASS ownership save failure leaves memory unclaimed, browser closed and services intact'
$x=Fixture;$x.f.database.Status='Running'
$x.s.web=@{Pid=99;Start='old';Binary='old'};$before=$x.s.web
$x.o.Save={param($State)throw 'Injected persistence failure'}
Fails $x 'OWNERSHIP_SAVE_FAILED'
Assert ([object]::ReferenceEquals($x.s.web,$before)) 'Existing ownership lost on failed save'
'PASS failed replacement preserves previous in-memory ownership'
Assert ((ConvertTo-NearbyArgument 'C:\Shop Files\') -ceq '"C:\Shop Files\\"') 'Trailing slash quoting'
Assert ((ConvertTo-NearbyArgument '') -ceq '""') 'Empty argument'
Assert ((ConvertTo-NearbyArgument 'a"b') -ceq '"a\"b"') 'Quote escaping'
$config=New-NearbyApacheConfig 'C:\Shop Files\Nearby Café' 'C:\xampp' 5174 $true
Assert ($config.Contains('DocumentRoot "C:/Shop Files/Nearby Café/public"')) 'Apache path quoting'
Assert ($config.Contains('<LocationMatch "^/$">')) 'Root route missing'
Assert (!$config.Contains('Include ')) 'XAMPP broad config included'
'PASS Windows argument quoting and Apache render for spaces / Unicode'
foreach($file in Get-ChildItem "$PSScriptRoot/../launcher" -Filter '*.ps*'){
 $tokens=$null;$errors=$null;[void][Management.Automation.Language.Parser]::ParseFile($file.FullName,[ref]$tokens,[ref]$errors);Assert ($errors.Count -eq 0) ('Syntax: '+$file.Name)
};'PASS all launcher PowerShell files parse'
'ALL LAUNCHER ORCHESTRATION CHECKS PASSED (simulated services; native Windows acceptance still required)'
