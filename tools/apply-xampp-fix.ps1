# Run as Administrator if files are read-only:
#   powershell -ExecutionPolicy Bypass -File tools/apply-xampp-fix.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

$htSrc = Join-Path $root 'patches\dot__htaccess'
$htDst = Join-Path $root '.htaccess'
$fnDst = Join-Path $root 'includes\functions.php'

if (-not (Test-Path $htSrc)) {
    Write-Error "Missing $htSrc"
}

Copy-Item -LiteralPath $htSrc -Destination $htDst -Force
Write-Host "Updated .htaccess"

$content = Get-Content -LiteralPath $fnDst -Raw -Encoding UTF8
$old = @'
    if (PHP_SAPI === 'cli') {
        $resolved = defined('LC_BASE_URL') ? rtrim((string) LC_BASE_URL, '/') : '';
        return $resolved;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
'@
$new = @'
    if (defined('LC_BASE_URL') && LC_BASE_URL !== '') {
        $resolved = rtrim((string) LC_BASE_URL, '/');

        return $resolved;
    }

    if (PHP_SAPI === 'cli') {
        $resolved = '';
        return $resolved;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
'@
if ($content -notlike "*defined('LC_BASE_URL') && LC_BASE_URL !== ''*") {
    if ($content -notlike "*$($old.Trim())*") {
        Write-Error 'lc_base_url() block not found — functions.php may already be patched or changed.'
    }
    $content = $content.Replace($old, $new)
    Set-Content -LiteralPath $fnDst -Value $content -Encoding UTF8 -NoNewline
    Write-Host "Updated includes/functions.php (lc_base_url honors LC_BASE_URL)"
} else {
    Write-Host "includes/functions.php already patched"
}

Write-Host "Done. Test: http://localhost/WSlivechat/livechat/install.php"
