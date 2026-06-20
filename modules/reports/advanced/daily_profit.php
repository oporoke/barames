<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

// Sales split by Kitchen vs Bar (drinks) vs Other category type, + COGS per day
$salesRows = q($conn, "
    SELECT st.sale_date,
           SUM(CASE WHEN c.type='kitchen' THEN si.line_total ELSE 0 END) AS kitchen_sales,
           SUM(CASE WHEN c.type='drinks'  THEN si.line_total ELSE 0 END) AS bar_sales,
           SUM(CASE WHEN c.type NOT IN ('kitchen','drinks') OR c.type IS NULL THEN si.line_total ELSE 0 END) AS other_sales,
           SUM(si.line_total) AS gross_sales,
           SUM(si.quantity * COALESCE(sk.cost_price,0)) AS cogs
    FROM sale_transactions st
    JOIN sale_items si ON si.transaction_id = st.id
    LEFT JOIN categories c ON c.id = si.category_id
    LEFT JOIN stock_items sk ON sk.id = si.stock_item_id
    WHERE st.type = 'sale' AND st.sale_date BETWEEN ? AND ?
    GROUP BY st.sale_date
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$expenseRows = q($conn, "
    SELECT expense_date, COALESCE(SUM(amount),0) AS total
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
    GROUP BY expense_date
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$expenseByDate = [];
foreach ($expenseRows as $e) { $expenseByDate[$e['expense_date']] = (float)$e['total']; }

// Build full date range (so days with zero sales still show)
$days = [];
$cursor = strtotime($start);
$endTs = strtotime($end);
while ($cursor <= $endTs) {
    $d = date('Y-m-d', $cursor);
    $days[$d] = [
        'date' => $d,
        'kitchen_sales' => 0, 'bar_sales' => 0, 'other_sales' => 0,
        'gross_sales' => 0, 'cogs' => 0, 'expenses' => $expenseByDate[$d] ?? 0,
    ];
    $cursor = strtotime('+1 day', $cursor);
}
foreach ($salesRows as $r) {
    if (!isset($days[$r['sale_date']])) continue;
    $days[$r['sale_date']]['kitchen_sales'] = (float)$r['kitchen_sales'];
    $days[$r['sale_date']]['bar_sales'] = (float)$r['bar_sales'];
    $days[$r['sale_date']]['other_sales'] = (float)$r['other_sales'];
    $days[$r['sale_date']]['gross_sales'] = (float)$r['gross_sales'];
    $days[$r['sale_date']]['cogs'] = (float)$r['cogs'];
}

// Sort oldest -> newest for cumulative build-up, compute rows
ksort($days);
$cumulative = 0;
$table = [];
foreach ($days as $d) {
    $grossProfit = $d['gross_sales'] - $d['cogs'];
    $netProfit = $grossProfit - $d['expenses'];
    $cumulative += $netProfit;
    $table[] = array_merge($d, [
        'gross_profit' => $grossProfit,
        'net_profit' => $netProfit,
        'cumulative' => $cumulative,
    ]);
}
// Display newest first
$displayTable = array_reverse($table);

$totalGrossSales = array_sum(array_column($table, 'gross_sales'));
$totalCogs = array_sum(array_column($table, 'cogs'));
$totalGrossProfit = $totalGrossSales - $totalCogs;
$totalExpenses = array_sum(array_column($table, 'expenses'));
$totalNetProfit = $totalGrossProfit - $totalExpenses;
$avgDailyNet = count($table) > 0 ? $totalNetProfit / count($table) : 0;
$bestDay = !empty($table) ? array_reduce($table, fn($c,$i) => ($c===null || $i['net_profit']>$c['net_profit']) ? $i : $c) : null;
$worstDay = !empty($table) ? array_reduce($table, fn($c,$i) => ($c===null || $i['net_profit']<$c['net_profit']) ? $i : $c) : null;

layoutHead('Daily Profit Summary', 'Gross sales, COGS, expenses and net profit, day by day', 'daily_profit', 'daily_profit');
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi blue">
    <div class="kpi-label">Gross Sales</div>
    <div class="kpi-value">TZS <?= number_format($totalGrossSales, 0) ?></div>
    <div class="kpi-sub">Kitchen + Bar + Other</div>
  </div>
  <div class="rp-kpi amber">
    <div class="kpi-label">Total COGS</div>
    <div class="kpi-value">TZS <?= number_format($totalCogs, 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
  <div class="rp-kpi teal">
    <div class="kpi-label">Gross Profit</div>
    <div class="kpi-value">TZS <?= number_format($totalGrossProfit, 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
  <div class="rp-kpi green">
    <div class="kpi-label">Net Profit</div>
    <div class="kpi-value <?= $totalNetProfit>=0?'positive':'negative' ?>">TZS <?= number_format($totalNetProfit, 0) ?></div>
    <div class="kpi-sub">Avg/day: TZS <?= number_format($avgDailyNet,0) ?></div>
  </div>
</div>

<?php if ($bestDay && $worstDay): ?>
<div class="rp-2col">
  <div class="rp-card" style="border-color:var(--green);">
    <div class="rp-section-title" style="color:var(--green);"><span style="background:var(--green);"></span>Best Day</div>
    <div style="font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:700;"><?= date('d M Y', strtotime($bestDay['date'])) ?></div>
    <div style="color:var(--green);font-weight:600;margin-top:4px;">TZS <?= number_format($bestDay['net_profit'],0) ?> net profit</div>
  </div>
  <div class="rp-card" style="border-color:var(--red);">
    <div class="rp-section-title" style="color:var(--red);"><span style="background:var(--red);"></span>Worst Day</div>
    <div style="font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:700;"><?= date('d M Y', strtotime($worstDay['date'])) ?></div>
    <div style="color:var(--red);font-weight:600;margin-top:4px;">TZS <?= number_format($worstDay['net_profit'],0) ?> net profit</div>
  </div>
</div>
<?php endif; ?>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Daily P&amp;L (most recent first)</div>
  <table class="rp-table">
    <thead>
      <tr>
        <th>Date</th>
        <th class="num">Kitchen</th>
        <th class="num">Bar</th>
        <th class="num">Gross Sales</th>
        <th class="num">COGS</th>
        <th class="num">Gross Profit</th>
        <th class="num">Expenses</th>
        <th class="num">Net Profit</th>
        <th class="num">Cumulative</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($displayTable as $r): ?>
      <tr>
        <td><?= date('d M Y', strtotime($r['date'])) ?></td>
        <td class="num">TZS <?= number_format($r['kitchen_sales'],0) ?></td>
        <td class="num">TZS <?= number_format($r['bar_sales'],0) ?></td>
        <td class="num">TZS <?= number_format($r['gross_sales'],0) ?></td>
        <td class="num">TZS <?= number_format($r['cogs'],0) ?></td>
        <td class="num">TZS <?= number_format($r['gross_profit'],0) ?></td>
        <td class="num">TZS <?= number_format($r['expenses'],0) ?></td>
        <td class="num" style="color:<?= $r['net_profit']>=0?'var(--green)':'var(--red)' ?>;font-weight:600;">TZS <?= number_format($r['net_profit'],0) ?></td>
        <td class="num" style="color:<?= $r['cumulative']>=0?'var(--green)':'var(--red)' ?>;font-weight:700;">TZS <?= number_format($r['cumulative'],0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php layoutFoot(); ?>