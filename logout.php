<?php
define('APPDIR', 'barpos');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';
auditLog($conn, 'logout', 'User logged out');
session_unset();
session_destroy();
header('Location: /barpos/login.php');
exit;
