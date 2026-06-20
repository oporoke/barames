<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

$bySupplier = q($conn, "
    SELECT s.name,
           COUNT(DISTINCT po.id) AS po_count,
           COALESCE(SUM(poi.total_cost),0) AS total_spend,
           SUM(CASE WHEN po.status='pending' THEN 1 ELSE 0 END) AS pending_pos,
           SUM(CASE WHEN po.status='received' THEN 1 ELSE 0 END) AS received_pos
    FROM suppliers s
    LEFT JOIN purchase_orders po ON po.supplier_id = s.id AND po.order_date BETWEEN ? AND ?
    LEFT JOIN purchase_order_items poi ON poi.order_id = po.id
    WHERE s.is_active = 1
    GROUP BY s.id, s.name
    ORDER BY total_spend DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$totalSpend = array_sum(array_column($bySupplier, 'total_spend'));
$maxSpend = max(array_column($bySupplier, 'total_spend')) ?: 1;

// Fulfillment rate
$fulfillRows = q($conn, "
    SELECT poi.quantity_ordered, poi.quantity_received
    FROM purchase_order_items poi
    JOIN purchase_orders po ON po.id = poi.order_id
    WHERE po.order_date BETWEEN ? AND ?
", [$start, $end])->fetch_all(MYSQLI_ASSOC);
$ordered = array_sum(array_column($fulfillRows, 'quantity_ordered'));
$received = array_sum(array_column($fulfillRows, 'quantity_received'));
$fulfillRate = $ordered > 0 ? round(($received / $ordered) * 100, 1) : 0;

// Recent POs
$recentPOs = q($conn, "
    SELECT po.id, po.order_date, po.status, po.total_amount, s.name AS supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    WHERE po.order_date BETWEEN ? AND ?
    ORDER BY po.order_date DESC
    LIMIT 20
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

layoutHead('Supplier Spend', 'Purchase order spend and fulfillment', 'suppliers', 'supplier_spend');
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi blue">
    <div class="kpi-label">Total Spend</div>
    <div class="kpi-value">TZS <?= number_format($totalSpend, 0) ?></div>
    <div class="kpi-sub"><?= count($bySupplier) ?> suppliers</div>
  </div>
  <div class="rp-kpi green">
    <div class="kpi-label">Fulfillment Rate</div>
    <div class="kpi-value"><?= $fulfillRate ?>%</div>
    <div class="kpi-sub">qty received / ordered</div>
  </div>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Spend by Supplier</div>
  <?php if (empty($bySupplier)): ?>
    <div class="rp-empty">No suppliers found.</div>
  <?php else: ?>
  <table class="rp-table">
    <thead><tr><th>Supplier</th><th class="num">POs</th><th class="num">Pending</th><th class="num">Received</th><th class="num">Spend</th><th>Share</th></tr></thead>
    <tbody>
    <?php foreach ($bySupplier as $s): $pct = round(($s['total_spend']/$maxSpend)*100); ?>
      <tr>
        <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
        <td class="num"><?= $s['po_count'] ?></td>
        <td class="num"><?= $s['pending_pos'] ?></td>
        <td class="num"><?= $s['received_pos'] ?></td>
        <td class="num">TZS <?= number_format($s['total_spend'],0) ?></td>
        <td><div class="bar-wrap"><div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:var(--accent);"></div></div><div class="bar-pct"><?= $pct ?>%</div></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Recent Purchase Orders</div>
  <?php if (empty($recentPOs)): ?>
    <div class="rp-empty">No purchase orders in this period.</div>
  <?php else: ?>
  <table class="rp-table">
    <thead><tr><th>Date</th><th>Supplier</th><th>Status</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($recentPOs as $po):
        $badge = $po['status']==='received' ? 'closed' : ($po['status']==='cancelled' ? 'void' : 'open');
    ?>
      <tr>
        <td><?= date('d M Y', strtotime($po['order_date'])) ?></td>
        <td><?= htmlspecialchars($po['supplier_name']) ?></td>
        <td><span class="rp-badge <?= $badge ?>"><?= ucfirst($po['status']) ?></span></td>
        <td class="num">TZS <?= number_format($po['total_amount'],0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php layoutFoot(); ?>
