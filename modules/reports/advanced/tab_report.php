<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

// Currently open tabs (live, regardless of date filter)
$openTabs = q($conn, "
    SELECT st.id, st.total, st.created_at, t.name AS table_name, u.name AS cashier_name,
           TIMESTAMPDIFF(MINUTE, st.created_at, NOW()) AS minutes_open
    FROM sale_transactions st
    LEFT JOIN restaurant_tables t ON t.id = st.table_id
    LEFT JOIN users u ON u.id = st.cashier_id
    WHERE st.tab_status = 'open'
    ORDER BY st.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Closed tabs in range - duration & average size
$closedTabs = q($conn, "
    SELECT st.id, st.total, t.name AS table_name,
           TIMESTAMPDIFF(MINUTE, st.created_at, st.created_at) AS dummy
    FROM sale_transactions st
    LEFT JOIN restaurant_tables t ON t.id = st.table_id
    WHERE st.tab_status = 'closed' AND st.table_id IS NOT NULL
      AND st.sale_date BETWEEN ? AND ?
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$avgTabSize = !empty($closedTabs) ? array_sum(array_column($closedTabs, 'total')) / count($closedTabs) : 0;

// By table - turnover
$byTable = q($conn, "
    SELECT t.name, COUNT(st.id) AS txns, COALESCE(SUM(st.total),0) AS revenue
    FROM restaurant_tables t
    LEFT JOIN sale_transactions st ON st.table_id = t.id AND st.type='sale' AND st.sale_date BETWEEN ? AND ?
    WHERE t.is_active = 1
    GROUP BY t.id, t.name
    ORDER BY revenue DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$longOpenThreshold = 120; // minutes

layoutHead('Open Tabs', 'Table turnover and tab duration', 'tabs', null);
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; Closed-tab stats: <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?> &middot; Open tabs are live</div>

<div class="rp-kpi-grid">
  <div class="rp-kpi amber">
    <div class="kpi-label">Currently Open Tabs</div>
    <div class="kpi-value"><?= count($openTabs) ?></div>
    <div class="kpi-sub">live right now</div>
  </div>
  <div class="rp-kpi blue">
    <div class="kpi-label">Avg Tab Size</div>
    <div class="kpi-value">TZS <?= number_format($avgTabSize, 0) ?></div>
    <div class="kpi-sub">closed tabs in range</div>
  </div>
  <div class="rp-kpi green">
    <div class="kpi-label">Closed Tabs (range)</div>
    <div class="kpi-value"><?= count($closedTabs) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Currently Open Tabs</div>
  <?php if (empty($openTabs)): ?>
    <div class="rp-empty">No tabs currently open.</div>
  <?php else: ?>
  <table class="rp-table">
    <thead><tr><th>Table</th><th>Cashier</th><th>Opened</th><th class="num">Minutes Open</th><th class="num">Running Total</th></tr></thead>
    <tbody>
    <?php foreach ($openTabs as $t): $long = $t['minutes_open'] > $longOpenThreshold; ?>
      <tr>
        <td><strong><?= htmlspecialchars($t['table_name'] ?? 'Walk-in') ?></strong></td>
        <td><?= htmlspecialchars($t['cashier_name'] ?? '—') ?></td>
        <td><?= date('d M, H:i', strtotime($t['created_at'])) ?></td>
        <td class="num"><?= $long ? '<span class="mpill low">'.$t['minutes_open'].' min</span>' : $t['minutes_open'].' min' ?></td>
        <td class="num">TZS <?= number_format($t['total'],0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Table Turnover (closed-sale revenue by table)</div>
  <table class="rp-table">
    <thead><tr><th>Table</th><th class="num">Transactions</th><th class="num">Revenue</th></tr></thead>
    <tbody>
    <?php foreach ($byTable as $t): ?>
      <tr><td><?= htmlspecialchars($t['name']) ?></td><td class="num"><?= $t['txns'] ?></td><td class="num">TZS <?= number_format($t['revenue'],0) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php layoutFoot(); ?>
