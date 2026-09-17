<?php
/**
 * One-shot CLI: split multi-day conversation threads into one ticket per calendar day.
 * Usage: php tools/split-ip-day-chats.php
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ip-resume.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

try {
    $result = lc_split_conversations_by_ip_day();
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Split failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
