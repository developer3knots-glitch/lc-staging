<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$file = __DIR__ . '/embed-app.js';
$v = is_file($file) ? (int) filemtime($file) : time();
echo json_encode(['v' => $v]);
