<?php
/**
 * Live aaPanel host: poll only until Node WS is proxied at /lc-realtime
 * on livechat.3knotsdigital.com. Do not point at erp.*/lc-realtime from this host.
 */
return [
    'mode' => 'poll',
    'ws_url' => '',
    'publish_url' => 'http://127.0.0.1:6001/publish',
    'secret' => 'local-dev-realtime-secret',
];
