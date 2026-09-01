<?php
/**
 * Local XAMPP WebSocket overrides (auto-loaded by includes/realtime.php).
 * Copy to config.realtime.local.php — no need to edit config.php.
 *
 * Start Node: tools/start-realtime-local.ps1
 * Docs: realtime/README-LOCAL.md
 */
return [
    'mode' => 'ws',
    'ws_url' => 'auto',
    'publish_url' => 'http://127.0.0.1:6001/publish',
    'secret' => 'local-dev-realtime-secret',
];
