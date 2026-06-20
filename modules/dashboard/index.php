<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireLogin();

/* Trims trailing zeros off decimal quantities for display (15.000 -> 15, 1.500 -> 1.5) */
function trimNum($v) {
    return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
}
/* Renders null-safe percentage; null means no comparison data exists for prior period */
function pctDisplay($val) {
    return $val === null ? 'N/A' : number_format($val, 1) . '%';
}

/* Net sales (gross sale - refunds) for any date range, reusable for WTD/MTD/custom ranges */
function getNetSales($conn, $start, $end) {
    $start = $conn->real_escape_string($start);
    $end   = $conn->real_escape_string($end);
    $gross = $conn->query("SELECT COALESCE(SUM(total),0) AS t FROM sale_transactions WHERE sale_date BETWEEN '$start' AND '$end' AND type='sale' AND tab_status='closed'")->fetch_assoc()['t'];
    $refunds = $conn->query("SELECT COALESCE(SUM(total),0) AS t FROM sale_transactions WHERE sale_date BETWEEN '$start' AND '$end' AND type='refund'")->fetch_assoc()['t'];
    return $gross - $refunds;
}

$today     = getTodayDate();
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
$todayTs = strtotime($today);

/* Week-to-date: Monday of current week through today; compared to same weekday-count last week */
$weekStartTs    = strtotime('monday this week', $todayTs);
$weekStart      = date('Y-m-d', $weekStartTs);
$lastWeekStart  = date('Y-m-d', strtotime('-7 days', $weekStartTs));
$lastWeekEnd    = date('Y-m-d', strtotime('-7 days', $todayTs));

/* Month-to-date: 1st of current month through today; compared to same day-count last month,
   capped at last month's actual length to avoid invalid dates (e.g. Feb 30) */
$monthStart       = date('Y-m-01', $todayTs);
$lastMonthStartTs = strtotime('-1 month', $todayTs);
$lastMonthStart   = date('Y-m-01', $lastMonthStartTs);
$dayOfMonth       = (int)date('j', $todayTs);
$lastMonthLength  = (int)date('t', strtotime($lastMonthStart));
$lastMonthEnd     = date('Y-m-d', strtotime($lastMonthStart . ' +' . (min($dayOfMonth, $lastMonthLength) - 1) . ' days'));

/* ---------- Sales (sale_transactions + sale_items) ---------- */
/* Revenue = type='sale' AND tab_status='closed' only. Open tabs aren't revenue yet, voided sales never happened. */

$grossSalesToday = $conn->query("
    SELECT COALESCE(SUM(total),0) AS t
    FROM sale_transactions
    WHERE sale_date='$today' AND type='sale' AND tab_status='closed'
")->fetch_assoc()['t'];

$refundsToday = $conn->query("
    SELECT COALESCE(SUM(total),0) AS t
    FROM sale_transactions
    WHERE sale_date='$today' AND type='refund'
")->fetch_assoc()['t'];

$netSalesToday = $grossSalesToday - $refundsToday;

$txnCountToday = $conn->query("
    SELECT COUNT(*) AS c
    FROM sale_transactions
    WHERE sale_date='$today' AND type='sale' AND tab_status='closed'
")->fetch_assoc()['c'];

$avgTicketToday = $txnCountToday > 0 ? $netSalesToday / $txnCountToday : 0;

$grossSalesYesterday = $conn->query("
    SELECT COALESCE(SUM(total),0) AS t
    FROM sale_transactions
    WHERE sale_date='$yesterday' AND type='sale' AND tab_status='closed'
")->fetch_assoc()['t'];

$pctChangeVsYesterday = $grossSalesYesterday > 0
    ? (($grossSalesToday - $grossSalesYesterday) / $grossSalesYesterday) * 100
    : null;

$voidRefundToday = $conn->query("
    SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS amt
    FROM sale_transactions
    WHERE sale_date='$today' AND type IN ('void','refund')
")->fetch_assoc();

/* ---------- Expenses & Net (unchanged source) ---------- */

$totalExpensesToday = $conn->query("
    SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE expense_date='$today'
")->fetch_assoc()['t'];

$netRevenueToday = $netSalesToday - $totalExpensesToday;

/* ---------- COGS & Net Profit ---------- */
/* COGS only covers sale_items linked to a stock_item_id (cost_price source).
   Items with no stock_item_id (ad-hoc/manual entries) are excluded from COGS
   and will understate true cost — tracked via $unlinkedItemsToday below. */

$cogsToday = $conn->query("
    SELECT COALESCE(SUM(si.quantity * stk.cost_price), 0) AS t
    FROM sale_items si
    JOIN sale_transactions st ON si.transaction_id = st.id
    JOIN stock_items stk ON si.stock_item_id = stk.id
    WHERE st.sale_date='$today' AND st.type='sale' AND st.tab_status='closed'
")->fetch_assoc()['t'];
$grossMarginPctToday = $netSalesToday > 0 ? (($netSalesToday - $cogsToday) / $netSalesToday) * 100 : null;
$unlinkedItemsToday = $conn->query("
    SELECT COUNT(*) AS c
    FROM sale_items si
    JOIN sale_transactions st ON si.transaction_id = st.id
    WHERE st.sale_date='$today' AND st.type='sale' AND st.tab_status='closed'
      AND si.stock_item_id IS NULL
")->fetch_assoc()['c'];

$netProfitToday = $netSalesToday - $cogsToday - $totalExpensesToday;

/* ---------- Category split ---------- */

$drinksSales = $conn->query("
    SELECT COALESCE(SUM(si.line_total),0) AS t
    FROM sale_items si
    JOIN sale_transactions st ON si.transaction_id = st.id
    JOIN categories c ON si.category_id = c.id
    WHERE c.type='drinks' AND st.sale_date='$today' AND st.type='sale' AND st.tab_status='closed'
")->fetch_assoc()['t'];

$kitchenSales = $conn->query("
    SELECT COALESCE(SUM(si.line_total),0) AS t
    FROM sale_items si
    JOIN sale_transactions st ON si.transaction_id = st.id
    JOIN categories c ON si.category_id = c.id
    WHERE c.type='kitchen' AND st.sale_date='$today' AND st.type='sale' AND st.tab_status='closed'
")->fetch_assoc()['t'];
$drinksCOGS = $conn->query("
    SELECT COALESCE(SUM(si.quantity * stk.cost_price), 0) AS t
    FROM sale_items si
    JOIN sale_transactions st ON si.transaction_id = st.id
    JOIN categories c ON si.category_id = c.id
    JOIN stock_items stk ON si.stock_item_id = stk.id
    WHERE c.type='drinks' AND st.sale_date='$today' AND st.type='sale' AND st.tab_status='closed'
")->fetch_assoc()['t'];

$kitchenCOGS = $conn->query("
    SELECT COALESCE(SUM(si.quantity * stk.cost_price), 0) AS t
    FROM sale_items si
    JOIN sale_transactions st ON si.transaction_id = st.id
    JOIN categories c ON si.category_id = c.id
    JOIN stock_items stk ON si.stock_item_id = stk.id
    WHERE c.type='kitchen' AND st.sale_date='$today' AND st.type='sale' AND st.tab_status='closed'
")->fetch_assoc()['t'];

$drinksMarginPct  = $drinksSales  > 0 ? (($drinksSales  - $drinksCOGS)  / $drinksSales)  * 100 : null;
$kitchenMarginPct = $kitchenSales > 0 ? (($kitchenSales - $kitchenCOGS) / $kitchenSales) * 100 : null;
/* ---------- Payment method breakdown ---------- */

$paymentBreakdown = $conn->query("
    SELECT payment_method, COALESCE(SUM(total),0) AS t, COUNT(*) AS c
    FROM sale_transactions
    WHERE sale_date='$today' AND type='sale' AND tab_status='closed'
    GROUP BY payment_method
    ORDER BY t DESC
");

/* ---------- Top selling items today ---------- */

$topItems = $conn->query("
    SELECT
        COALESCE(NULLIF(si.description,''), stk.name, CONCAT('Item #', si.id)) AS item_name,
        SUM(si.quantity) AS qty,
        SUM(si.line_total) AS revenue
    FROM sale_items si
    JOIN sale_transactions st ON si.transaction_id = st.id
    LEFT JOIN stock_items stk ON si.stock_item_id = stk.id
    WHERE st.sale_date='$today' AND st.type='sale' AND st.tab_status='closed'
    GROUP BY item_name
    ORDER BY revenue DESC
    LIMIT 5
");

/* ---------- Open tabs ---------- */

$openTabs = $conn->query("
    SELECT st.id, rt.name AS table_name, st.total,
           TIMESTAMPDIFF(MINUTE, st.created_at, NOW()) AS minutes_open
    FROM sale_transactions st
    LEFT JOIN restaurant_tables rt ON st.table_id = rt.id
    WHERE st.tab_status='open'
    ORDER BY st.created_at ASC
");
$openTabsCount = $openTabs->num_rows;

/* ---------- Active shift ---------- */

$activeShift = $conn->query("
    SELECT sh.id, u.name AS cashier_name, sh.opening_float, sh.opened_at
    FROM shifts sh
    JOIN users u ON sh.cashier_id = u.id
    WHERE sh.status='open'
    ORDER BY sh.opened_at DESC
    LIMIT 1
")->fetch_assoc();

/* ---------- Stock movement today ---------- */

$stockMovementToday = ['in' => 0, 'out' => 0, 'adjustment' => 0];
$movRes = $conn->query("
    SELECT movement_type, COALESCE(SUM(quantity),0) AS qty
    FROM stock_movements
    WHERE moved_at='$today'
    GROUP BY movement_type
");
while ($row = $movRes->fetch_assoc()) {
    $stockMovementToday[$row['movement_type']] = $row['qty'];
}

/* ---------- Low stock ---------- */

$lowStock = $conn->query("
    SELECT s.name, s.quantity, s.low_stock_threshold, c.name AS cat
    FROM stock_items s
    JOIN categories c ON s.category_id = c.id
    WHERE s.quantity <= s.low_stock_threshold AND s.is_active=1
    ORDER BY s.quantity ASC
    LIMIT 8
");

/* ---------- Hourly sales trend ---------- */

$hourly = array_fill(0, 24, 0);
$hourRes = $conn->query("
    SELECT HOUR(created_at) AS hr, COALESCE(SUM(total),0) AS t
    FROM sale_transactions
    WHERE sale_date='$today' AND type='sale' AND tab_status='closed'
    GROUP BY HOUR(created_at)
");
while ($row = $hourRes->fetch_assoc()) {
    $hourly[(int)$row['hr']] = (float)$row['t'];
}

/* ---------- Trends (WTD / MTD) ---------- */

$wtdSales         = getNetSales($conn, $weekStart, $today);
$wtdLastWeekSales = getNetSales($conn, $lastWeekStart, $lastWeekEnd);
$wtdPctChange     = $wtdLastWeekSales > 0 ? (($wtdSales - $wtdLastWeekSales) / $wtdLastWeekSales) * 100 : null;

$mtdSales          = getNetSales($conn, $monthStart, $today);
$mtdLastMonthSales = getNetSales($conn, $lastMonthStart, $lastMonthEnd);
$mtdPctChange      = $mtdLastMonthSales > 0 ? (($mtdSales - $mtdLastMonthSales) / $mtdLastMonthSales) * 100 : null;

/* ---------- Inventory value & Purchase Orders ---------- */

$stockValue = $conn->query("
    SELECT COALESCE(SUM(quantity * cost_price), 0) AS t
    FROM stock_items
    WHERE is_active = 1
")->fetch_assoc()['t'];

$pendingPOs = $conn->query("
    SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS t
    FROM purchase_orders
    WHERE status IN ('pending', 'partial')
")->fetch_assoc();

$pendingPOList = $conn->query("
    SELECT po.id, s.name AS supplier_name, po.order_date, po.status, po.total_amount
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.status IN ('pending', 'partial')
    ORDER BY po.order_date ASC
    LIMIT 5
");

require_once '../../includes/header.php';
?>
<h1 class="page-title">Dashboard &mdash; <?= date('d M Y') ?></h1>
<?php showFlash(); ?>

<div class="stat-grid">
    <div class="stat-card green">
        <div class="label">Net Sales Today</div>
        <div class="value"><?= formatMoney($netSalesToday) ?></div>
        <?php if ($pctChangeVsYesterday !== null): ?>
            <div class="sub" style="color:<?= $pctChangeVsYesterday >= 0 ? '#10b981' : '#ef4444' ?>">
                <?= $pctChangeVsYesterday >= 0 ? '&#9650;' : '&#9660;' ?> <?= number_format(abs($pctChangeVsYesterday), 1) ?>% vs yesterday
            </div>
        <?php else: ?>
            <div class="sub">No data yesterday to compare</div>
        <?php endif; ?>
        <div class="sub">Gross margin: <?= pctDisplay($grossMarginPctToday) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Transactions Today</div>
        <div class="value"><?= (int)$txnCountToday ?></div>
        <div class="sub">Avg ticket: <?= formatMoney($avgTicketToday) ?></div>
    </div>
    <div class="stat-card red">
        <div class="label">Total Expenses Today</div>
        <div class="value"><?= formatMoney($totalExpensesToday) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Net Revenue Today</div>
        <div class="value" style="color:<?= $netRevenueToday >= 0 ? '#10b981' : '#ef4444' ?>"><?= formatMoney($netRevenueToday) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Net Profit Today</div>
        <div class="value" style="color:<?= $netProfitToday >= 0 ? '#10b981' : '#ef4444' ?>"><?= formatMoney($netProfitToday) ?></div>
        <div class="sub">Sales &minus; COGS (<?= formatMoney($cogsToday) ?>) &minus; Expenses</div>
        <?php if ($unlinkedItemsToday > 0): ?>
            <div class="sub" style="color:#f59e0b">&#9888; <?= (int)$unlinkedItemsToday ?> item(s) unlinked to stock — COGS understated</div>
        <?php endif; ?>
    </div>
    <div class="stat-card blue">
        <div class="label">Drinks Sales</div>
        <div class="value"><?= formatMoney($drinksSales) ?></div>
        <div class="sub">Margin: <?= pctDisplay($drinksMarginPct) ?></div>
    </div>
    <div class="stat-card blue">
        <div class="label">Kitchen Sales</div>
        <div class="value"><?= formatMoney($kitchenSales) ?></div>
        <div class="sub">Margin: <?= pctDisplay($kitchenMarginPct) ?></div>
    </div>
</div>

<?php if ($voidRefundToday['c'] > 0): ?>
<div class="card">
    <h3>&#9888; Voids &amp; Refunds Today</h3>
    <p><?= (int)$voidRefundToday['c'] ?> transaction(s) totalling <?= formatMoney($voidRefundToday['amt']) ?> &mdash; worth a quick review.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3>Hourly Sales Trend</h3>
    <canvas id="hourlySalesChart" height="80"></canvas>
</div>
<div class="card">
    <h3>Sales Trends</h3>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="label">Week to Date</div>
            <div class="value"><?= formatMoney($wtdSales) ?></div>
            <?php if ($wtdPctChange !== null): ?>
                <div class="sub" style="color:<?= $wtdPctChange >= 0 ? '#10b981' : '#ef4444' ?>">
                    <?= $wtdPctChange >= 0 ? '&#9650;' : '&#9660;' ?> <?= number_format(abs($wtdPctChange), 1) ?>% vs last week
                </div>
            <?php else: ?>
                <div class="sub">No data last week to compare</div>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="label">Month to Date</div>
            <div class="value"><?= formatMoney($mtdSales) ?></div>
            <?php if ($mtdPctChange !== null): ?>
                <div class="sub" style="color:<?= $mtdPctChange >= 0 ? '#10b981' : '#ef4444' ?>">
                    <?= $mtdPctChange >= 0 ? '&#9650;' : '&#9660;' ?> <?= number_format(abs($mtdPctChange), 1) ?>% vs last month
                </div>
            <?php else: ?>
                <div class="sub">No data last month to compare</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <h3>Payment Methods Today</h3>
    <table>
        <thead><tr><th>Method</th><th>Transactions</th><th>Amount</th></tr></thead>
        <tbody>
        <?php if ($paymentBreakdown->num_rows === 0): ?>
            <tr><td colspan="3">No sales recorded yet today.</td></tr>
        <?php else: while ($row = $paymentBreakdown->fetch_assoc()): ?>
            <tr>
                <td><?= ucfirst(str_replace('_', ' ', $row['payment_method'])) ?></td>
                <td><?= (int)$row['c'] ?></td>
                <td><?= formatMoney($row['t']) ?></td>
            </tr>
        <?php endwhile; endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Top Selling Items Today</h3>
    <table>
        <thead><tr><th>Item</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
        <tbody>
        <?php if ($topItems->num_rows === 0): ?>
            <tr><td colspan="3">No items sold yet today.</td></tr>
        <?php else: while ($row = $topItems->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['item_name']) ?></td>
                <td><?= trimNum($row['qty']) ?></td>
                <td><?= formatMoney($row['revenue']) ?></td>
            </tr>
        <?php endwhile; endif; ?>
        </tbody>
    </table>
</div>
<div class="stat-grid">
    <div class="stat-card">
        <div class="label">Stock Value on Hand</div>
        <div class="value"><?= formatMoney($stockValue) ?></div>
        <div class="sub">Active items, cost basis</div>
    </div>
    <div class="stat-card">
        <div class="label">Pending Purchase Orders</div>
        <div class="value"><?= (int)$pendingPOs['c'] ?></div>
        <div class="sub"><?= formatMoney($pendingPOs['t']) ?> outstanding</div>
    </div>
</div>

<?php if ($pendingPOList->num_rows > 0): ?>
<div class="card">
    <h3>Pending Purchase Orders</h3>
    <table>
        <thead><tr><th>Supplier</th><th>Order Date</th><th>Status</th><th>Amount</th></tr></thead>
        <tbody>
        <?php while ($row = $pendingPOList->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                <td><?= date('d M Y', strtotime($row['order_date'])) ?></td>
                <td><span class="badge"><?= ucfirst($row['status']) ?></span></td>
                <td><?= formatMoney($row['total_amount']) ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="label">Open Tabs</div>
        <div class="value"><?= (int)$openTabsCount ?></div>
        <div class="sub"><?= $openTabsCount > 0 ? 'Awaiting payment' : 'All clear' ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Active Shift</div>
        <?php if ($activeShift): ?>
            <div class="value" style="font-size:1rem"><?= htmlspecialchars($activeShift['cashier_name']) ?></div>
            <div class="sub">Opened <?= date('H:i', strtotime($activeShift['opened_at'])) ?> &middot; Float <?= formatMoney($activeShift['opening_float']) ?></div>
        <?php else: ?>
            <div class="value" style="color:#ef4444; font-size:1rem">No shift open</div>
        <?php endif; ?>
    </div>
    <div class="stat-card">
        <div class="label">Stock In Today</div>
        <div class="value"><?= trimNum($stockMovementToday['in']) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Stock Out Today</div>
        <div class="value"><?= trimNum($stockMovementToday['out']) ?></div>
    </div>
</div>

<?php if ($openTabsCount > 0): ?>
<div class="card">
    <h3>Open Tabs</h3>
    <table>
        <thead><tr><th>Table</th><th>Running Total</th><th>Open For</th></tr></thead>
        <tbody>
        <?php while ($row = $openTabs->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['table_name'] ?? 'Walk-in') ?></td>
                <td><?= formatMoney($row['total']) ?></td>
                <td><?= (int)$row['minutes_open'] ?> min</td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($lowStock->num_rows > 0): ?>
<div class="card">
    <h3>&#9888; Low Stock Alerts</h3>
    <table>
        <thead><tr><th>Item</th><th>Category</th><th>Qty</th><th>Threshold</th></tr></thead>
        <tbody>
        <?php while ($row = $lowStock->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><span class="badge badge-<?= $row['cat'] === 'Drinks' ? 'drinks' : 'kitchen' ?>"><?= $row['cat'] ?></span></td>
                <td>
                    <?php if ($row['quantity'] <= 0): ?>
                        <span class="badge badge-low" style="background:#ef4444;color:#fff">OUT</span>
                    <?php else: ?>
                        <span class="badge badge-low"><?= $row['quantity'] ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $row['low_stock_threshold'] ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('hourlySalesChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23))) ?>,
        datasets: [{
            label: 'Sales',
            data: <?= json_encode(array_values($hourly)) ?>,
            backgroundColor: '#3b82f6'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
