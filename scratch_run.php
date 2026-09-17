<?php
define('LC_ROOT', 'c:/xampp/htdocs/WSlivechat');
require_once 'c:/xampp/htdocs/WSlivechat/includes/config.php';
require_once 'c:/xampp/htdocs/WSlivechat/includes/db.php';
require_once 'c:/xampp/htdocs/WSlivechat/includes/functions.php';
session_name(LC_SESSION_NAME);
session_start();
$_SESSION['agent_id'] = 1;
$_SESSION['account_id'] = 1;
$_GET['conversation_id'] = 62;
$_GET['last_id'] = 0;
ob_start();
require 'c:/xampp/htdocs/WSlivechat/api/agent-poll.php';
echo ob_get_clean();
