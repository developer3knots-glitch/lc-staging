<?php
/**
 * Live aaPanel: WebSocket on same host via Nginx /lc-realtime → Node :6001
 */
return [
    'mode' => 'ws',
    'ws_url' => 'wss://livechat.3knotsdigital.com/lc-realtime',
    'publish_url' => 'http://127.0.0.1:6001/publish',
    'secret' => 'local-dev-realtime-secret',
];
