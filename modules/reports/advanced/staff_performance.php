<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

$rows = q($conn, "
    SELECT u.id, u.name, u.role,
           SUM(CASE WHEN st.type='sale' THEN st.total ELSE 0 END) AS sales_total,
           COUNT(CASE WHEN st.type='sale' THEN 1 END) AS sale_txns,
           COUNT(CASE WHEN st.type='void' THEN 1 END) AS void_txns,
           COALESCE(SUM(CASE WHEN st.type='void' THEN st.total ELSE 0 END),0) AS void_total,
           COALESCE(SUM(CASE WHEN st.type='sale' THEN st.discount ELSE 0 END),0) AS discount_total,
           COALESCE(AVG(CASE WHEN st.type='sale' THEN st.total END),0) AS avg_ticket
    FROM users u
    LEFT JOIN sale_transactions st ON st.cashier_id = u.id AND st.sale_date BETWEEN ? AND ?
    WHERE u.role IN ('cashier','manager','admin')
    GROUP BY u.id, u.name, u.role
    ORDER BY sales_total DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$maxSales = max(array_column($rows, 'sales_total')) ?: 1;
$totalSales = array_sum(array_column($rows, 'sales_total'));
$totalVoids = array_sum(array_column($rows, 'void_txns'));
$totalDiscount = array_sum(array_column($rows, 'discount_total'));

layoutHead('Staff Performance', 'Sales, voids and discounts per staff member', 'staff', 'staff_performance');
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi green">
    <div class="kpi-label">Total Sales</div>
    <div class="kpi-value">TZS <?= number_format($totalSales, 0) ?></div>
    <div class="kpi-sub"><?= count($rows) ?> staff</div>
  </div>
  <div class="rp-kpi red">
    <div class="kpi-label">Total Voids</div>
    <div class="kpi-value"><?= $totalVoids ?></div>
    <div class="kpi-sub">transactions voided</div>
  </div>
  <div class="rp-kpi amber">
    <div class="kpi-label">Total Discounts Given</div>
    <div class="kpi-value">TZS <?= number_format($totalDiscount, 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
</div>

<?php if (empty($rows)): ?>
  <div class="rp-card"><div class="rp-empty">No staff sales activity in this period.</div></div>
<?php else: ?>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Per-Staff Breakdown</div>
  <table class="rp-table">
    <thead><tr><th>Staff</th><th>Role</th><th class="num">Sales</th><th class="num">Txns</th><th class="num">Avg Ticket</th><th class="num">Voids</th><th class="num">Discounts</th><th>Share</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pct = round(($r['sales_total']/$maxSales)*100); $voidFlag = $r['void_txns'] > 3; ?>
      <tr>
        <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
        <td style="text-transform:capitalize;"><?= htmlspecialchars($r['role']) ?></td>
        <td class="num">TZS <?= number_format($r['sales_total'], 0) ?></td>
        <td class="num"><?= $r['sale_txns'] ?></td>
        <td class="num">TZS <?= number_format($r['avg_ticket'], 0) ?></td>
        <td class="num"><?= $voidFlag ? '<span class="mpill low">'.$r['void_txns'].'</span>' : $r['void_txns'] ?></td>
        <td class="num">TZS <?= number_format($r['discount_total'], 0) ?></td>
        <td><div class="bar-wrap"><div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:var(--green);"></div></div><div class="bar-pct"><?= $pct ?>%</div></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; layoutFoot(); ?>
