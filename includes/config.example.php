<?php
/**
 * LiveChat — Core Configuration (example)
 * Copy to config.php and set your database credentials.
 */

define('LC_APP_NAME', '3Knots LiveChat');
define('LC_VERSION', '1.0.0');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(admin|api|widget|install)(/.*)?$#', '', $scriptDir);
$basePath = rtrim($basePath, '/');
define('LC_BASE_URL', $protocol . '://' . $host . $basePath);
define('LC_BASE_PATH', dirname(__DIR__));

define('LC_DB_HOST', 'localhost');
define('LC_DB_NAME', 'your_database_name');
define('LC_DB_USER', 'your_database_user');
define('LC_DB_PASS', 'your_database_password');
define('LC_DB_CHARSET', 'utf8mb4');

define('LC_SESSION_NAME', 'livechat_session');
define('LC_POLL_INTERVAL', 1500);

date_default_timezone_set('Asia/Karachi');
