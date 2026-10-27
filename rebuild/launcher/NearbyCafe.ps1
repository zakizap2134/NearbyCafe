# Daily use: no elevation, installations or database changes.
param([switch]$Stop)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
Import-Module (Join-Path $PSScriptRoot 'Windows.psm1') -Force
Import-Module (Join-Path $PSScriptRoot 'Stack.psm1') -Force
Add-Type -TypeDefinition @'
using System; using System.Runtime.InteropServices;
public static class NearbyDpi { [DllImport("user32.dll")] public static extern bool SetProcessDpiAwarenessContext(IntPtr value); }
'@
try{[void][NearbyDpi]::SetProcessDpiAwarenessContext([IntPtr](-4))}catch{}
Add-Type -AssemblyName PresentationFramework,PresentationCore,WindowsBase
$hash=[Security.Cryptography.SHA256]::Create()
try{$name=([BitConverter]::ToString($hash.ComputeHash([Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant())))).Replace('-','')}finally{$hash.Dispose()}
$created=$false;$mutex=New-Object Threading.Mutex($true,('Local\NearbyCafe_'+$name),[ref]$created)
$runtime=Join-Path $root 'storage\launcher'
if(!$created){
 if(Test-Path -LiteralPath $runtime){$signal=if($Stop){'stop.request'}else{'open.request'};[IO.File]::WriteAllText((Join-Path $runtime $signal),'1')}
 $mutex.Dispose();exit
}
$xaml=@'
<Window xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation" xmlns:x="http://schemas.microsoft.com/winfx/2006/xaml"
 Title="Nearby Café" Width="650" Height="530" MinWidth="400" MinHeight="300" WindowStartupLocation="CenterScreen" Background="#FFF9F0" FontFamily="Segoe UI" FontSize="15">
 <Window.Resources><Style TargetType="Button"><Setter Property="MinHeight" Value="46"/><Setter Property="Padding" Value="14,8"/><Setter Property="Margin" Value="0,0,10,10"/><Setter Property="Background" Value="#A93226"/><Setter Property="Foreground" Value="White"/><Setter Property="BorderThickness" Value="0"/></Style></Window.Resources>
 <ScrollViewer VerticalScrollBarVisibility="Auto" HorizontalScrollBarVisibility="Disabled"><StackPanel Margin="24">
  <Grid><Grid.ColumnDefinitions><ColumnDefinition Width="96"/><ColumnDefinition Width="*"/></Grid.ColumnDefinitions><Image x:Name="Logo" Width="78" Height="78" Stretch="Uniform" HorizontalAlignment="Left"/><StackPanel Grid.Column="1" VerticalAlignment="Center"><TextBlock Text="Nearby Café" FontSize="28" FontWeight="SemiBold" Foreground="#442D25"/><TextBlock Text="Your shop, ready in a few clicks." TextWrapping="Wrap" Foreground="#6A5349" Margin="0,5,0,0"/></StackPanel></Grid>
  <Border Background="White" CornerRadius="12" Padding="18" Margin="0,24,0,14"><Grid><Grid.ColumnDefinitions><ColumnDefinition Width="44"/><ColumnDefinition Width="*"/></Grid.ColumnDefinitions><Image x:Name="StatusIcon" Width="30" Height="30" VerticalAlignment="Top"/><StackPanel Grid.Column="1"><TextBlock x:Name="Heading" Text="Checking setup" TextWrapping="Wrap" FontSize="20" FontWeight="SemiBold" Foreground="#442D25"/><TextBlock x:Name="Detail" Text="Please wait while we prepare the shop." TextWrapping="Wrap" Foreground="#60483E" Margin="0,8,0,0"/><TextBlock x:Name="Reference" TextWrapping="Wrap" Foreground="#7B291F" Margin="0,8,0,0"/></StackPanel></Grid></Border>
  <ProgressBar x:Name="Progress" Height="5" IsIndeterminate="True" Foreground="#A93226" Background="#EAD9C8"/>
  <TextBlock x:Name="Address" TextWrapping="Wrap" Foreground="#60483E" Margin="0,14,0,8"/>
  <TextBlock Text="Keep this laptop awake and connected to the shop Wi-Fi while tablets are in use." TextWrapping="Wrap" Foreground="#60483E"/>
  <WrapPanel Margin="0,16,0,8">
   <Button x:Name="OpenButton" IsEnabled="False" AutomationProperties.Name="Open Nearby Café"><StackPanel Orientation="Horizontal"><Image x:Name="OpenIcon" Width="18" Height="18" Margin="0,0,8,0"/><TextBlock Text="Open Nearby Café"/></StackPanel></Button>
   <Button x:Name="RetryButton" IsEnabled="False" AutomationProperties.Name="Retry"><StackPanel Orientation="Horizontal"><Image x:Name="RetryIcon" Width="18" Height="18" Margin="0,0,8,0"/><TextBlock Text="Retry"/></StackPanel></Button>
   <Button x:Name="StopButton" IsEnabled="False" AutomationProperties.Name="Stop Nearby Café"><StackPanel Orientation="Horizontal"><Image x:Name="StopIcon" Width="18" Height="18" Margin="0,0,8,0"/><TextBlock Text="Stop Nearby Café"/></StackPanel></Button>
  </WrapPanel>
  <TextBlock Text="Closing this window leaves the shop running. Use Stop Nearby Café after finishing sales on all devices." TextWrapping="Wrap" FontSize="13" Foreground="#60483E"/>
 </StackPanel></ScrollViewer>
</Window>
'@
$window=[Windows.Markup.XamlReader]::Load((New-Object Xml.XmlNodeReader([xml]$xaml)))
$window.Width=[Math]::Min(650,[Windows.SystemParameters]::WorkArea.Width)
$window.Height=[Math]::Min(530,[Windows.SystemParameters]::WorkArea.Height)
$ui=@{};foreach($key in @('Logo','StatusIcon','Heading','Detail','Reference','Progress','Address','OpenButton','RetryButton','StopButton','OpenIcon','RetryIcon','StopIcon')){$ui[$key]=$window.FindName($key)}
$icons=Join-Path $PSScriptRoot 'icons'
function Image-Path([string]$Name){return [Windows.Media.Imaging.BitmapImage]::new([uri](Join-Path $icons $Name))}
try{
 $window.Icon=Image-Path 'nearby-cafe.ico';$ui.Logo.Source=Image-Path 'nearby-cafe-256.png'
 $ui.OpenIcon.Source=Image-Path 'open.png';$ui.RetryIcon.Source=Image-Path 'retry.png';$ui.StopIcon.Source=Image-Path 'stop.png'
}catch{[void][Windows.MessageBox]::Show('Launcher icons are missing. Ask your developer to install the complete update.','Nearby Café');$mutex.ReleaseMutex();$mutex.Dispose();exit 1}
$script:worker=$null;$script:operation=$null;$script:shared=$null
function Start-Operation([string]$Action){
 if($script:worker){return}
 $ui.OpenButton.IsEnabled=$false;$ui.RetryButton.IsEnabled=$false;$ui.StopButton.IsEnabled=$false;$ui.Reference.Text='';$ui.Progress.Visibility='Visible'
 $script:shared=[hashtable]::Synchronized(@{Stage='checking';Message='Checking setup';Error='';Done=$false;Ready=$false})
 $script:worker=[PowerShell]::Create()
 [void]$script:worker.AddScript({param($Root,$Action,$Shared)
  $ErrorActionPreference='Stop'
  Import-Module (Join-Path $Root 'launcher\Windows.psm1') -Force
  Import-Module (Join-Path $Root 'launcher\Stack.psm1') -Force
  $guard=$null
  try{
   $config=Get-NearbyConfig $Root;$folder=Join-Path $Root 'storage\launcher'
   if(-not(Test-Path -LiteralPath $folder)){throw 'CONFIG_MISSING'}
   try{$guard=[IO.File]::Open((Join-Path $folder 'operation.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)}catch{throw 'LOCKED'}
   $state=Get-NearbyOwnership $Root;$ops=Get-NearbyAdapters $config
   $status={param($Stage,$Message)$Shared.Stage=$Stage;$Shared.Message=$Message}.GetNewClosure()
   if($Action -eq 'stop'){[void](Stop-NearbyStack $config $state $ops $status)}else{$result=Start-NearbyStack $config $state $ops $status;$Shared.Ready=$result.Ready}
   Write-NearbyEvent $Root $(if($Action -eq 'stop'){'STOP_COMPLETE'}else{'START_READY'})
  }catch{
   $code=$_.Exception.Message;if($code -notmatch '^[A-Z0-9_]{1,60}$'){$code='LAUNCHER_FAILED'}
   $Shared.Error=$code;$Shared.Message=Get-NearbyMessage $code;$Shared.Stage='warning';Write-NearbyEvent $Root $code
  }finally{if($guard){$guard.Dispose()};$Shared.Done=$true}
 }).AddArgument($root).AddArgument($Action).AddArgument($script:shared)
 $script:operation=$script:worker.BeginInvoke()
}
function Confirm-Stop{
 if([Windows.MessageBox]::Show($window,'Finish sales on every device before stopping. Stop Nearby Café now?','Stop Nearby Café',[Windows.MessageBoxButton]::YesNo,[Windows.MessageBoxImage]::Question) -eq [Windows.MessageBoxResult]::Yes){Start-Operation 'stop'}
}
$ui.OpenButton.Add_Click({try{$c=Get-NearbyConfig $root;Start-Process ('http://localhost:'+$c.WebPort)}catch{$ui.Detail.Text='The browser could not open. Open the shop address shown below manually.'}})
$ui.RetryButton.Add_Click({Start-Operation 'start'});$ui.StopButton.Add_Click({Confirm-Stop})
$timer=New-Object Windows.Threading.DispatcherTimer;$timer.Interval=[TimeSpan]::FromMilliseconds(200)
$timer.Add_Tick({
 if($script:shared){
  $stage=$script:shared.Stage;$ui.StatusIcon.Source=Image-Path ($stage+'.png')
  $ui.Heading.Text=if($stage -eq 'warning'){'Startup problem'}elseif($stage -eq 'ready'){'Ready'}elseif($stage -eq 'stopped'){'Stopped'}elseif($stage -eq 'shared'){'Services left running'}else{$script:shared.Message}
  $ui.Detail.Text=if($stage -eq 'ready'){'Your shop is ready. Use Open Nearby Café if the browser did not open.'}else{$script:shared.Message}
  if($script:shared.Error){$ui.Reference.Text='Reference: '+$script:shared.Error}
  if($script:shared.Done -and $script:worker){
   try{[void]$script:worker.EndInvoke($script:operation)}catch{$ui.Detail.Text='The operation could not finish. Contact your developer.'}
   $script:worker.Dispose();$script:worker=$null;$ui.Progress.Visibility='Collapsed';$ui.RetryButton.IsEnabled=$true;$ui.StopButton.IsEnabled=$true;$ui.OpenButton.IsEnabled=$script:shared.Ready
   try{
    $c=Get-NearbyConfig $root;$address='Shop address: http://localhost:'+$c.WebPort
    if($c.LanEnabled){$addresses=@(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue|Where-Object{$_.IPAddress -match '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' -and $_.AddressState -eq 'Preferred'}|Select-Object -ExpandProperty IPAddress)
     if($addresses.Count -gt 0){$address+="`r`nTablet address: http://"+$addresses[0]+':'+$c.WebPort}
    };$ui.Address.Text=$address
   }catch{}
  }
 }
 foreach($signal in @('open.request','stop.request')){
  $path=Join-Path $runtime $signal
  if((Test-Path -LiteralPath $path) -and !$script:worker){
   Remove-Item -LiteralPath $path -Force;$window.WindowState='Normal';[void]$window.Activate()
   if($signal -eq 'stop.request'){Confirm-Stop}elseif($ui.OpenButton.IsEnabled){$ui.OpenButton.RaiseEvent([Windows.RoutedEventArgs]::new([Windows.Controls.Button]::ClickEvent))}else{Start-Operation 'start'}
  }
 }
})
$window.Add_Closing({param($sender,$event)if($script:worker){$event.Cancel=$true;$ui.Detail.Text='Please wait for startup or shutdown to finish before closing this window.'}})
$window.Add_ContentRendered({if($Stop){$ui.RetryButton.IsEnabled=$true;$ui.StopButton.IsEnabled=$true;Confirm-Stop}else{Start-Operation 'start'}})
try{$timer.Start();[void]$window.ShowDialog()}finally{$timer.Stop();$mutex.ReleaseMutex();$mutex.Dispose()}
