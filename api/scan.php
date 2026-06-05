<?php
define('APPDIR', 'barpos');
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/auth.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['found'=>false,'error'=>'Unauthorized']); exit;
}

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode(['found'=>false]); exit; }

$safe = $conn->real_escape_string($code);
$item = $conn->query("
    SELECT si.id, si.name, si.sku, si.barcode, si.selling_price, si.quantity, si.unit,
           c.id AS category_id, c.name AS cat_name
    FROM stock_items si
    JOIN categories c ON si.category_id = c.id
    WHERE si.is_active = 1
      AND (si.sku = '$safe' OR si.barcode = '$safe')
    LIMIT 1
")->fetch_assoc();

echo json_encode($item ? ['found'=>true,'item'=>$item] : ['found'=>false,'message'=>"No item: $code"]);
exit;
