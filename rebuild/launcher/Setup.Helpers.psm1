Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
function ConvertTo-NearbyApachePath([string]$Path){
 if($Path -match '["\x00\r\n]'){throw 'CONFIG_INVALID'};return $Path.Replace('\','/')
}
function New-NearbyApacheConfig([string]$Root,[string]$Xampp,[int]$Port,[bool]$Lan){
 $app=ConvertTo-NearbyApachePath $Root;$x=ConvertTo-NearbyApachePath $Xampp
 if($Port -lt 1024 -or $Port -gt 65535){throw 'CONFIG_INVALID'}
 $listen=if($Lan){'0.0.0.0'}else{'127.0.0.1'}
 $allowed=if($Lan){'127.0.0.1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16'}else{'127.0.0.1'}
 return @"
ServerRoot "$x/apache"
Listen ${listen}:$Port
ServerName localhost:$Port
PidFile "$app/storage/launcher/apache.pid"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule headers_module modules/mod_headers.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule alias_module modules/mod_alias.so
LoadFile "$x/php/php8ts.dll"
LoadModule php_module "$x/php/php8apache2_4.dll"
PHPIniDir "$x/php"
TypesConfig "$x/apache/conf/mime.types"
DocumentRoot "$app/public"
DirectoryIndex index.html
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Timeout 60
KeepAlive On
KeepAliveTimeout 3
ThreadsPerChild 64
MaxConnectionsPerChild 0
ErrorLog "|\"$x/php/php.exe\" \"$app/tools/launcher-log.php\""
ErrorLogFormat "[%l] %M"
LogLevel warn
EnableSendfile Off
AcceptFilter http none
php_admin_flag display_errors Off
php_admin_flag log_errors Off
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "no-referrer"
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=`$1
<Directory />
 AllowOverride None
 Require all denied
</Directory>
# Permit directory-index processing only at the site root.
<LocationMatch "^/$">
 Require ip $allowed
</LocationMatch>
<Directory "$app/public">
 Options -Indexes -FollowSymLinks -ExecCGI
 AllowOverride None
 Require all denied
 <Files "index.html">
  Require ip $allowed
  Header set Cache-Control "no-cache"
 </Files>
 <FilesMatch "^(favicon\.ico|favicon-(16|32|48|192)\.png|apple-touch-icon\.png)$">
  Require ip $allowed
 </FilesMatch>
 <Files "launcher-health.php">
  Require local
  SetHandler application/x-httpd-php
 </Files>
</Directory>
<Directory "$app/public/assets">
 Require all denied
 <FilesMatch "\.(js|css|svg|png|ico|woff2)$">
  Require ip $allowed
 </FilesMatch>
</Directory>
<Directory "$app/public/api">
 Require all denied
 <Files "index.php">
  Require ip $allowed
  SetHandler application/x-httpd-php
  AcceptPathInfo Off
 </Files>
</Directory>
"@
}
function Get-NearbyDatabaseOptions([string]$Ini){
 $values=@{};$section=''
 foreach($line in [IO.File]::ReadAllLines($Ini)){
  $text=$line.Trim();if(!$text -or $text.StartsWith('#') -or $text.StartsWith(';')){continue}
  if($text.StartsWith('!')){throw 'DATABASE_INCLUDES_REQUIRE_MANUAL_SETUP'}
  if($text -match '^\[([^\]]+)\]$'){$section=$Matches[1].ToLowerInvariant();continue}
  if($section -ne 'mysqld'){continue}
  if($text -match '^([a-zA-Z0-9_-]+)\s*=\s*(.*)$'){
   $key=$Matches[1].ToLowerInvariant();$value=$Matches[2].Trim()
   if(($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))){$value=$value.Substring(1,$value.Length-2)}
   if($key -in @('port','datadir','basedir','tmpdir')){
    if($values.ContainsKey($key) -and $values[$key] -ne $value){throw 'DATABASE_OPTIONS_AMBIGUOUS'}
    $values[$key]=$value
   }
  }
 }
 if(-not $values.ContainsKey('datadir') -or -not $values.ContainsKey('port')){throw 'DATABASE_OPTIONS_MISSING'}
 return $values
}
function Set-NearbyDirectoryAccess([string]$Path,[string]$Sid,[string]$Rights){
 $acl=Get-Acl -LiteralPath $Path
 $rule=New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier($Sid)),[Security.AccessControl.FileSystemRights]$Rights,[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit',[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow)
 $acl.SetAccessRule($rule);Set-Acl -LiteralPath $Path -AclObject $acl
}
function Set-NearbyServiceAccess([string]$Name,[string]$Sid){
 $r=Invoke-NearbyExe "$env:SystemRoot\System32\sc.exe" @('sdshow',$Name)
 if($r.Exit -ne 0){throw 'SERVICE_PERMISSIONS_FAILED'}
 $sddl=($r.Output -split '\r?\n'|Where-Object{$_.Trim().StartsWith('D:')}) -join ''
 if(!$sddl){throw 'SERVICE_PERMISSIONS_FAILED'}
 $d=New-Object Security.AccessControl.RawSecurityDescriptor($sddl)
 $sidObject=New-Object Security.Principal.SecurityIdentifier($Sid)
 # Avoid duplicate ACEs on repeated setup; preserve all unrelated permissions.
 $found=$false
 foreach($a in $d.DiscretionaryAcl){if($a -is [Security.AccessControl.CommonAce] -and $a.SecurityIdentifier -eq $sidObject -and $a.AceQualifier -eq [Security.AccessControl.AceQualifier]::AccessAllowed -and ($a.AccessMask -band 0x200B5) -eq 0x200B5){$found=$true}}
 if(!$found){
  $ace=New-Object Security.AccessControl.CommonAce([Security.AccessControl.AceFlags]::None,[Security.AccessControl.AceQualifier]::AccessAllowed,0x200B5,$sidObject,$false,$null)
  $d.DiscretionaryAcl.InsertAce($d.DiscretionaryAcl.Count,$ace)
  $r=Invoke-NearbyExe "$env:SystemRoot\System32\sc.exe" @('sdset',$Name,$d.GetSddlForm([Security.AccessControl.AccessControlSections]::All))
  if($r.Exit -ne 0){throw 'SERVICE_PERMISSIONS_FAILED'}
 }
}
function Get-NearbyPublicNetworks{
 # The shop firewall rule is Private-only by design; a Public-profile Wi-Fi silently blocks tablets.
 $names=@()
 try{foreach($p in @(Get-NetConnectionProfile -ErrorAction Stop)){if([string]$p.NetworkCategory -eq 'Public'){$names+=('{0} ({1})' -f $p.Name,$p.InterfaceAlias)}}}catch{}
 return $names
}
Export-ModuleMember -Function *-Nearby*
