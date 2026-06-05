<?php
define('APPDIR', 'barpos');
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/print/receipt_printer.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

$id = (int)($_POST['sale_id'] ?? $_GET['sale_id'] ?? 0);
if (!$id) { echo json_encode(['ok'=>false,'error'=>'No sale ID']); exit; }

$ok = printSaleReceipt($conn, $id);
echo json_encode(['ok'=>$ok]);
exit;
