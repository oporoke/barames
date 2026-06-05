<?php
define('APPDIR', 'barpos');
require_once '../../includes/db.php';
require_once '../../includes/functions.php';

$type = $_GET['type'] ?? 'daily';
$date = $_GET['date'] ?? date('Y-m-d');

switch ($type) {
    case 'weekly':
        $start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        $end   = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
        $label = "Weekly_$start to_$end";
        break;
    case 'monthly':
        $start = date('Y-m-01', strtotime($date));
        $end   = date('Y-m-t',  strtotime($date));
        $label = "Monthly_" . date('F_Y', strtotime($date));
        break;
    case 'yearly':
        $start = date('Y-01-01', strtotime($date));
        $end   = date('Y-12-31', strtotime($date));
        $label = "YTD_" . date('Y', strtotime($date));
        break;
    default:
        $start = $end = $date;
        $label = "Daily_$date";
}

$filename = "BarPOS_Report_{$label}_" . date('YmdHis') . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

$totalSales    = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM sales WHERE sale_date BETWEEN '$start' AND '$end'")->fetch_assoc()['t'];
$totalExpenses = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE expense_date BETWEEN '$start' AND '$end'")->fetch_assoc()['t'];
$netProfit     = $totalSales - $totalExpenses;
$margin        = $totalSales > 0 ? round(($netProfit / $totalSales) * 100, 1) : 0;

$salesRows    = $conn->query("SELECT s.sale_date, c.name AS cat, s.description, s.amount FROM sales s JOIN categories c ON s.category_id=c.id WHERE s.sale_date BETWEEN '$start' AND '$end' ORDER BY s.sale_date, s.id");
$expenseRows  = $conn->query("SELECT e.expense_date, c.name AS cat, e.description, e.amount FROM expenses e JOIN categories c ON e.category_id=c.id WHERE e.expense_date BETWEEN '$start' AND '$end' ORDER BY e.expense_date, e.id");
$bestSelling  = $conn->query("SELECT s.description, c.name AS cat, SUM(s.amount) AS total_revenue, COUNT(*) AS times_sold FROM sales s JOIN categories c ON s.category_id=c.id WHERE s.sale_date BETWEEN '$start' AND '$end' AND s.description != '' GROUP BY s.description, s.category_id ORDER BY total_revenue DESC LIMIT 20");
$margins      = $conn->query("SELECT name, cost_price, selling_price, (selling_price-cost_price) AS profit, CASE WHEN selling_price>0 THEN ROUND(((selling_price-cost_price)/selling_price)*100,1) ELSE 0 END AS margin_pct FROM stock_items WHERE is_active=1 AND selling_price>0 ORDER BY margin_pct DESC");
?>
<html><head><meta charset="UTF-8"></head><body>

<table border="1">
<tr><td colspan="4" style="font-weight:bold;font-size:14pt;background:#1a1a2e;color:white;">BAR POS SYSTEM â€” FINANCIAL REPORT</td></tr>
<tr><td colspan="4"><?= htmlspecialchars(str_replace('_',' ',$label)) ?> | Generated: <?= date('d M Y H:i') ?></td></tr>
<tr><td></td></tr>

<tr><td colspan="4" style="font-weight:bold;background:#e5e7eb;">SUMMARY</td></tr>
<tr><td>Total Sales (TZS)</td><td><?= number_format($totalSales,2) ?></td><td>Total Expenses (TZS)</td><td><?= number_format($totalExpenses,2) ?></td></tr>
<tr><td>Net Profit (TZS)</td><td><?= number_format($netProfit,2) ?></td><td>Profit Margin</td><td><?= $margin ?>%</td></tr>
<tr><td></td></tr>

<tr><td colspan="4" style="font-weight:bold;background:#e5e7eb;">ALL SALES</td></tr>
<tr style="font-weight:bold;background:#f3f4f6;"><td>Date</td><td>Department</td><td>Description</td><td>Amount (TZS)</td></tr>
<?php while ($r = $salesRows->fetch_assoc()): ?>
<tr><td><?= $r['sale_date'] ?></td><td><?= htmlspecialchars($r['cat']) ?></td><td><?= htmlspecialchars($r['description']) ?></td><td><?= number_format($r['amount'],2) ?></td></tr>
<?php endwhile; ?>
<tr><td></td></tr>

<tr><td colspan="4" style="font-weight:bold;background:#e5e7eb;">ALL EXPENSES</td></tr>
<tr style="font-weight:bold;background:#f3f4f6;"><td>Date</td><td>Department</td><td>Description</td><td>Amount (TZS)</td></tr>
<?php while ($r = $expenseRows->fetch_assoc()): ?>
<tr><td><?= $r['expense_date'] ?></td><td><?= htmlspecialchars($r['cat']) ?></td><td><?= htmlspecialchars($r['description']) ?></td><td><?= number_format($r['amount'],2) ?></td></tr>
<?php endwhile; ?>
<tr><td></td></tr>

<tr><td colspan="4" style="font-weight:bold;background:#e5e7eb;">BEST SELLING ITEMS</td></tr>
<tr style="font-weight:bold;background:#f3f4f6;"><td>Item</td><td>Department</td><td>Times Sold</td><td>Total Revenue (TZS)</td></tr>
<?php while ($r = $bestSelling->fetch_assoc()): ?>
<tr><td><?= htmlspecialchars($r['description']) ?></td><td><?= htmlspecialchars($r['cat']) ?></td><td><?= $r['times_sold'] ?></td><td><?= number_format($r['total_revenue'],2) ?></td></tr>
<?php endwhile; ?>
<tr><td></td></tr>

<tr><td colspan="6" style="font-weight:bold;background:#e5e7eb;">PROFIT MARGIN PER STOCK ITEM</td></tr>
<tr style="font-weight:bold;background:#f3f4f6;"><td>Item</td><td>Cost (TZS)</td><td>Sell Price (TZS)</td><td>Profit/Unit (TZS)</td><td>Margin %</td></tr>
<?php while ($r = $margins->fetch_assoc()): ?>
<tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= number_format($r['cost_price'],2) ?></td><td><?= number_format($r['selling_price'],2) ?></td><td><?= number_format($r['profit'],2) ?></td><td><?= $r['margin_pct'] ?>%</td></tr>
<?php endwhile; ?>

</table>
</body></html>
