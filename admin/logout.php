<?php
require_once __DIR__ . '/../includes/auth.php';
lc_logout_agent();
header('Location: ' . lc_admin_url('login'));
exit;
