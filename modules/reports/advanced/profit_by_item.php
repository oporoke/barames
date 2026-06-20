<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

// Pull raw sale lines (not pre-aggregated) so we can resolve cost price
// per the date each sale actually happened, not today's price.
$lines = q($conn, "
    SELECT si.description AS item, si.stock_item_id, si.quantity, si.line_total, st.sale_date
    FROM sale_items si
    JOIN sale_transactions st ON st.id = si.transaction_id
    WHERE st.type = 'sale' AND st.sale_date BETWEEN ? AND ?
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

// Resolve cost price per distinct sale_date present in this range (one lookup per date, not per line)
$datesInRange = array_unique(array_column($lines, 'sale_date'));
$itemIdsInRange = array_unique(array_filter(array_column($lines, 'stock_item_id')));
$costByDateAndItem = []; // [date][stock_item_id] => ['cost'=>, 'is_estimated'=>]
foreach ($datesInRange as $d) {
    $costByDateAndItem[$d] = costPricesAsOf($conn, $itemIdsInRange, $d);
}

// Aggregate by item description, using the resolved historical cost per line
$rows = [];
$anyEstimated = [];
foreach ($lines as $l) {
    $key = $l['item'];
    if (!isset($rows[$key])) {
        $rows[$key] = ['item' => $key, 'qty_sold' => 0, 'revenue' => 0, 'cost' => 0];
    }
    $rows[$key]['qty_sold'] += (float)$l['quantity'];
    $rows[$key]['revenue']  += (float)$l['line_total'];

    $costInfo = $costByDateAndItem[$l['sale_date']][$l['stock_item_id']] ?? ['cost' => 0, 'is_estimated' => true];
    $rows[$key]['cost'] += (float)$l['quantity'] * $costInfo['cost'];
    if ($costInfo['is_estimated']) { $anyEstimated[$key] = true; }
}

foreach ($rows as $key => &$r) {
    $r['profit'] = $r['revenue'] - $r['cost'];
    $r['margin'] = $r['revenue'] > 0 ? round(($r['profit'] / $r['revenue']) * 100, 1) : 0;
    $r['cost_is_estimated'] = isset($anyEstimated[$key]);
}
unset($r);

usort($rows, fn($a, $b) => $b['profit'] <=> $a['profit']);
$rows = array_values($rows);

$totalRevenue = array_sum(array_column($rows, 'revenue'));
$totalCost    = array_sum(array_column($rows, 'cost'));
$totalProfit  = $totalRevenue - $totalCost;
$overallMargin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0;

$noCostItems = array_filter($rows, fn($r) => $r['cost'] == 0 && $r['revenue'] > 0);

layoutHead('Profit by Item', 'Per-item margin & contribution to profit', 'profit_by_item', 'profit_by_item');
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi green">
    <div class="kpi-label">Total Profit</div>
    <div class="kpi-value <?= $totalProfit>=0?'positive':'negative' ?>">TZS <?= number_format($totalProfit, 0) ?></div>
    <div class="kpi-sub">Margin: <?= $overallMargin ?>%</div>
  </div>
  <div class="rp-kpi blue">
    <div class="kpi-label">Total Revenue</div>
    <div class="kpi-value">TZS <?= number_format($totalRevenue, 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
  <div class="rp-kpi amber">
    <div class="kpi-label">Total COGS</div>
    <div class="kpi-value">TZS <?= number_format($totalCost, 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
</div>

<?php if (!empty($noCostItems)): ?>
<div class="rp-card" style="border-color:var(--amber);background:var(--amber-bg);">
  <strong>&#9888; <?= count($noCostItems) ?> item(s)</strong> have no cost price set — their margin shows as 100% but may be inaccurate. Set cost_price in Stock to fix.
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <div class="rp-card"><div class="rp-empty">No sales recorded in this period.</div></div>
<?php else: ?>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Profit Ranking</div>
  <table class="rp-table">
    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Revenue</th><th class="num">Cost</th><th class="num">Profit</th><th class="num">Margin</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= htmlspecialchars($r['item']) ?></strong></td>
        <td class="num"><?= number_format($r['qty_sold'], 2) ?></td>
        <td class="num">TZS <?= number_format($r['revenue'], 0) ?></td>
        <td class="num">TZS <?= number_format($r['cost'], 0) ?><?php if ($r['cost_is_estimated']): ?> <span title="Some lines used today's cost price — no historical record for that sale date" style="color:var(--amber);font-size:0.7rem;">*</span><?php endif; ?></td>
        <td class="num <?= $r['profit']>=0?'net-pos':'net-neg' ?>">TZS <?= number_format($r['profit'], 0) ?></td>
        <td class="num"><span class="mpill <?= marginClass($r['margin']) ?>"><?= $r['margin'] ?>%</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div style="margin-top:14px;font-size:0.76rem;color:var(--ink-muted);">
    Cost uses the price that was actually in effect on each sale's date, where known. <strong>*</strong> = no historical price record existed for that date, so today's cost price was used as a fallback.
  </div>
</div>

<?php endif; layoutFoot(); ?>