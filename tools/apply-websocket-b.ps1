# Apply Option A WebSocket foundation (websocket-b branch)
# Run in Admin PowerShell from repo root if files are read-only:
#   powershell -ExecutionPolicy Bypass -File tools/apply-websocket-b.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $root

$map = @{
    'patches/includes__realtime.php'           = 'includes/realtime.php'
    'patches/includes__functions.php'          = 'includes/functions.php'
    'patches/includes__config.example.php'     = 'includes/config.example.php'
    'patches/api__realtime-token.php'          = 'api/realtime-token.php'
    'patches/api__agent-send.php'              = 'api/agent-send.php'
    'patches/api__visitor-send.php'            = 'api/visitor-send.php'
    'patches/api__agent-close.php'             = 'api/agent-close.php'
    'patches/api__typing.php'                  = 'api/typing.php'
    'patches/api__widget-init.php'             = 'api/widget-init.php'
    'patches/admin__includes__footer.php'      = 'admin/includes/footer.php'
    'patches/assets__js__lc-realtime.js'       = 'assets/js/lc-realtime.js'
    'patches/assets__js__admin-chat.js'        = 'assets/js/admin-chat.js'
    'patches/widget__embed.js'                 = 'widget/embed.js'
}

function Unlock-File([string]$path) {
    if (-not (Test-Path $path)) { return }
    try { attrib -R $path 2>$null | Out-Null } catch { }
}

foreach ($entry in $map.GetEnumerator()) {
    $src = Join-Path $root $entry.Key
    $dst = Join-Path $root $entry.Value
    if (-not (Test-Path $src)) {
        Write-Warning "Skip missing patch: $($entry.Key)"
        continue
    }
    $dir = Split-Path $dst -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Unlock-File $dst
    Copy-Item $src $dst -Force
    Write-Host "Applied $($entry.Value)"
}

Write-Host ""
Write-Host "Done. Default mode is still poll (LC_REALTIME_MODE=poll)."
Write-Host "VPS: see realtime/README.md, then set ws URL + secret in includes/config.php"
