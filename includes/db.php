<?php

require_once __DIR__ . '/config.php';

if (!defined('LC_TIMEZONE')) {
    define('LC_TIMEZONE', 'Asia/Karachi');
}

date_default_timezone_set(LC_TIMEZONE);

function lc_app_timezone(): string
{
    return LC_TIMEZONE;
}

function lc_utc_offset(): string
{
    static $offset = null;
    if ($offset === null) {
        $offset = (new DateTime('now', new DateTimeZone(LC_TIMEZONE)))->format('P');
    }
    return $offset;
}

function lc_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . LC_DB_HOST . ';dbname=' . LC_DB_NAME . ';charset=' . LC_DB_CHARSET;
        $pdo = new PDO($dsn, LC_DB_USER, LC_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . lc_utc_offset() . "'");
    }
    return $pdo;
}

function lc_installed(): bool
{
    $lock = LC_BASE_PATH . '/install/installed.lock';
    return file_exists($lock);
}
