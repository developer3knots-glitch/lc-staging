<?php
/**
 * LiveChat - Core Configuration
 * Live: https://livechat.3knotsdigital.com/
 * ERP path install: https://erp.3knotsdigital.com/WSlivechat/
 */

define('LC_APP_NAME', '3Knots LiveChat');
define('LC_VERSION', '1.0.0');

/** Fallback when request host is missing (CLI). Live request host always wins in lc_base_url(). */
define('LC_PUBLIC_BASE_URL', 'https://livechat.3knotsdigital.com');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $fwd = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    if ($fwd === 'http' || $fwd === 'https') {
        $protocol = $fwd;
    }
}
$host = $_SERVER['HTTP_HOST'] ?? 'livechat.3knotsdigital.com';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(admin|api|widget|install|superadmin)(/.*)?$#', '', $scriptDir);
$basePath = rtrim($basePath, '/');

$detected = $protocol . '://' . $host . $basePath;
if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
    define('LC_BASE_URL', LC_PUBLIC_BASE_URL);
} else {
    if (stripos($host, '3knotsdigital.com') !== false) {
        define('LC_BASE_URL', 'https://' . $host . $basePath);
    } else {
        define('LC_BASE_URL', $detected);
    }
}
define('LC_BASE_PATH', dirname(__DIR__));

define('LC_DB_HOST', 'localhost');
define('LC_DB_NAME', 'livechat');
define('LC_DB_USER', 'livechat');
define('LC_DB_PASS', 'cBRDPFXKLJ54Frca');
define('LC_DB_CHARSET', 'utf8mb4');

define('LC_SESSION_NAME', 'livechat_session');
define('LC_POLL_INTERVAL', 1500);

// aaPanel: use poll until Node WS is proxied on this host
define('LC_REALTIME_MODE', 'poll');
define('LC_REALTIME_WS_URL', 'wss://livechat.3knotsdigital.com/lc-realtime');
define('LC_REALTIME_PUBLISH_URL', 'http://127.0.0.1:6001/publish');
define('LC_REALTIME_SECRET', 'local-dev-realtime-secret');

define('LC_SUPERADMIN_NAME', 'Super Admin');
define('LC_SUPERADMIN_EMAIL', 'superadmin@3knotsdigital.com');
define('LC_SUPERADMIN_PASSWORD', 'SuperAdmin@123');

// Application logic uses UTC. MySQL session is set to +00:00 in db.php.
// APIs return ISO-8601; browsers display in the visitor/agent local timezone.
