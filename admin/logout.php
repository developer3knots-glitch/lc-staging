<?php
require_once __DIR__ . '/../includes/auth.php';
lc_logout_agent();
header('Location: ' . LC_BASE_URL . '/admin/login.php');
exit;
