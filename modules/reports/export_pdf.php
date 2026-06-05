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
        $label = "Year-to-Date: " . date('Y', strtotime($date));
        break;
    default:
        $start = $end = $date;
        $label = "Daily Report: " . date('d M Y', strtotime($date));
}

$totalSales    = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM sales WHERE sale_date BETWEEN '$start' AND '$end'")->fetch_assoc()['t'];
$totalExpenses = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE expense_date BETWEEN '$start' AND '$end'")->fetch_assoc()['t'];
$netProfit     = $totalSales - $totalExpenses;
$margin        = $totalSales > 0 ? round(($netProfit / $totalSales) * 100, 1) : 0;

$salesByDept = $conn->query("SELECT c.name AS cat, c.type, COALESCE(SUM(s.amount),0) AS total FROM categories c LEFT JOIN sales s ON s.category_id=c.id AND s.sale_date BETWEEN '$start' AND '$end' GROUP BY c.id ORDER BY total DESC")->fetch_all(MYSQLI_ASSOC);
$expByDept   = $conn->query("SELECT c.name AS cat, c.type, COALESCE(SUM(e.amount),0) AS total FROM categories c LEFT JOIN expenses e ON e.category_id=c.id AND e.expense_date BETWEEN '$start' AND '$end' GROUP BY c.id ORDER BY total DESC")->fetch_all(MYSQLI_ASSOC);
$bestSelling = $conn->query("SELECT s.description, c.name AS cat, SUM(s.amount) AS total_revenue, COUNT(*) AS times_sold FROM sales s JOIN categories c ON s.category_id=c.id WHERE s.sale_date BETWEEN '$start' AND '$end' AND s.description != '' GROUP BY s.description, s.category_id ORDER BY total_revenue DESC LIMIT 10");
$expBreakdown= $conn->query("SELECT e.description, c.name AS cat, SUM(e.amount) AS total, COUNT(*) AS cnt FROM expenses e JOIN categories c ON e.category_id=c.id WHERE e.expense_date BETWEEN '$start' AND '$end' GROUP BY e.description, e.category_id ORDER BY total DESC LIMIT 20");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Bar POS Report</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #333; padding: 20px; }
  .header { text-align:center; border-bottom: 2px solid #1a1a2e; padding-bottom:12px; margin-bottom:20px; }
  .header h1 { font-size:20px; color:#1a1a2e; }
  .header p  { color:#666; margin-top:4px; }
  .summary { display:flex; gap:12px; margin-bottom:20px; }
  .stat { flex:1; border:1px solid #ddd; border-radius:6px; padding:10px; text-align:center; }
  .stat .lbl { font-size:10px; color:#888; text-transform:uppercase; }
  .stat .val { font-size:16px; font-weight:bold; margin-top:4px; }
  h2 { font-size:13px; color:#1a1a2e; margin:16px 0 8px; border-left:3px solid #4f46e5; padding-left:8px; }
  table { width:100%; border-collapse:collapse; margin-bottom:16px; }
  th { background:#f0f0f0; padding:6px 8px; text-align:left; font-size:11px; border:1px solid #ddd; }
  td { padding:5px 8px; border:1px solid #eee; font-size:11px; }
  tr:nth-child(even) td { background:#fafafa; }
  .green { color:#10b981; } .red { color:#ef4444; }
  .footer { margin-top:30px; text-align:center; font-size:10px; color:#aaa; border-top:1px solid #eee; padding-top:10px; }
  @media print { body { padding:0; } }
</style>
</head>
<body>
<div class="header">
  <h1>Bar POS System â€” Financial Report</h1>
  <p><?= htmlspecialchars($label) ?> &nbsp;|&nbsp; Generated: <?= date('d M Y H:i') ?></p>
</div>

<div class="summary">
  <div class="stat"><div class="lbl">Total Sales</div><div class="val class green">TZS <?= number_format($totalSales,2) ?></div></div>
  <div class="stat"><div class="lbl">Total Expenses</div><div class="val red">TZS <?= number_format($totalExpenses,2) ?></div></div>
  <div class="stat"><div class="lbl">Net Profit</div><div class="val <?= $netProfit>=0?'green':'red' ?>">TZS <?= number_format($netProfit,2) ?></div></div>
  <div class="stat"><div class="lbl">Profit Margin</div><div class="val"><?= $margin ?>%</div></div>
</div>

<h2>Sales by Department</h2>
<table>
  <thead><tr><th>Department</th><th>Total (TZS)</th></tr></thead>
  <tbody>
  <?php foreach ($salesByDept as $r): ?>
  <tr><td><?= htmlspecialchars($r['cat']) ?></td><td><?= number_format($r['total'],2) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Expenses by Department</h2>
<table>
  <thead><tr><th>Department</th><th>Total (TZS)</th></tr></thead>
  <tbody>
  <?php foreach ($expByDept as $r): ?>
  <tr><td><?= htmlspecialchars($r['cat']) ?></td><td><?= number_format($r['total'],2) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($bestSelling && $bestSelling->num_rows > 0): ?>
<h2>Best Selling Items</h2>
<table>
  <thead><tr><th>#</th><th>Item</th><th>Dept</th><th>Times Sold</th><th>Revenue (TZS)</th></tr></thead>
  <tbody>
  <?php $i=1; while($r = $bestSelling->fetch_assoc()): ?>
  <tr><td><?= $i++ ?></td><td><?= htmlspecialchars($r['description']) ?></td><td><?= htmlspecialchars($r['cat']) ?></td><td><?= $r['times_sold'] ?></td><td><?= number_format($r['total_revenue'],2) ?></td></tr>
  <?php endwhile; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($expBreakdown && $expBreakdown->num_rows > 0): ?>
<h2>Expense Breakdown</h2>
<table>
  <thead><tr><th>Description</th><th>Dept</th><th>Count</th><th>Total (TZS)</th></tr></thead>
  <tbody>
  <?php while($r = $expBreakdown->fetch_assoc()): ?>
  <tr><td><?= htmlspecialchars($r['description']) ?></td><td><?= htmlspecialchars($r['cat']) ?></td><td><?= $r['cnt'] ?></td><td><?= number_format($r['total'],2) ?></td></tr>
  <?php endwhile; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="footer">Bar POS System &mdash; Confidential &mdash; <?= date('Y') ?></div>

<script>window.onload = function(){ window.print(); }</script>
</body>
</html>
