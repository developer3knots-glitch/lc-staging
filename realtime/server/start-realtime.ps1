# LiveChat Realtime Server — Auto-start script
# Add to Windows Task Scheduler to run at system startup:
#   Action: powershell.exe -WindowStyle Hidden -File "C:\xampp\htdocs\WSlivechat\realtime\server\start-realtime.ps1"
#   Trigger: At startup, or when XAMPP starts

$ServerDir = "C:\xampp\htdocs\WSlivechat\realtime\server"
$NodeExe   = "node"
$Script    = "server.js"
$LogFile   = "$ServerDir\realtime-server.log"

Set-Location $ServerDir

# Kill any stale process on port 6001 before starting
$existing = netstat -ano | Select-String ":6001 .+LISTENING"
if ($existing) {
    $pid = ($existing -split '\s+')[-1]
    try { Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue } catch {}
    Start-Sleep -Seconds 1
}

# Start the server in the background
Start-Process -FilePath $NodeExe `
    -ArgumentList $Script `
    -WorkingDirectory $ServerDir `
    -RedirectStandardOutput $LogFile `
    -RedirectStandardError "$ServerDir\realtime-error.log" `
    -WindowStyle Hidden `
    -PassThru

Write-Host "LiveChat Realtime Server started. Logs: $LogFile"
