<?php
require_once __DIR__ . '/../includes/auth.php';
lc_require_superadmin();
lc_logout_superadmin();
header('Location: ' . lc_superadmin_url('login'));
exit;
