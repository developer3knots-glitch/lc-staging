<?php
/**
 * CORS for the visitor widget (cross-origin embeds).
 * Must load before db/config so OPTIONS preflight never 500s without headers.
 */
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning');
    header('Access-Control-Max-Age: 86400');
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}
