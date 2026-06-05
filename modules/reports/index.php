<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireRole('admin','manager');

$type = $_GET['type'] ?? 'daily';
$date = $_GET['date'] ?? date('Y-m-d');

switch ($type) {
    case 'weekly':
        $start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        $end   = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
        $label = "Weekly Report: $start to $end";
        break;
    case 'monthly':
        $start = date('Y-m-01', strtotime($date));
        $end   = date('Y-m-t',  strtotime($date));
        $label = "Monthly Report: " . date('F Y', strtotime($date));
        break;
    case 'yearly':
        $start = date('Y-01-01', strtotime($date));
        $end   = date('Y-12-31', strtotime($date));
        $label = "Year-to-Date Report: " . date('Y', strtotime($date));
        break;
    default:
        $start = $end = $date;
        $label = "Daily Report: " . date('d M Y', strtotime($date));
}

// â”€â”€ TOTALS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$totalSales    = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM sales WHERE sale_date BETWEEN '$start' AND '$end'")->fetch_assoc()['t'];
$totalExpenses = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE expense_date BETWEEN '$start' AND '$end'")->fetch_assoc()['t'];
$netProfit     = $totalSales - $totalExpenses;
$margin        = $totalSales > 0 ? round(($netProfit / $totalSales) * 100, 1) : 0;

// â”€â”€ SALES BY DEPARTMENT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$salesByDept = $conn->query("
    SELECT c.name AS cat, c.type, COALESCE(SUM(s.amount),0) AS total
    FROM categories c
    LEFT JOIN sales s ON s.category_id = c.id AND s.sale_date BETWEEN '$start' AND '$end'
    GROUP BY c.id ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);

// â”€â”€ EXPENSES BY DEPARTMENT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$expByDept = $conn->query("
    SELECT c.name AS cat, c.type, COALESCE(SUM(e.amount),0) AS total
    FROM categories c
    LEFT JOIN expenses e ON e.category_id = c.id AND e.expense_date BETWEEN '$start' AND '$end'
    GROUP BY c.id ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);

// â”€â”€ EXPENSE BREAKDOWN BY DESCRIPTION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$expBreakdown = $conn->query("
    SELECT e.description, c.name AS cat, SUM(e.amount) AS total, COUNT(*) AS cnt
    FROM expenses e
    JOIN categories c ON e.category_id = c.id
    WHERE e.expense_date BETWEEN '$start' AND '$end'
    GROUP BY e.description, e.category_id
    ORDER BY total DESC
    LIMIT 20
");

// â”€â”€ BEST SELLING ITEMS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$bestSelling = $conn->query("
    SELECT s.description, c.name AS cat, c.type,
           SUM(s.amount) AS total_revenue,
           COUNT(*) AS times_sold
    FROM sales s
    JOIN categories c ON s.category_id = c.id
    WHERE s.sale_date BETWEEN '$start' AND '$end'
      AND s.description != ''
    GROUP BY s.description, s.category_id
    ORDER BY total_revenue DESC
    LIMIT 10
");

// â”€â”€ PROFIT MARGIN PER STOCK ITEM â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$margins = $conn->query("
    SELECT name, cost_price, selling_price, unit,
           (selling_price - cost_price) AS profit,
           CASE WHEN selling_price > 0
                THEN ROUND(((selling_price - cost_price) / selling_price) * 100, 1)
                ELSE 0 END AS margin_pct
    FROM stock_items
    WHERE is_active = 1 AND selling_price > 0
    ORDER BY margin_pct DESC
");

// â”€â”€ DAILY BREAKDOWN (for weekly/monthly/yearly) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$dailyBreakdown = null;
if ($type !== 'daily') {
    $dailyBreakdown = $conn->query("
        SELECT d.day,
               COALESCE(s.total, 0) AS sales,
               COALESCE(e.total, 0) AS expenses,
               COALESCE(s.total, 0) - COALESCE(e.total, 0) AS net
        FROM (
            SELECT DISTINCT sale_date AS day FROM sales WHERE sale_date BETWEEN '$start' AND '$end'
            UNION
            SELECT DISTINCT expense_date FROM expenses WHERE expense_date BETWEEN '$start' AND '$end'
        ) d
        LEFT JOIN (SELECT sale_date, SUM(amount) AS total FROM sales WHERE sale_date BETWEEN '$start' AND '$end' GROUP BY sale_date) s ON s.sale_date = d.day
        LEFT JOIN (SELECT expense_date, SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN '$start' AND '$end' GROUP BY expense_date) e ON e.expense_date = d.day
        ORDER BY d.day
    ");
}

// â”€â”€ YEARLY MONTHLY SUMMARY â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$monthlySummary = null;
if ($type === 'yearly') {
    $monthlySummary = $conn->query("
        SELECT months.m,
               COALESCE(s.total, 0) AS sales,
               COALESCE(e.total, 0) AS expenses,
               COALESCE(s.total, 0) - COALESCE(e.total, 0) AS net
        FROM (
            SELECT 1 AS m UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
            UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8
            UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12
        ) months
        LEFT JOIN (
            SELECT MONTH(sale_date) AS m, SUM(amount) AS total
            FROM sales WHERE YEAR(sale_date) = YEAR('$start')
            GROUP BY MONTH(sale_date)
        ) s ON s.m = months.m
        LEFT JOIN (
            SELECT MONTH(expense_date) AS m, SUM(amount) AS total
            FROM expenses WHERE YEAR(expense_date) = YEAR('$start')
            GROUP BY MONTH(expense_date)
        ) e ON e.m = months.m
        ORDER BY months.m
    ");
}

require_once '../../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <h1 class="page-title" style="margin:0;">&#128202; Reports</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="export_pdf.php?type=<?= $type ?>&date=<?= $date ?>" target="_blank" class="btn btn-danger btn-sm">&#128196; Export PDF</a>
        <a href="export_excel.php?type=<?= $type ?>&date=<?= $date ?>" class="btn btn-success btn-sm">&#128196; Export Excel</a>
    </div>
</div>

<!-- FILTER -->
<div class="card">
    <form method="GET" action="" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group">
            <label>Report Type</label>
            <select name="type" onchange="this.form.submit()">
                <option value="daily"   <?= $type==='daily'  ?'selected':'' ?>>Daily</option>
                <option value="weekly"  <?= $type==='weekly' ?'selected':'' ?>>Weekly</option>
                <option value="monthly" <?= $type==='monthly'?'selected':'' ?>>Monthly</option>
                <option value="yearly"  <?= $type==='yearly' ?'selected':'' ?>>Year-to-Date</option>
            </select>
        </div>
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Generate</button>
    </form>
</div>

<h2 style="color:#1a1a2e;margin-bottom:16px;font-size:1.1rem;"><?= htmlspecialchars($label) ?></h2>

<!-- SUMMARY CARDS -->
<div class="stat-grid">
    <div class="stat-card green">
        <div class="label">Total Revenue</div>
        <div class="value">TZS <?= number_format($totalSales, 2) ?></div>
    </div>
    <div class="stat-card red">
        <div class="label">Total Expenses</div>
        <div class="value">TZS <?= number_format($totalExpenses, 2) ?></div>
    </div>
    <div class="stat-card" style="border-color:<?= $netProfit >= 0 ? '#10b981':'#ef4444' ?>">
        <div class="label">Net Profit</div>
        <div class="value" style="color:<?= $netProfit >= 0 ? '#10b981':'#ef4444' ?>">
            TZS <?= number_format($netProfit, 2) ?>
        </div>
    </div>
    <div class="stat-card blue">
        <div class="label">Profit Margin</div>
        <div class="value" style="color:<?= $margin >= 30 ? '#10b981' : ($margin >= 10 ? '#f59e0b' : '#ef4444') ?>">
            <?= $margin ?>%
        </div>
    </div>
</div>

<!-- SALES + EXPENSES BY DEPT -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
    <div class="card">
        <h3>Sales by Department</h3>
        <table>
            <thead><tr><th>Department</th><th>Total (TZS)</th><th>Share</th></tr></thead>
            <tbody>
            <?php foreach ($salesByDept as $r):
                $share = $totalSales > 0 ? round(($r['total'] / $totalSales) * 100, 1) : 0;
            ?>
            <tr>
                <td><span class="badge badge-<?= $r['type'] ?>"><?= htmlspecialchars($r['cat']) ?></span></td>
                <td><?= number_format($r['total'], 2) ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div style="flex:1;background:#e5e7eb;border-radius:4px;height:6px;">
                            <div style="width:<?= $share ?>%;background:#10b981;height:6px;border-radius:4px;"></div>
                        </div>
                        <span style="font-size:.8rem;color:#888;"><?= $share ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3>Expenses by Department</h3>
        <table>
            <thead><tr><th>Department</th><th>Total (TZS)</th><th>Share</th></tr></thead>
            <tbody>
            <?php foreach ($expByDept as $r):
                $share = $totalExpenses > 0 ? round(($r['total'] / $totalExpenses) * 100, 1) : 0;
            ?>
            <tr>
                <td><span class="badge badge-<?= $r['type'] ?>"><?= htmlspecialchars($r['cat']) ?></span></td>
                <td><?= number_format($r['total'], 2) ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div style="flex:1;background:#e5e7eb;border-radius:4px;height:6px;">
                            <div style="width:<?= $share ?>%;background:#ef4444;height:6px;border-radius:4px;"></div>
                        </div>
                        <span style="font-size:.8rem;color:#888;"><?= $share ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- BEST SELLING ITEMS -->
<?php if ($bestSelling && $bestSelling->num_rows > 0): ?>
<div class="card">
    <h3>&#127942; Best Selling Items</h3>
    <table>
        <thead><tr><th>#</th><th>Item</th><th>Department</th><th>Times Sold</th><th>Total Revenue (TZS)</th></tr></thead>
        <tbody>
        <?php $rank = 1; while ($r = $bestSelling->fetch_assoc()): ?>
        <tr>
            <td><strong style="color:#4f46e5;"><?= $rank++ ?></strong></td>
            <td><?= htmlspecialchars($r['description']) ?></td>
            <td><span class="badge badge-<?= $r['type'] ?>"><?= htmlspecialchars($r['cat']) ?></span></td>
            <td><?= $r['times_sold'] ?>x</td>
            <td><strong><?= number_format($r['total_revenue'], 2) ?></strong></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- EXPENSE BREAKDOWN -->
<?php if ($expBreakdown && $expBreakdown->num_rows > 0): ?>
<div class="card">
    <h3>&#129534; Expense Breakdown by Type</h3>
    <table>
        <thead><tr><th>Description</th><th>Department</th><th>Occurrences</th><th>Total (TZS)</th></tr></thead>
        <tbody>
        <?php while ($r = $expBreakdown->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($r['description']) ?></td>
            <td><span class="badge badge-<?= $r['cat'] === 'Drinks' ? 'drinks':'kitchen' ?>"><?= htmlspecialchars($r['cat']) ?></span></td>
            <td><?= $r['cnt'] ?>x</td>
            <td><?= number_format($r['total'], 2) ?></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- PROFIT MARGIN PER STOCK ITEM -->
<?php if ($margins && $margins->num_rows > 0): ?>
<div class="card">
    <h3>&#128181; Profit Margin Per Stock Item</h3>
    <table>
        <thead><tr><th>Item</th><th>Unit</th><th>Cost (TZS)</th><th>Sell (TZS)</th><th>Profit/Unit</th><th>Margin %</th></tr></thead>
        <tbody>
        <?php while ($r = $margins->fetch_assoc()):
            $color = $r['margin_pct'] >= 30 ? '#10b981' : ($r['margin_pct'] >= 10 ? '#f59e0b' : '#ef4444');
        ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['unit']) ?></td>
            <td><?= number_format($r['cost_price'], 2) ?></td>
            <td><?= number_format($r['selling_price'], 2) ?></td>
            <td><?= number_format($r['profit'], 2) ?></td>
            <td><strong style="color:<?= $color ?>"><?= $r['margin_pct'] ?>%</strong></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <p style="font-size:.8rem;color:#888;margin-top:10px;">
        <span style="color:#10b981;">&#9632;</span> &ge;30% Good &nbsp;
        <span style="color:#f59e0b;">&#9632;</span> 10â€“29% Average &nbsp;
        <span style="color:#ef4444;">&#9632;</span> &lt;10% Low
    </p>
</div>
<?php endif; ?>

<!-- YEARLY MONTHLY SUMMARY -->
<?php if ($type === 'yearly' && $monthlySummary): ?>
<div class="card">
    <h3>&#128197; Monthly Summary â€” <?= date('Y', strtotime($date)) ?></h3>
    <table>
        <thead><tr><th>Month</th><th>Sales (TZS)</th><th>Expenses (TZS)</th><th>Net (TZS)</th></tr></thead>
        <tbody>
        <?php
        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        while ($r = $monthlySummary->fetch_assoc()):
            $net = $r['net'];
        ?>
        <tr>
            <td><?= $months[$r['m'] - 1] ?></td>
            <td><?= number_format($r['sales'], 2) ?></td>
            <td><?= number_format($r['expenses'], 2) ?></td>
            <td style="color:<?= $net >= 0 ? '#10b981':'#ef4444' ?>;font-weight:600;">
                <?= number_format($net, 2) ?>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- DAILY BREAKDOWN FOR WEEKLY/MONTHLY -->
<?php if ($dailyBreakdown && $dailyBreakdown->num_rows > 0 && $type !== 'yearly'): ?>
<div class="card">
    <h3>&#128197; Daily Breakdown</h3>
    <table>
        <thead><tr><th>Date</th><th>Sales (TZS)</th><th>Expenses (TZS)</th><th>Net (TZS)</th></tr></thead>
        <tbody>
        <?php while ($r = $dailyBreakdown->fetch_assoc()): ?>
        <tr>
            <td><?= date('D, d M Y', strtotime($r['day'])) ?></td>
            <td><?= number_format($r['sales'], 2) ?></td>
            <td><?= number_format($r['expenses'], 2) ?></td>
            <td style="color:<?= $r['net'] >= 0 ? '#10b981':'#ef4444' ?>;font-weight:600;">
                <?= number_format($r['net'], 2) ?>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

