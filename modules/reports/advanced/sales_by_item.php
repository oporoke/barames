<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();
$sort = in_array($_GET['sort'] ?? '', ['qty','revenue']) ? $_GET['sort'] : 'revenue';

$rows = q($conn, "
    SELECT si.description AS item,
           c.name AS category,
           SUM(si.quantity) AS qty_sold,
           SUM(si.line_total) AS revenue,
           COUNT(DISTINCT si.transaction_id) AS txns
    FROM sale_items si
    JOIN sale_transactions st ON st.id = si.transaction_id
    LEFT JOIN categories c ON c.id = si.category_id
    WHERE st.type = 'sale' AND st.sale_date BETWEEN ? AND ?
    GROUP BY si.description, c.name
    ORDER BY " . ($sort === 'qty' ? 'qty_sold' : 'revenue') . " DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$totalRevenue = array_sum(array_column($rows, 'revenue'));
$totalQty     = array_sum(array_column($rows, 'qty_sold'));
$maxRevenue   = $totalRevenue > 0 ? max(array_column($rows, 'revenue')) : 1;
$worst = array_slice(array_reverse($rows), 0, 5);
$top   = array_slice($rows, 0, 10);

layoutHead('Sales by Item', 'Best & worst selling items', 'sales_by_item', 'sales_by_item');
rangeFilterBar($start, $end,
    '<div><label>Sort By</label><select name="sort"><option value="revenue"' . ($sort==='revenue'?' selected':'') . '>Revenue</option><option value="qty"' . ($sort==='qty'?' selected':'') . '>Quantity</option></select></div>'
);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi green">
    <div class="kpi-label">Total Revenue</div>
    <div class="kpi-value">TZS <?= number_format($totalRevenue, 0) ?></div>
    <div class="kpi-sub"><?= count($rows) ?> distinct items</div>
  </div>
  <div class="rp-kpi blue">
    <div class="kpi-label">Units Sold</div>
    <div class="kpi-value"><?= number_format($totalQty, 0) ?></div>
    <div class="kpi-sub">across all items</div>
  </div>
  <div class="rp-kpi teal">
    <div class="kpi-label">Top Seller</div>
    <div class="kpi-value" style="font-size:1.1rem;"><?= !empty($rows) ? htmlspecialchars($rows[0]['item']) : '—' ?></div>
    <div class="kpi-sub"><?= !empty($rows) ? 'TZS ' . number_format($rows[0]['revenue'],0) : '' ?></div>
  </div>
</div>

<?php if (empty($rows)): ?>
  <div class="rp-card"><div class="rp-empty">No sales recorded in this period.</div></div>
<?php else: ?>

<div class="rp-card">
  <div class="rp-section-title"><span></span>All Items (sorted by <?= $sort === 'qty' ? 'quantity' : 'revenue' ?>)</div>
  <table class="rp-table">
    <thead><tr><th>Item</th><th>Category</th><th class="num">Qty Sold</th><th class="num">Revenue</th><th class="num">Txns</th><th>Share</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pct = $maxRevenue > 0 ? round(($r['revenue']/$maxRevenue)*100) : 0; ?>
      <tr>
        <td><strong><?= htmlspecialchars($r['item']) ?></strong></td>
        <td><?= htmlspecialchars($r['category'] ?? '—') ?></td>
        <td class="num"><?= number_format($r['qty_sold'], 2) ?></td>
        <td class="num">TZS <?= number_format($r['revenue'], 0) ?></td>
        <td class="num"><?= $r['txns'] ?></td>
        <td>
          <div class="bar-wrap">
            <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:var(--accent);"></div></div>
            <div class="bar-pct"><?= $pct ?>%</div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="rp-card">
  <div class="rp-section-title" style="color:var(--red);"><span style="background:var(--red);"></span>Slowest Movers (lowest revenue in range)</div>
  <table class="rp-table">
    <thead><tr><th>Item</th><th class="num">Qty Sold</th><th class="num">Revenue</th></tr></thead>
    <tbody>
    <?php foreach ($worst as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['item']) ?></td>
        <td class="num"><?= number_format($r['qty_sold'], 2) ?></td>
        <td class="num">TZS <?= number_format($r['revenue'], 0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; layoutFoot(); ?>
