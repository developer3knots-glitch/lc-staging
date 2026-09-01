# Start LiveChat realtime server for local XAMPP dev
# Usage: powershell -ExecutionPolicy Bypass -File tools/start-realtime-local.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$serverDir = Join-Path $root 'realtime\server'
Set-Location $serverDir

function Find-Node {
    $cmd = Get-Command node -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($p in @(
        'C:\Program Files\nodejs\node.exe',
        'C:\Program Files (x86)\nodejs\node.exe'
    )) {
        if (Test-Path $p) { return $p }
    }
    return $null
}

$node = Find-Node
if (-not $node) {
    Write-Host ''
    Write-Host 'Node.js not found. Install LTS from https://nodejs.org/ then re-run this script.'
    Write-Host 'See realtime/README-LOCAL.md'
    exit 1
}

$envExample = Join-Path $serverDir '.env.example'
$envFile = Join-Path $serverDir '.env'
if (-not (Test-Path $envFile)) {
    if (Test-Path $envExample) {
        Copy-Item $envExample $envFile
        Write-Host "Created .env from .env.example"
    } else {
        @"
PORT=6001
HOST=127.0.0.1
LC_REALTIME_SECRET=local-dev-realtime-secret
"@ | Set-Content $envFile -Encoding UTF8
        Write-Host 'Created default .env'
    }
}

Write-Host "Using Node: $node"
Write-Host "Server dir: $serverDir"
Write-Host ''

if (-not (Test-Path (Join-Path $serverDir 'node_modules\ws'))) {
    Write-Host 'Running npm install...'
    & npm install
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

Write-Host 'Starting realtime server (Ctrl+C to stop)...'
Write-Host 'Health: http://127.0.0.1:6001/health'
Write-Host ''

& npm start
