# Disposable InnoDB hard-kill (chaos) rehearsal for VenueVue / Nearby Cafe.
# Creates a TEMPORARY MariaDB data directory on a TEST port, never the shop database.
# Proves: committed rows survive a hard kill, an in-flight transaction leaves zero partial
# writes, InnoDB crash recovery runs on restart, and the app invariants still hold.
[CmdletBinding()]
param(
 [string]$XamppRoot='C:\xampp',
 [int]$Port=3307,
 [string]$WorkDir=(Join-Path ([IO.Path]::GetTempPath()) ('nearby-chaos-'+[guid]::NewGuid().ToString('N').Substring(0,8))),
 [switch]$Keep
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$src=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$php=Join-Path $XamppRoot 'php\php.exe';$bin=Join-Path $XamppRoot 'mysql\bin'
$mysqld=Join-Path $bin 'mysqld.exe';$mysql=Join-Path $bin 'mysql.exe';$installDb=Join-Path $bin 'mysql_install_db.exe'
$failures=0;$server=$null;$worker=$null
function Step([string]$Text){Write-Output ('---- '+$Text)}
function Check([bool]$Ok,[string]$Text){if($Ok){Write-Output ('PASS '+$Text)}else{$script:failures++;Write-Output ('FAIL '+$Text)}}
function Test-Port([int]$P){$c=New-Object Net.Sockets.TcpClient;try{$a=$c.BeginConnect('127.0.0.1',$P,$null,$null);return ($a.AsyncWaitHandle.WaitOne(300) -and $c.Connected)}catch{return $false}finally{$c.Close()}}
function Wait-Port([int]$P,[bool]$Open,[int]$Seconds){for($i=0;$i -lt ($Seconds*4);$i++){if((Test-Port $P) -eq $Open){return $true};Start-Sleep -Milliseconds 250};return $false}
function Sql([string]$Query){$out=& $mysql '--user=root' '--host=127.0.0.1' ('--port='+$Port) -N -B nearby_chaos -e $Query 2>&1;if($LASTEXITCODE -ne 0){throw ('SQL failed: '+($out -join ' '))};return (($out|ForEach-Object{[string]$_}) -join "`n").Trim()}
function Start-TestServer{
 $script:server=Start-Process -FilePath $mysqld -ArgumentList @(('--defaults-file="'+$ini+'"'),('--log-error="'+$log+'"')) -PassThru -NoNewWindow
 if(-not(Wait-Port $Port $true 60)){throw 'Test database did not start; read '+$log}
}

# Safety guardrails: never the shop port, never an occupied port, never an existing directory.
if($Port -eq 3306){throw 'Refusing port 3306: that is the shop database port. Use a disposable port such as 3307.'}
foreach($f in @($php,$mysqld,$mysql,$installDb)){if(-not(Test-Path -LiteralPath $f)){throw ('Missing '+$f)}}
if(Test-Port $Port){throw ('Port '+$Port+' is already in use. Choose another disposable port.')}
if(Test-Path -LiteralPath $WorkDir){throw ('Work directory already exists: '+$WorkDir)}
$data=Join-Path $WorkDir 'data';$app=Join-Path $WorkDir 'rebuild';$ini=Join-Path $data 'my.ini';$log=Join-Path $WorkDir 'mysql_error.log'
try{
 Step ('Creating disposable copy and database in '+$WorkDir)
 foreach($d in @('app','tools','database')){Copy-Item -LiteralPath (Join-Path $src $d) -Destination (Join-Path $app $d) -Recurse}
 [void](New-Item -ItemType Directory -Path (Join-Path $app 'config'),(Join-Path $app 'storage') -Force)
 [IO.File]::WriteAllText((Join-Path $app 'config\local.php'),("<?php`nreturn ['db'=>['host'=>'127.0.0.1','port'=>"+$Port+",'name'=>'nearby_chaos','user'=>'root','password'=>''],'session_hours'=>8];`n"),(New-Object Text.UTF8Encoding($false)))
 $r=& $installDb ('--datadir='+$data) ('--port='+$Port) 2>&1;if($LASTEXITCODE -ne 0){throw ('mysql_install_db failed: '+($r -join ' '))}
 Start-TestServer
 Push-Location $app
 try{
  $r=& $php tools\install.php 2>&1;if($LASTEXITCODE -ne 0){throw ('install.php failed: '+($r -join ' '))}
  Check ((Sql 'SELECT @@innodb_flush_log_at_trx_commit') -eq '1') 'innodb_flush_log_at_trx_commit=1 (every COMMIT is flushed to the redo log)'
  & $php tools\integrity-check.php|Out-Null;Check ($LASTEXITCODE -eq 0) 'baseline integrity check is HEALTHY'

  Step 'Committing a row that must survive'
  Sql "INSERT INTO ingredients(name,unit,current_stock) VALUES('Chaos committed milk','mL',0)"|Out-Null

  Step 'Opening an in-flight transaction (sale-like multi-row write), then hard-killing the database'
  $marker=Join-Path $WorkDir 'worker.ready'
  $workerFile=Join-Path $WorkDir 'worker.php'
  [IO.File]::WriteAllText($workerFile,@"
<?php
require '$($app.Replace('\','/'))/app/bootstrap.php';
`$db=connection();`$db->beginTransaction();
`$db->exec("INSERT INTO ingredients(name,unit,current_stock) VALUES('Chaos uncommitted beans','g',500)");
`$db->exec("UPDATE ingredients SET current_stock=999 WHERE name='Chaos committed milk'");
file_put_contents('$($marker.Replace('\','/'))','ready');
sleep(120);`$db->commit();
"@,(New-Object Text.UTF8Encoding($false)))
  $worker=Start-Process -FilePath $php -ArgumentList @(('"'+$workerFile+'"')) -PassThru -NoNewWindow
  for($i=0;$i -lt 80 -and -not(Test-Path -LiteralPath $marker);$i++){Start-Sleep -Milliseconds 250}
  Check (Test-Path -LiteralPath $marker) 'uncommitted writes are in progress (not yet COMMITted)'
  Stop-Process -Id $server.Id -Force   # equivalent to pulling the plug on the database process
  [void](Wait-Port $Port $false 30);Start-Sleep -Seconds 1
  if(-not $worker.HasExited){Stop-Process -Id $worker.Id -Force}
  Check (-not(Test-Port $Port)) 'database process was killed without a clean shutdown'

  Step 'Restarting: InnoDB replays its redo log and rolls back unfinished transactions'
  Start-TestServer
  $recovery=@(Get-Content -LiteralPath $log|Select-String -Pattern 'crash recovery','Rolled back recovered transaction','rollback of','Starting final batch','To recover:')
  Check ($recovery.Count -gt 0) 'MariaDB error log shows InnoDB crash recovery after the kill'
  $recovery|Select-Object -Last 6|ForEach-Object{Write-Output ('      log: '+$_.Line.Trim())}
  Check ((Sql "SELECT COUNT(*) FROM ingredients WHERE name='Chaos uncommitted beans'") -eq '0') 'uncommitted INSERT is absent (zero partial writes)'
  Check ((Sql "SELECT current_stock FROM ingredients WHERE name='Chaos committed milk'") -eq '0.000') 'uncommitted UPDATE was rolled back; committed row intact'
  & $php tools\integrity-check.php
  Check ($LASTEXITCODE -eq 0) 'post-crash integrity check is HEALTHY'

  Step 'Negative control: a deliberately broken invariant must be detected'
  Sql "UPDATE ingredients SET current_stock=5 WHERE name='Chaos committed milk'"|Out-Null
  & $php tools\integrity-check.php|Out-Null
  Check ($LASTEXITCODE -eq 2) 'integrity check flags stock that has no matching stock movements'
 }finally{Pop-Location}
}finally{
 if($worker -and -not $worker.HasExited){Stop-Process -Id $worker.Id -Force -ErrorAction SilentlyContinue}
 if(Test-Port $Port){& $mysql '--user=root' '--host=127.0.0.1' ('--port='+$Port) -e 'SHUTDOWN' 2>$null|Out-Null;[void](Wait-Port $Port $false 30)}
 if($server -and -not $server.HasExited){Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue}
 if(-not $Keep -and (Test-Path -LiteralPath $WorkDir)){Start-Sleep -Seconds 1;Remove-Item -LiteralPath $WorkDir -Recurse -Force -ErrorAction SilentlyContinue}
 elseif($Keep){Write-Output ('Kept for inspection: '+$WorkDir)}
}
if($failures -gt 0){Write-Output ('CHAOS REHEARSAL FAILED: '+$failures+' check(s)');exit 1}
Write-Output 'ALL CHAOS CHECKS PASSED (disposable database; shop database untouched)'
exit 0
