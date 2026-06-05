<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
requireRole('admin','manager');

$id    = (int)($_GET['id']??0);
$order = $conn->query("SELECT po.*,s.name AS supplier,s.phone,s.contact_person FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.id WHERE po.id=$id")->fetch_assoc();
if (!$order) { echo "Order not found."; exit; }
$items = $conn->query("SELECT poi.*,si.name AS item_name,si.unit FROM purchase_order_items poi JOIN stock_items si ON poi.stock_item_id=si.id WHERE poi.order_id=$id");
$statusColors=['pending'=>'#f59e0b','received'=>'#10b981','partial'=>'#3b82f6','cancelled'=>'#ef4444'];
$sc = $statusColors[$order['status']]??'#888';
require_once '../../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
  <h1 class="page-title" style="margin:0;">PO #<?= str_pad($id,4,'0',STR_PAD_LEFT) ?></h1>
  <div style="display:flex;gap:8px;">
    <a href="index.php" class="btn btn-secondary btn-sm">&#8592; Back</a>
    <button onclick="window.print()" class="btn btn-primary btn-sm">&#128424; Print</button>
  </div>
</div>
<div class="card">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap;">
    <div>
      <p style="font-size:.8rem;color:var(--muted);text-transform:uppercase;font-weight:600;">Supplier</p>
      <p><strong><?= htmlspecialchars($order['supplier']) ?></strong></p>
      <p style="color:var(--muted);"><?= htmlspecialchars($order['contact_person']??'') ?></p>
      <p style="color:var(--muted);"><?= htmlspecialchars($order['phone']??'') ?></p>
    </div>
    <div>
      <p style="font-size:.8rem;color:var(--muted);text-transform:uppercase;font-weight:600;">Order Details</p>
      <p>Date: <strong><?= date('d M Y',strtotime($order['order_date'])) ?></strong></p>
      <p>Status: <span class="badge" style="background:<?= $sc ?>22;color:<?= $sc ?>"><?= ucfirst($order['status']) ?></span></p>
      <?php if($order['notes']): ?><p style="color:var(--muted);">Note: <?= htmlspecialchars($order['notes']) ?></p><?php endif; ?>
    </div>
  </div>
</div>
<div class="card">
  <h3>Order Items</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th>Unit</th><th>Qty Ordered</th><th>Qty Received</th><th>Unit Cost (TZS)</th><th>Total (TZS)</th></tr></thead>
    <tbody>
    <?php $grand=0; while($row=$items->fetch_assoc()): $grand+=$row['total_cost']; ?>
    <tr>
      <td><?= htmlspecialchars($row['item_name']) ?></td>
      <td><?= htmlspecialchars($row['unit']) ?></td>
      <td><?= $row['quantity_ordered'] ?></td>
      <td><?= $row['quantity_received'] ?></td>
      <td><?= number_format($row['unit_cost'],0) ?></td>
      <td><?= number_format($row['total_cost'],0) ?></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
    <tfoot><tr><td colspan="5" style="text-align:right;font-weight:700;">TOTAL</td><td><strong>TZS <?= number_format($grand,0) ?></strong></td></tr></tfoot>
  </table></div>
</div>
<?php require_once '../../includes/footer.php'; ?>
