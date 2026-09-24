<?php
/**
 * LiveChat - Core Configuration (example)
 * Copy to config.php and set your database credentials.
 */

define('LC_APP_NAME', '3Knots LiveChat');
define('LC_VERSION', '1.0.0');

/** Fallback public base (no trailing slash). Live request host wins in lc_base_url(). */
define('LC_PUBLIC_BASE_URL', 'https://livechat.3knotsdigital.com');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'livechat.3knotsdigital.com';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(admin|api|widget|install|superadmin)(/.*)?$#', '', $scriptDir);
$basePath = rtrim($basePath, '/');
if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
    define('LC_BASE_URL', LC_PUBLIC_BASE_URL);
} else {
    define('LC_BASE_URL', (stripos($host, '3knotsdigital.com') !== false ? 'https' : $protocol) . '://' . $host . $basePath);
}
define('LC_BASE_PATH', dirname(__DIR__));

define('LC_DB_HOST', 'localhost');
define('LC_DB_NAME', 'your_database_name');
define('LC_DB_USER', 'your_database_user');
define('LC_DB_PASS', 'your_database_password');
define('LC_DB_CHARSET', 'utf8mb4');

define('LC_SESSION_NAME', 'livechat_session');
define('LC_POLL_INTERVAL', 1500);

// Realtime: poll (default on aaPanel) until WS is proxied
define('LC_REALTIME_MODE', 'poll');
define('LC_REALTIME_WS_URL', 'wss://livechat.3knotsdigital.com/lc-realtime');
define('LC_REALTIME_PUBLISH_URL', 'http://127.0.0.1:6001/publish');
define('LC_REALTIME_SECRET', 'change-me-long-random-secret');
// Super Admin (seeded once when table is empty - change after first login)
define('LC_SUPERADMIN_NAME', 'Super Admin');
define('LC_SUPERADMIN_EMAIL', 'superadmin@3knotsdigital.com');
define('LC_SUPERADMIN_PASSWORD', 'SuperAdmin@123');

// Application logic uses UTC. MySQL session is set to +00:00 in db.php.
// APIs return ISO-8601; browsers display in the visitor/agent local timezone.
