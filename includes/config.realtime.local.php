<?php
/**
 * Live aaPanel host: poll only until Node WS is proxied at /lc-realtime
 * on this same host. Do not point WebSocket at the ERP subdomain from here.
 */
return [
    'mode' => 'poll',
    'ws_url' => '',
    'publish_url' => 'http://127.0.0.1:6001/publish',
    'secret' => 'local-dev-realtime-secret',
];
