# Unified 1-Click Client Setup for Nearby Cafe (VenueVue).
[CmdletBinding()]
param(
 [string]$XamppRoot='C:\xampp',
 [int]$WebPort=5174,
 [switch]$LocalOnly,
 [switch]$KeepDatabaseRunning,
 [switch]$Elevated,
 [string]$OwnerSid,
 [string]$OwnerDesktop
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$global:LASTEXITCODE=0
Add-Type -AssemblyName System.Windows.Forms,System.Drawing
Import-Module (Join-Path $PSScriptRoot 'Windows.psm1') -Force
Import-Module (Join-Path $PSScriptRoot 'Setup.Helpers.psm1') -Force
$root=[IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
$brandName="Nearby Caf$([char]0x00E9)"

function Show-ClientDialog([string]$Title,[string]$Message,[bool]$IsError=$false){
 $icon=if($IsError){[Windows.Forms.MessageBoxIcon]::Error}else{[Windows.Forms.MessageBoxIcon]::Information}
 [void][Windows.Forms.MessageBox]::Show($Message,$Title,[Windows.Forms.MessageBoxButtons]::OK,$icon)
}

function Show-SetupCodeDialog([string]$SetupCode,[string]$CodeFilePath,[string]$Url){
 try{[Windows.Forms.Clipboard]::SetText($SetupCode)}catch{}
 $form=New-Object Windows.Forms.Form
 $form.Text="$brandName - 1-Click Setup Complete"
 $form.Size=New-Object Drawing.Size(560,390)
 $form.StartPosition='CenterScreen'
 $form.FormBorderStyle='FixedDialog'
 $form.MaximizeBox=$false
 $form.MinimizeBox=$false
 $form.BackColor=[Drawing.Color]::FromArgb(255,249,240)
 $form.Font=New-Object Drawing.Font('Segoe UI',10)

 $lblTitle=New-Object Windows.Forms.Label
 $lblTitle.Text="$brandName Is Ready!"
 $lblTitle.Font=New-Object Drawing.Font('Segoe UI',15,[Drawing.FontStyle]::Bold)
 $lblTitle.ForeColor=[Drawing.Color]::FromArgb(68,45,37)
 $lblTitle.Location=New-Object Drawing.Point(22,18)
 $lblTitle.Size=New-Object Drawing.Size(500,32)
 $form.Controls.Add($lblTitle)

 $lblInfo=New-Object Windows.Forms.Label
 $lblInfo.Text="Your One-Time Owner Setup Code has been generated, copied to your clipboard, and saved to your Desktop:`r`n$CodeFilePath"
 $lblInfo.ForeColor=[Drawing.Color]::FromArgb(96,72,62)
 $lblInfo.Location=New-Object Drawing.Point(22,56)
 $lblInfo.Size=New-Object Drawing.Size(505,58)
 $form.Controls.Add($lblInfo)

 $txtCode=New-Object Windows.Forms.TextBox
 $txtCode.Text=$SetupCode
 $txtCode.ReadOnly=$true
 $txtCode.Multiline=$true
 $txtCode.Font=New-Object Drawing.Font('Consolas',11,[Drawing.FontStyle]::Bold)
 $txtCode.BackColor=[Drawing.Color]::White
 $txtCode.ForeColor=[Drawing.Color]::FromArgb(123,41,31)
 $txtCode.Location=New-Object Drawing.Point(22,120)
 $txtCode.Size=New-Object Drawing.Size(505,56)
 $form.Controls.Add($txtCode)

 $lblSteps=New-Object Windows.Forms.Label
 $lblSteps.Text="Next steps:`r`n1. Click 'Open Setup Page' below (or use the $brandName icon on your Desktop).`r`n2. Click 'Set up owner account' in the browser.`r`n3. Paste (Ctrl+V) the setup code above and choose your Owner username and password."
 $lblSteps.ForeColor=[Drawing.Color]::FromArgb(68,45,37)
 $lblSteps.Location=New-Object Drawing.Point(22,188)
 $lblSteps.Size=New-Object Drawing.Size(505,88)
 $form.Controls.Add($lblSteps)

 $btnCopy=New-Object Windows.Forms.Button
 $btnCopy.Text='Copy Code'
 $btnCopy.Size=New-Object Drawing.Size(150,40)
 $btnCopy.Location=New-Object Drawing.Point(22,290)
 $btnCopy.BackColor=[Drawing.Color]::FromArgb(234,217,200)
 $btnCopy.FlatStyle='Flat'
 $btnCopy.Add_Click({
  try{[Windows.Forms.Clipboard]::SetText($SetupCode);$btnCopy.Text='Copied!'}catch{}
 })
 $form.Controls.Add($btnCopy)

 $btnOpen=New-Object Windows.Forms.Button
 $btnOpen.Text='Open Setup Page ->'
 $btnOpen.Size=New-Object Drawing.Size(220,40)
 $btnOpen.Location=New-Object Drawing.Point(307,290)
 $btnOpen.BackColor=[Drawing.Color]::FromArgb(169,50,38)
 $btnOpen.ForeColor=[Drawing.Color]::White
 $btnOpen.FlatStyle='Flat'
 $btnOpen.Add_Click({
  try{[Windows.Forms.Clipboard]::SetText($SetupCode)}catch{}
  $form.DialogResult=[Windows.Forms.DialogResult]::OK
  $form.Close()
 })
 $form.AcceptButton=$btnOpen
 $form.Controls.Add($btnOpen)
 [void]$form.ShowDialog()
}

try{
 # 1. Auto-request Administrator elevation if not already elevated
 if(!$Elevated){
  $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
  $desktop=[Environment]::GetFolderPath('Desktop')
  $principal=New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
  if($principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){
   $Elevated=$true
   $OwnerSid=$sid
   $OwnerDesktop=$desktop
  }else{
   $promptMsg="Welcome to $brandName 1-Click Setup!`r`n`r`nThis automated installer will:`r`n- Verify XAMPP 8.2+ (Apache, PHP, MariaDB)`r`n- Create config\local.php and initialize the database`r`n- Generate your One-Time Owner Setup Code on your Desktop`r`n- Register Windows background services & Desktop shortcut`r`n- Open $brandName in your browser`r`n`r`nAn Administrator prompt will follow. Continue?"
   $ans=[Windows.Forms.MessageBox]::Show($promptMsg,"$brandName - 1-Click Setup",[Windows.Forms.MessageBoxButtons]::YesNo,[Windows.Forms.MessageBoxIcon]::Information)
   if($ans -ne [Windows.Forms.DialogResult]::Yes){exit 0}
   $argsList=@('-NoProfile','-STA','-ExecutionPolicy','Bypass','-File',$PSCommandPath,'-Elevated','-XamppRoot',$XamppRoot,'-WebPort',[string]$WebPort,'-OwnerSid',$sid,'-OwnerDesktop',$desktop)
   if($LocalOnly){$argsList+='-LocalOnly'}
   if($KeepDatabaseRunning){$argsList+='-KeepDatabaseRunning'}
   Start-Process -FilePath "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Verb RunAs -ArgumentList (($argsList|ForEach-Object{ConvertTo-NearbyArgument $_}) -join ' ')
   exit 0
  }
 }

 $principal=New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
 if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'ADMIN_REQUIRED'}
 if(!$OwnerSid){$OwnerSid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value}
 if(!$OwnerDesktop -or -not(Test-Path -LiteralPath $OwnerDesktop -PathType Container)){$OwnerDesktop=[Environment]::GetFolderPath('Desktop')}

 # 2. Verify XAMPP installation
 $candidates=@($XamppRoot,'C:\xampp','D:\xampp','C:\Program Files\xampp')|Select-Object -Unique
 $x=$null
 foreach($cand in $candidates){
  if($cand -and (Test-Path -LiteralPath (Join-Path $cand 'php\php.exe') -PathType Leaf)){
   $x=[IO.Path]::GetFullPath($cand).TrimEnd('\')
   break
  }
 }
 if(!$x){
  Show-ClientDialog "$brandName - XAMPP Required" ("XAMPP (PHP 8.2+, Apache 2.4, and MariaDB) was not found at $XamppRoot.`r`n`r`nPlease install XAMPP at C:\xampp first, then double-click One-Click-Setup.bat again.") $true
  exit 1
 }

 $php=Join-Path $x 'php\php.exe'
 $httpd=Join-Path $x 'apache\bin\httpd.exe'
 $dbexe=Join-Path $x 'mysql\bin\mysqld.exe'
 $ini=Join-Path $x 'mysql\bin\my.ini'
 foreach($reqFile in @($php,$httpd,$dbexe,$ini,(Join-Path $x 'php\php8apache2_4.dll'))){
  if(-not(Test-Path -LiteralPath $reqFile -PathType Leaf)){
   Show-ClientDialog "$brandName - Incomplete XAMPP" ("Required XAMPP component is missing:`r`n$reqFile`r`n`r`nPlease install the full XAMPP package (Apache + PHP 8.2+ + MySQL/MariaDB).") $true
   exit 1
  }
 }

 # 3. Ensure storage directories & permissions
 $storageDir=Join-Path $root 'storage'
 $runtimeDir=Join-Path $storageDir 'launcher'
 $uploadsDir=Join-Path $storageDir 'uploads\products'
 foreach($d in @($storageDir,$runtimeDir,$uploadsDir)){[void][IO.Directory]::CreateDirectory($d)}
 Set-NearbyDirectoryAccess $storageDir 'S-1-5-19' 'Modify'
 if($OwnerSid -match '^S-1-5-21-(\d+-){3}\d+$'){
  Set-NearbyDirectoryAccess $storageDir $OwnerSid 'Modify'
  Set-NearbyDirectoryAccess $runtimeDir $OwnerSid 'Modify'
 }

 # 4. Auto-provision config\local.php if missing
 $options=Get-NearbyDatabaseOptions $ini
 $dbPort=[int]$options.port
 $localConfigPath=Join-Path $root 'config\local.php'
 if(-not(Test-Path -LiteralPath $localConfigPath -PathType Leaf)){
  $localPhpContent=@"
<?php
// Auto-generated by One-Click-Setup.bat. Never commit this file.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => $dbPort,
        'name' => 'venuevue_rebuild',
        'user' => 'root',
        'password' => '',
    ],
    'session_hours' => 8,
];
"@
  [IO.File]::WriteAllText($localConfigPath,$localPhpContent,(New-Object Text.UTF8Encoding($false)))
 }

 # 4.5. Ensure frontend assets (public\index.html) exist; auto-build if missing on a fresh Git clone
 $indexHtml=Join-Path $root 'public\index.html'
 if(-not(Test-Path -LiteralPath $indexHtml -PathType Leaf)){
  $npmCmd=Get-Command 'npm.cmd' -ErrorAction SilentlyContinue
  if(!$npmCmd){
   Show-ClientDialog "$brandName - Frontend Assets Missing" ("public\index.html was not found (this looks like an unbuilt Git clone) and Node.js/npm is not installed.`r`n`r`nEither install Node.js 22+ and run 'npm ci && npm run build' inside rebuild, or use the pre-built release folder.") $true
   exit 1
  }
  Push-Location $root
  try{
   if(-not(Test-Path -LiteralPath (Join-Path $root 'node_modules') -PathType Container)){
    $ciRes=Invoke-NearbyExe $npmCmd.Source @('ci')
    if($ciRes.Exit -ne 0){throw 'NPM_CI_FAILED'}
   }
   $buildRes=Invoke-NearbyExe $npmCmd.Source @('run','build')
   if($buildRes.Exit -ne 0 -or -not(Test-Path -LiteralPath $indexHtml -PathType Leaf)){throw 'NPM_BUILD_FAILED'}
  }finally{
   Pop-Location
  }
 }

 # 5. Stop existing NearbyCafeWeb service if running so Install.ps1 can run cleanly
 $sha=[Security.Cryptography.SHA256]::Create()
 try{$suffix=([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant())))).Replace('-','').Substring(0,10).ToLowerInvariant()}finally{$sha.Dispose()}
 $webServiceName='NearbyCafeWeb_'+$suffix
 $existingWeb=Get-Service -Name $webServiceName -ErrorAction SilentlyContinue
 if($existingWeb -and $existingWeb.Status -ne 'Stopped'){
  Stop-Service -Name $webServiceName -Force -ErrorAction SilentlyContinue
  for($i=0;$i -lt 25;$i++){
   $s=Get-Service -Name $webServiceName -ErrorAction SilentlyContinue
   if(!$s -or $s.Status -eq 'Stopped'){break}
   Start-Sleep -Milliseconds 300
  }
 }

 # 6. Run Install.ps1 to register services, ACLs, firewall rule, and Desktop shortcut
 $installScript=Join-Path $PSScriptRoot 'Install.ps1'
 & $installScript -Elevated -Quiet -XamppRoot $x -WebPort $WebPort -OwnerSid $OwnerSid -OwnerDesktop $OwnerDesktop -LocalOnly:$LocalOnly -KeepDatabaseRunning:$KeepDatabaseRunning
 if(-not(Test-Path -LiteralPath (Join-Path $root 'config\launcher.local.json') -PathType Leaf)){throw 'SERVICE_SETUP_FAILED'}

 # 7. Ensure MariaDB is running and provision/migrate database schema
 $cfg=Get-NearbyConfig $root
 $dbStartedBySetup=$false
 $dbProbe=Get-NearbyProbe ([pscustomobject]@{Php=$php;Root=$root}) 'database'
 if(-not $dbProbe.ok){
  $dbSvc=Get-Service -Name $cfg.DatabaseService -ErrorAction SilentlyContinue
  if($dbSvc -and $dbSvc.Status -ne 'Running'){
   Start-Service -Name $cfg.DatabaseService
   $dbStartedBySetup=$true
  }
  for($i=0;$i -lt 40;$i++){
   $dbProbe=Get-NearbyProbe ([pscustomobject]@{Php=$php;Root=$root}) 'database'
   if($dbProbe.ok){break}
   Start-Sleep -Milliseconds 500
  }
  if(-not $dbProbe.ok){throw 'DB_START_TIMEOUT'}
 }

 $installDbRes=Invoke-NearbyExe $php @((Join-Path $root 'tools\install.php'))
 if($installDbRes.Exit -ne 0){throw ('DB_INSTALL_FAILED: '+$installDbRes.Output)}

 $migrateScript=Join-Path $root 'tools\migrate-stage4.php'
 if(Test-Path -LiteralPath $migrateScript -PathType Leaf){
  $migRes=Invoke-NearbyExe $php @($migrateScript)
  if($migRes.Exit -ne 0){throw ('DB_MIGRATE_FAILED: '+$migRes.Output)}
 }

 # 8. Check if initial Owner setup is needed; if so, generate code & save to Desktop
 $checkOwnerPhp='require "'+(ConvertTo-NearbyApachePath $root)+'/app/owner_setup.php"; $db=connection(); echo owner_setup_needed($db) ? "NEEDED" : "EXISTS";'
 $ownerCheck=Invoke-NearbyExe $php @('-r',$checkOwnerPhp)
 $setupCode=$null
 $codeFilePath=Join-Path $OwnerDesktop 'NEARBY_CAFE_OWNER_SETUP_CODE.txt'

 if(($ownerCheck.Output -join '').Trim() -eq 'NEEDED'){
  $prepRes=Invoke-NearbyExe $php @((Join-Path $root 'tools\prepare-owner-setup.php'))
  if($prepRes.Exit -ne 0){throw ('OWNER_CODE_PREP_FAILED: '+$prepRes.Output)}
  foreach($line in ($prepRes.Output -split '\r?\n')){
   if($line.Trim() -match '^[A-F0-9]{8}(-[A-F0-9]{8}){7}$'){
    $setupCode=$line.Trim()
    break
   }
  }
  if(!$setupCode){throw 'OWNER_CODE_PARSE_FAILED'}
  $codeFileContent=@"
====================================================================
NEARBY CAFE (VENUEVUE) - ONE-TIME OWNER SETUP CODE
====================================================================

Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
Valid for: 24 hours
POS Address: http://localhost:$WebPort

YOUR ONE-TIME OWNER SETUP CODE:

    $setupCode

HOW TO COMPLETE OWNER SETUP:
1. Open http://localhost:$WebPort (or double-click '$brandName' on your Desktop).
2. Click 'Set up owner account'.
3. Paste the Setup Code above, then enter your Owner display name, username, and password (at least 12 characters).
4. Once your Owner account is created, go to Management -> Account security to generate your permanent offline Recovery Code.
5. Delete this file after you have finished setting up your Owner account.
====================================================================
"@
  [IO.File]::WriteAllText($codeFilePath,$codeFileContent,(New-Object Text.UTF8Encoding($false)))
  if($OwnerSid -match '^S-1-5-21-(\d+-){3}\d+$'){
   try{Set-NearbyDirectoryAccess $codeFilePath $OwnerSid 'Modify'}catch{}
  }
 }

 # 9. Start NearbyCafeWeb service, record launcher ownership, and verify HTTP readiness
 $webStartedBySetup=$false
 $webSvc=Get-Service -Name $cfg.WebService -ErrorAction SilentlyContinue
 if($webSvc -and $webSvc.Status -ne 'Running'){
  Start-Service -Name $cfg.WebService
  $webStartedBySetup=$true
 }
 for($i=0;$i -lt 30;$i++){
  $ws=Get-Service -Name $cfg.WebService -ErrorAction SilentlyContinue
  if($ws -and $ws.Status -eq 'Running'){break}
  Start-Sleep -Milliseconds 300
 }
 try{
  $ownState=Get-NearbyOwnership $root
  if($dbStartedBySetup){
   $dbInfo=Get-NearbyServiceInfo $cfg 'database'
   $ownState['database']=@{Pid=$dbInfo.Pid;Start=$dbInfo.Start;Binary=$dbInfo.Binary}
  }
  if($webStartedBySetup){
   $webInfo=Get-NearbyServiceInfo $cfg 'web'
   $ownState['web']=@{Pid=$webInfo.Pid;Start=$webInfo.Start;Binary=$webInfo.Binary}
  }
  Save-NearbyState $root $ownState
  $ownFile=Join-Path $root 'storage\launcher\ownership.json'
  if(($OwnerSid -match '^S-1-5-21-(\d+-){3}\d+$') -and (Test-Path -LiteralPath $ownFile)){
   Set-NearbyDirectoryAccess $ownFile $OwnerSid 'Modify'
  }
 }catch{}

 $ops=Get-NearbyAdapters $cfg
 $readyProbe=Get-NearbyProbe $cfg 'ready'
 if($readyProbe.ok){
  for($i=0;$i -lt 30;$i++){
   if(& $ops.HttpReady ([string]$readyProbe.data.epoch)){break}
   Start-Sleep -Milliseconds 300
  }
 }

 $url="http://localhost:$WebPort"
 $publicNets=@(Get-NearbyPublicNetworks)
 $wifiNote=if(-not $LocalOnly -and $publicNets.Count -gt 0){"`r`n`r`nNote: Your Wi-Fi ("+($publicNets -join ', ')+") is currently set to Public in Windows Settings. Set it to Private so barista tablets on shop Wi-Fi can connect."}else{''}

 if($setupCode){
  Show-SetupCodeDialog $setupCode $codeFilePath $url
  Start-Process $url
 }else{
  Start-Process $url
  Show-ClientDialog "$brandName - 1-Click Setup Complete" ("$brandName is installed, running, and open in your browser at:`r`n$url`r`n`r`n- Your existing Owner account and shop database have been preserved.`r`n- For daily operation, double-click the '$brandName' icon on your Desktop."+$wifiNote) $false
 }
}catch{
 $errMsg=$_.Exception.Message
 Write-NearbyEvent $root ('ONECLICK_'+$errMsg)
 Show-ClientDialog "$brandName - Setup Stopped" ("1-Click Setup encountered an issue:`r`n$errMsg`r`n`r`nPlease check that XAMPP is installed at C:\xampp and no other program is blocking port $WebPort.") $true
 exit 1
}
