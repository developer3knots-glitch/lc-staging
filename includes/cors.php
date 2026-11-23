<?php
/**
 * CORS for the visitor widget (cross-origin embeds).
 * Must load before db/config so OPTIONS preflight never 500s without headers.
 * Do not rely on nginx/aaPanel — every widget API must advertise these from PHP.
 */
if (!function_exists('lc_emit_cors_headers')) {
    function lc_emit_cors_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        // Reflect trusted https origins when present; otherwise * for public widget embeds.
        $allowOrigin = '*';
        if ($origin !== '' && preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $origin)) {
            $allowOrigin = $origin;
        }

        // Replace any prior ACAO so Apache+PHP never emit "*,*" (browsers treat that as missing).
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Methods');
        header_remove('Access-Control-Allow-Headers');
        header_remove('Access-Control-Max-Age');
        header_remove('Access-Control-Allow-Credentials');

        header('Access-Control-Allow-Origin: ' . $allowOrigin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
        header('Access-Control-Max-Age: 86400');
    }
}

lc_emit_cors_headers();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}
