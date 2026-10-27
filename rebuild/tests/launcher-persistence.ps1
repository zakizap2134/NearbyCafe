# Real filesystem tests; no services, application database or private config used.
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
Import-Module "$PSScriptRoot/../launcher/Windows.psm1" -Force
function Assert($Condition,$Message){if(!$Condition){throw $Message}}
$root=Join-Path ([IO.Path]::GetTempPath()) ('Nearby Cafe Test '+[guid]::NewGuid().ToString('N'))
$folder=Join-Path $root 'storage/launcher'
[void][IO.Directory]::CreateDirectory($folder)
try{
 $file=Join-Path $folder 'ownership.json'
 $state=@{web=$null;database=$null}
 Save-NearbyState $root $state
 Assert (Test-Path -LiteralPath $file -PathType Leaf) 'First creation failed'
 Assert ($null -eq (Get-NearbyOwnership $root).web) 'First state incorrect'
 'PASS first ownership file creation in a path containing spaces'
 $state.web=@{Pid=123;Start='synthetic';Binary='C:\Shop Files\httpd.exe'}
 Save-NearbyState $root $state
 Assert ((Get-NearbyOwnership $root).web.Pid -eq 123) 'Replacement lost state'
 Assert (@(Get-ChildItem $folder -Filter '*.tmp').Count -eq 0) 'Successful save leaked temporary file'
 'PASS existing ownership file atomically replaced with NullString backup argument'
 # A directory at the destination forces replacement failure after temp creation.
 Remove-Item -LiteralPath $file
 [void][IO.Directory]::CreateDirectory($file)
 [IO.File]::WriteAllText((Join-Path $file 'sentinel'),'keep')
 $failed=$false
 try{Save-NearbyState $root $state}catch{$failed=$true}
 Assert $failed 'Expected replacement failure'
 Assert ((Get-Content (Join-Path $file 'sentinel') -Raw) -eq 'keep') 'Failure altered destination'
 Assert (@(Get-ChildItem $folder -Filter '*.tmp').Count -eq 0) 'Failure leaked temporary file'
 'PASS failed replacement preserves destination and removes temporary file'
}finally{Remove-Item -LiteralPath $root -Recurse -Force}
'ALL FILESYSTEM PERSISTENCE CHECKS PASSED; native Windows PowerShell 5.1 acceptance required when run elsewhere'
