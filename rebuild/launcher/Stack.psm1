Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
function Stop-NearbyError([string]$Code){throw [InvalidOperationException]::new($Code)}
function Get-NearbyMessage([string]$Code){
 $messages=@{
 OWNERSHIP_SAVE_FAILED='Startup could not save service ownership. Services have been left running safely. Ask your developer to check storage permissions before retrying.'
 CONFIG_MISSING='Setup is incomplete. Ask your developer to finish the one-time installation.'
 CONFIG_INVALID='The saved setup needs attention. Ask your developer to check the configuration.'
 PHP_MISSING='PHP was not found. Keep XAMPP installed and ask your developer to check its location.'
 DB_BINARY_MISSING='The database program was not found. Ask your developer to check the XAMPP installation.'
 ASSETS_MISSING='Application files are missing. Ask your developer to install the complete built update.'
 DB_ACCESS_DENIED='The saved database connection was not accepted. Ask your developer to check the settings.'
 DB_NOT_INSTALLED='The shop database is missing. Ask your developer to finish setup or recover the existing database.'
 DB_UNAVAILABLE='The database could not be reached. Check that this is the shop host laptop, then retry.'
 DB_IDENTITY='The running database does not match this shop. Ask your developer to check the installation.'
 DB_BUSY='Another database process is starting or using this computer. Wait and retry; do not start another copy.'
 DB_TIMEOUT='The database is taking too long to start. Wait and retry, or contact your developer.'
 SERVICE_MISSING='A required service is missing. Ask your developer to rerun the one-time setup.'
 SERVICE_CHANGED='A service no longer matches the saved installation. Ask your developer to check it.'
 SERVICE_ACCESS='Windows did not allow service control. Ask your developer to run setup for your Windows account.'
 SERVICE_TIMEOUT='The service did not finish in time. It was left intact. Wait and retry or contact your developer.'
 PORT_BUSY='The shop address is in use by another program. Close the old development server or ask your developer to check it.'
 APP_TIMEOUT='Nearby Cafe could not become ready. Wait and retry; contact your developer if this continues.'
 OWNER_MISSING='Owner setup or recovery is needed. Ask your developer to prepare initial setup or recover the existing Owner.'
 SCHEMA_MISSING='The database setup is incomplete. Ask your developer to check it; do not reinstall over shop data.'
 SCHEMA_MISMATCH='The application and database do not match. Ask your developer to check the documented update.'
 STOP_INCOMPLETE='A service has not stopped yet. Nothing was force-closed. Wait and retry before shutting down Windows.'
 LOCKED='Nearby Cafe is already starting or stopping. Wait for the other launcher window.'
 }
 if($messages.ContainsKey($Code)){return $messages[$Code]}
 return 'Nearby Cafe needs attention. Retry once, then share the reference code with your developer.'
}
function Test-NearbyOwned($Record,$Current){
 return ($null -ne $Record -and $Current.Status -eq 'Running' -and [int]$Record.Pid -gt 0 -and
 [int]$Record.Pid -eq [int]$Current.Pid -and [string]$Record.Start -eq [string]$Current.Start -and [string]$Record.Binary -ceq [string]$Current.Binary)
}
function Wait-Nearby([scriptblock]$Check,[int]$Attempts,$Ops,[string]$Code){
 for($i=0;$i -lt $Attempts;$i++){if(& $Check){return}; & $Ops.Pause};Stop-NearbyError $Code
}
function Save-NearbyOwnership($State,[string]$Kind,$Service,$Ops){
 $next=$State.Clone()
 $next[$Kind]=@{Pid=$Service.Pid;Start=$Service.Start;Binary=$Service.Binary}
 try{& $Ops.Save $next}catch{Stop-NearbyError 'OWNERSHIP_SAVE_FAILED'}
 # Publish ownership in memory only after atomic persistence succeeds.
 $State[$Kind]=$next[$Kind]
}
function Start-NearbyStack($Config,$State,$Ops,[scriptblock]$Status){
 & $Status 'checking' 'Checking setup'; & $Ops.Validate
 $probe=& $Ops.Database
 if(-not $probe.ok){
  if($probe.code -ne 'DB_UNAVAILABLE'){Stop-NearbyError $probe.code}
  if(& $Ops.DatabasePortOpen){Stop-NearbyError 'DB_BUSY'}
  & $Status 'database' 'Starting database'
  $service=& $Ops.Service 'database';$started=$false
  if($service.Status -eq 'Stopped'){
   if(& $Ops.DatabaseProcessExists){Stop-NearbyError 'DB_BUSY'}
   $started=& $Ops.Start 'database'
  }
  Wait-Nearby {(& $Ops.Service 'database').Status -eq 'Running'} $Config.Attempts $Ops 'DB_TIMEOUT'
  if($started){Save-NearbyOwnership $State 'database' (& $Ops.Service 'database') $Ops}
  Wait-Nearby {(& $Ops.Database).ok} $Config.Attempts $Ops 'DB_TIMEOUT'
  $probe=& $Ops.Database
 }
 & $Ops.CheckDatabaseIdentity $probe.data
 $ready=& $Ops.Preflight
 if(-not $ready.ok){Stop-NearbyError $ready.code}
 & $Status 'coffee' 'Starting Nearby Cafe'
 $service=& $Ops.Service 'web';$started=$false
 if($service.Status -eq 'Stopped'){
  if(& $Ops.WebPortOpen){Stop-NearbyError 'PORT_BUSY'}
  $started=& $Ops.Start 'web'
 }
 Wait-Nearby {(& $Ops.Service 'web').Status -eq 'Running'} $Config.Attempts $Ops 'APP_TIMEOUT'
 if($started){Save-NearbyOwnership $State 'web' (& $Ops.Service 'web') $Ops}
 Wait-Nearby {& $Ops.HttpReady $ready.data.epoch} $Config.Attempts $Ops 'APP_TIMEOUT'
 & $Status 'ready' 'Ready'; & $Ops.Open
 return @{Ready=$true}
}
function Stop-NearbyStack($Config,$State,$Ops,[scriptblock]$Status){
 & $Status 'checking' 'Stopping Nearby Cafe'
 $web=& $Ops.Service 'web'
 if($web.Status -eq 'StopPending'){
  Wait-Nearby {(& $Ops.Service 'web').Status -eq 'Stopped'} $Config.Attempts $Ops 'STOP_INCOMPLETE';$web=& $Ops.Service 'web'
 }
 if(Test-NearbyOwned $State.web $web){
  & $Ops.Stop 'web' $State.web
  Wait-Nearby {(& $Ops.Service 'web').Status -eq 'Stopped'} $Config.Attempts $Ops 'STOP_INCOMPLETE'
 }elseif($web.Status -ne 'Stopped'){
  & $Status 'shared' 'Services were started elsewhere and have been left running.';return @{Stopped=$false}
 }
 $State.web=$null;& $Ops.Save $State
 $db=& $Ops.Service 'database'
 if($db.Status -eq 'StopPending' -and $State.database){
  Wait-Nearby {(& $Ops.Service 'database').Status -eq 'Stopped'} $Config.Attempts $Ops 'STOP_INCOMPLETE';$db=& $Ops.Service 'database'
 }
 if($Config.StopOwnedDatabase -and (Test-NearbyOwned $State.database $db)){
  & $Ops.Stop 'database' $State.database
  Wait-Nearby {(& $Ops.Service 'database').Status -eq 'Stopped'} $Config.Attempts $Ops 'STOP_INCOMPLETE';$State.database=$null
 }elseif($db.Status -eq 'Stopped'){$State.database=$null}
 & $Ops.Save $State
 $message='Nearby Cafe is stopped. You can close this window.'
 if((& $Ops.Service 'database').Status -ne 'Stopped'){$message='Nearby Cafe is stopped. The shared database has been left running.'}
 & $Status 'stopped' $message;return @{Stopped=$true}
}
Export-ModuleMember -Function *-Nearby*
