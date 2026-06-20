<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

// Voids & refunds
$flagged = q($conn, "
    SELECT st.id, st.type, st.total, st.discount, st.note, st.sale_date, st.created_at,
           u.name AS cashier_name, st.ref_transaction_id
    FROM sale_transactions st
    LEFT JOIN users u ON u.id = st.cashier_id
    WHERE st.sale_date BETWEEN ? AND ? AND (st.type IN ('void','refund') OR st.discount > 0)
    ORDER BY st.created_at DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$voidCount = 0; $refundCount = 0; $voidTotal = 0; $refundTotal = 0; $discountTotal = 0; $discountCount = 0;
foreach ($flagged as $f) {
    if ($f['type'] === 'void')   { $voidCount++;   $voidTotal   += $f['total']; }
    if ($f['type'] === 'refund') { $refundCount++; $refundTotal += $f['total']; }
    if ($f['discount'] > 0)      { $discountCount++; $discountTotal += $f['discount']; }
}

// Top discount-givers
$byUser = q($conn, "
    SELECT u.name, COUNT(*) AS cnt, COALESCE(SUM(st.discount),0) AS total_discount
    FROM sale_transactions st
    JOIN users u ON u.id = st.cashier_id
    WHERE st.sale_date BETWEEN ? AND ? AND st.discount > 0
    GROUP BY u.name
    ORDER BY total_discount DESC
    LIMIT 10
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

// Audit log entries related to overrides/voids
$auditEntries = q($conn, "
    SELECT al.*, u.name AS uname
    FROM audit_log al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.created_at BETWEEN ? AND ?
      AND (al.action LIKE '%void%' OR al.action LIKE '%discount%' OR al.action LIKE '%refund%' OR al.action LIKE '%delete%')
    ORDER BY al.created_at DESC
    LIMIT 50
", [$start . ' 00:00:00', $end . ' 23:59:59'])->fetch_all(MYSQLI_ASSOC);

layoutHead('Void / Discount Audit', 'Shrinkage and fraud control', 'audit', 'void_audit');
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi red">
    <div class="kpi-label">Voids</div>
    <div class="kpi-value"><?= $voidCount ?></div>
    <div class="kpi-sub">TZS <?= number_format($voidTotal,0) ?> value</div>
  </div>
  <div class="rp-kpi amber">
    <div class="kpi-label">Refunds</div>
    <div class="kpi-value"><?= $refundCount ?></div>
    <div class="kpi-sub">TZS <?= number_format($refundTotal,0) ?> value</div>
  </div>
  <div class="rp-kpi blue">
    <div class="kpi-label">Discounted Sales</div>
    <div class="kpi-value"><?= $discountCount ?></div>
    <div class="kpi-sub">TZS <?= number_format($discountTotal,0) ?> total discount</div>
  </div>
</div>

<?php if (!empty($byUser)): ?>
<div class="rp-card">
  <div class="rp-section-title"><span></span>Top Discount-Givers</div>
  <table class="rp-table">
    <thead><tr><th>Staff</th><th class="num">Discounted Txns</th><th class="num">Total Discount</th></tr></thead>
    <tbody>
    <?php foreach ($byUser as $u): ?>
      <tr><td><?= htmlspecialchars($u['name']) ?></td><td class="num"><?= $u['cnt'] ?></td><td class="num">TZS <?= number_format($u['total_discount'],0) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Flagged Transactions (voids, refunds, discounts)</div>
  <?php if (empty($flagged)): ?>
    <div class="rp-empty">No voids, refunds, or discounts in this period.</div>
  <?php else: ?>
  <table class="rp-table">
    <thead><tr><th>Date</th><th>Type</th><th>Cashier</th><th class="num">Amount</th><th class="num">Discount</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($flagged as $f): ?>
      <tr>
        <td><?= date('d M, H:i', strtotime($f['created_at'])) ?></td>
        <td><span class="rp-badge <?= $f['type']==='void' ? 'void' : ($f['type']==='refund'?'void':'open') ?>"><?= ucfirst($f['type']) ?></span></td>
        <td><?= htmlspecialchars($f['cashier_name'] ?? '—') ?></td>
        <td class="num">TZS <?= number_format($f['total'],0) ?></td>
        <td class="num"><?= $f['discount']>0 ? 'TZS '.number_format($f['discount'],0) : '—' ?></td>
        <td><?= htmlspecialchars($f['note'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if (!empty($auditEntries)): ?>
<div class="rp-card">
  <div class="rp-section-title"><span></span>Related Audit Log Entries</div>
  <table class="rp-table">
    <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($auditEntries as $a): ?>
      <tr>
        <td><?= date('d M, H:i', strtotime($a['created_at'])) ?></td>
        <td><?= htmlspecialchars($a['uname'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['action']) ?></td>
        <td><?= htmlspecialchars($a['detail'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php layoutFoot(); ?>
