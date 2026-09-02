<?php
/**
 * Production WebSocket via Apache /lc-realtime (port 443) — works for 3knotsdigital.com embed.
 */
return [
    'mode' => 'ws',
    'ws_url' => 'wss://erp.3knotsdigital.com/lc-realtime',
    'publish_url' => 'http://127.0.0.1:6001/publish',
    'secret' => 'local-dev-realtime-secret',
];
