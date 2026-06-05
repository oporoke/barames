<?php
define('APPDIR', 'barpos');
require_once '../../includes/db.php';

$id  = (int)($_GET['id'] ?? 0);
$row = $conn->query("SELECT s.*, c.name AS cat FROM sales s JOIN categories c ON s.category_id=c.id WHERE s.id=$id")->fetch_assoc();
if (!$row) { echo "Sale not found."; exit; }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Receipt #<?= $id ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Courier New', monospace; font-size: 13px; max-width: 320px; margin: 0 auto; padding: 20px 10px; }
  .center { text-align: center; }
  .divider { border-top: 1px dashed #333; margin: 10px 0; }
  .row { display: flex; justify-content: space-between; margin: 4px 0; }
  .total-row { font-size: 16px; font-weight: bold; margin-top: 8px; }
  h2 { font-size: 16px; margin: 6px 0; }
  @media print { body { max-width: 100%; } button { display: none; } }
</style>
</head>
<body>
<div class="center">
  <h2>BAR POS SYSTEM</h2>
  <p>Receipt #<?= str_pad($id, 6, '0', STR_PAD_LEFT) ?></p>
  <p><?= date('d M Y H:i', strtotime($row['created_at'])) ?></p>
</div>
<div class="divider"></div>
<div class="row"><span>Department</span><span><?= htmlspecialchars($row['cat']) ?></span></div>
<?php if ($row['description']): ?>
<div class="row"><span>Item</span><span><?= htmlspecialchars($row['description']) ?></span></div>
<?php endif; ?>
<?php
$hasQty = isset($row['quantity']) && $row['quantity'] != 1;
if ($hasQty): ?>
<div class="row"><span>Qty x Price</span><span><?= $row['quantity'] ?> x <?= number_format($row['unit_price'] ?? 0, 0) ?></span></div>
<?php endif; ?>
<div class="divider"></div>
<div class="row total-row"><span>TOTAL</span><span>TZS <?= number_format($row['amount'], 2) ?></span></div>
<?php if (isset($row['payment_method'])): ?>
<div class="row"><span>Paid via</span><span><?= ucwords(str_replace('_',' ',$row['payment_method'])) ?></span></div>
<?php endif; ?>
<div class="divider"></div>
<div class="center"><p>Thank you!</p></div>
<br>
<div class="center">
  <button onclick="window.print()" style="padding:8px 20px;font-size:13px;cursor:pointer;border-radius:4px;border:1px solid #333;background:#fff;">
    &#128424; Print Receipt
  </button>
</div>
</body>
</html>
