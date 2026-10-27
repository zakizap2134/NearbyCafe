# Read-only shop network diagnostic. Changes nothing; does not need administrator rights.
[CmdletBinding()]
param([int]$FallbackPort=5174)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
Import-Module (Join-Path $PSScriptRoot 'Stack.psm1') -Force
Import-Module (Join-Path $PSScriptRoot 'Windows.psm1') -Force
Import-Module (Join-Path $PSScriptRoot 'Setup.Helpers.psm1') -Force
$root=[IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent));$problems=0
function Out-Check([string]$Level,[string]$Text){
 if($Level -eq 'FAIL'){$script:problems++}
 Write-Output ($Level.PadRight(5)+' '+$Text)
}
$port=$FallbackPort;$lan=$true;$httpd=$null
try{$c=Get-NearbyConfig $root;$port=[int]$c.WebPort;$lan=[bool]$c.LanEnabled;$httpd=[string]$c.Httpd;Out-Check 'PASS' ('Launcher setup found; shop port '+$port+'.')}
catch{Out-Check 'WARN' ('Launcher setup not found; assuming port '+$port+'. Run Setup-Nearby-Cafe.bat once.')}
if(-not $lan){Out-Check 'FAIL' 'Setup was installed with -LocalOnly. Tablets cannot connect by design; rerun setup without -LocalOnly.'}

# 1. Wi-Fi name (tablets must join the same network, not a guest network).
try{
 $ssid=@(& "$env:SystemRoot\System32\netsh.exe" wlan show interfaces 2>$null|Where-Object{$_ -match '^\s*SSID\s*:\s*(.+)$'}|ForEach-Object{$Matches[1].Trim()})
 if($ssid.Count){Out-Check 'INFO' ('Host Wi-Fi name: '+($ssid -join ', ')+'  (tablets must use exactly this name, not a Guest network)')}
 else{Out-Check 'INFO' 'Host is not on Wi-Fi (cable or no wireless adapter). Tablets must join the router this cable connects to.'}
}catch{Out-Check 'INFO' 'Wi-Fi name could not be read.'}

# 2. Host addresses and Windows network profile.
$addresses=@(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue|Where-Object{$_.IPAddress -match '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' -and $_.AddressState -eq 'Preferred'})
if($addresses.Count -eq 0){Out-Check 'FAIL' 'No shop network address. Connect this host to the shop Wi-Fi or router.'}
foreach($a in $addresses){
 $category='Unknown';try{$category=[string](Get-NetConnectionProfile -InterfaceIndex $a.InterfaceIndex -ErrorAction Stop).NetworkCategory}catch{}
 $origin=[string]$a.PrefixOrigin
 $line=$a.IPAddress+' on '+$a.InterfaceAlias+' (profile '+$category+', address source '+$origin+')'
 if($category -eq 'Public'){Out-Check 'FAIL' ($line+': Public profile blocks tablets. Set this network to Private.')}
 else{Out-Check 'PASS' $line}
 if($origin -eq 'Dhcp'){Out-Check 'INFO' '  Address comes from the router (DHCP). Reserve it in the router so it never changes (OWNER_GUIDE.md).'}
}

# 3. Firewall rule created by setup (Private profile, local subnet only).
try{
 $rules=@(Get-NetFirewallRule -DisplayName 'Nearby Cafe - shop Wi-Fi' -ErrorAction Stop)
 foreach($r in $rules){
  $ports=@(($r|Get-NetFirewallPortFilter).LocalPort);$remote=@(($r|Get-NetFirewallAddressFilter).RemoteAddress)
  $text='Firewall rule enabled='+$r.Enabled+' profile='+$r.Profile+' port='+($ports -join ',')+' remote='+($remote -join ',')
  if([string]$r.Enabled -ne 'True' -or [string]$r.Action -ne 'Allow' -or ($ports -notcontains [string]$port)){Out-Check 'FAIL' ($text+'. Rerun Setup-Nearby-Cafe.bat.')}
  elseif([string]$r.Profile -match 'Public|Any'){Out-Check 'WARN' ($text+'. Rule is wider than Private; rerun setup.')}
  else{Out-Check 'PASS' $text}
 }
}catch{if($lan){Out-Check 'FAIL' 'Nearby Cafe firewall rule is missing. Rerun Setup-Nearby-Cafe.bat (administrator prompt).'}}
Out-Check 'INFO' 'If another antivirus/firewall product is installed, it must also allow the shop port on the private network.'

# 4. Is the shop web service listening?
$listen=@(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
if($listen.Count -eq 0){Out-Check 'FAIL' ('Nothing is listening on port '+$port+'. Double-click the Nearby Cafe shortcut and wait for Ready.')}
elseif(@($listen|Where-Object{$_.LocalAddress -in @('0.0.0.0','::')}).Count -eq 0){Out-Check 'FAIL' ('Port '+$port+' only accepts this computer ('+(($listen|ForEach-Object{$_.LocalAddress}) -join ',')+'). Tablets cannot connect.')}
else{
 Out-Check 'PASS' ('Shop web service is listening for the network on port '+$port+'.')
 foreach($a in $addresses){
  try{$r=Invoke-WebRequest -Uri ('http://'+$a.IPAddress+':'+$port+'/') -UseBasicParsing -TimeoutSec 3 -MaximumRedirection 0;Out-Check 'PASS' ('Host answers at http://'+$a.IPAddress+':'+$port+'/ (HTTP '+$r.StatusCode+')')}
  catch{Out-Check 'FAIL' ('Host did not answer at http://'+$a.IPAddress+':'+$port+'/')}
 }
}
Write-Output ''
foreach($a in $addresses){Write-Output ('TABLET ADDRESS: http://'+$a.IPAddress+':'+$port+'/')}
if($problems -gt 0){Write-Output ('RESULT: '+$problems+' problem(s) found. Follow the FAIL lines, then run this check again.');exit 2}
Write-Output 'RESULT: host side is ready. If a tablet still fails, check the tablet: same Wi-Fi name, no Guest network, router AP/client isolation off.'
exit 0
