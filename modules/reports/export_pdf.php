<?php
define('APPDIR', 'barpos');
require_once '../../includes/db.php';
require_once '../../includes/functions.php';

// ── DOMPDF BOOTSTRAP ─────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/barpos/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// ── INPUT VALIDATION ─────────────────────────────────────────────────
$allowed_types = ['daily', 'weekly', 'monthly', 'yearly'];
$type = in_array($_GET['type'] ?? '', $allowed_types) ? $_GET['type'] : 'daily';
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date'] : date('Y-m-d');

// ── DATE RANGE ───────────────────────────────────────────────────────
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

// ── HELPER ───────────────────────────────────────────────────────────
function q($conn, $sql, $params = []) {
    if (empty($params)) return $conn->query($sql);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

// ── REVENUE ──────────────────────────────────────────────────────────
$totalSales = q($conn,
    "SELECT COALESCE(SUM(total),0) AS t FROM sale_transactions
     WHERE type='sale' AND sale_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc()['t'];

// ── COGS ─────────────────────────────────────────────────────────────
$totalCOGS = q($conn,
    "SELECT COALESCE(SUM(si.quantity * sk.cost_price),0) AS t
     FROM sale_items si
     JOIN stock_items sk ON si.stock_item_id = sk.id
     JOIN sale_transactions st ON si.transaction_id = st.id
     WHERE st.type='sale' AND st.sale_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc()['t'];

// ── EXPENSES ─────────────────────────────────────────────────────────
$totalExpenses = q($conn,
    "SELECT COALESCE(SUM(amount),0) AS t FROM expenses
     WHERE expense_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc()['t'];

// ── METRICS ──────────────────────────────────────────────────────────
$grossProfit = $totalSales - $totalCOGS;
$netProfit   = $grossProfit - $totalExpenses;
$grossMargin = $totalSales > 0 ? round(($grossProfit / $totalSales) * 100, 1) : 0;
$netMargin   = $totalSales > 0 ? round(($netProfit   / $totalSales) * 100, 1) : 0;


$payBreakdown = q($conn,
    "SELECT
        COALESCE(SUM(CASE WHEN payment_method='cash'         THEN amount ELSE 0 END),0) AS cash_sales,
        COALESCE(SUM(CASE WHEN payment_method='mobile_money' THEN amount ELSE 0 END),0) AS lipa_sales
     FROM sales
     WHERE sale_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc();

$txnCount  = q($conn,
    "SELECT COUNT(*) AS c FROM sale_transactions
     WHERE type='sale' AND sale_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc()['c'];
$avgTicket = $txnCount > 0 ? $totalSales / $txnCount : 0;

// ── SALES BY DEPT ─────────────────────────────────────────────────────
$salesByDept = q($conn,
    "SELECT c.name AS cat, COALESCE(SUM(s.amount),0) AS total
     FROM categories c
     LEFT JOIN sales s ON s.category_id = c.id
       AND s.sale_date BETWEEN ? AND ?
     WHERE c.type IN ('drinks', 'kitchen')
     GROUP BY c.id
     ORDER BY c.type DESC",
    [$start, $end]
)->fetch_all(MYSQLI_ASSOC);

// ── EXPENSES BY DEPT ──────────────────────────────────────────────────
$expByDept = q($conn,
    "SELECT c.name AS cat, COALESCE(SUM(e.amount),0) AS total
     FROM categories c
     LEFT JOIN expenses e ON e.category_id = c.id
       AND e.expense_date BETWEEN ? AND ?
     GROUP BY c.id ORDER BY total DESC",
    [$start, $end]
)->fetch_all(MYSQLI_ASSOC);

// ── BEST SELLING ──────────────────────────────────────────────────────
$bestSelling = q($conn,
    "SELECT si.description, c.name AS cat,
            SUM(si.line_total) AS total_revenue,
            SUM(si.quantity)   AS qty_sold,
            SUM(si.quantity * sk.cost_price) AS total_cost,
            SUM(si.line_total) - SUM(si.quantity * sk.cost_price) AS gross_profit
     FROM sale_items si
     JOIN sale_transactions st ON si.transaction_id = st.id
     JOIN categories c ON si.category_id = c.id
     JOIN stock_items sk ON si.stock_item_id = sk.id
     WHERE st.type='sale' AND st.sale_date BETWEEN ? AND ?
       AND si.description != ''
     GROUP BY si.description, si.category_id
     ORDER BY total_revenue DESC LIMIT 10",
    [$start, $end]
)->fetch_all(MYSQLI_ASSOC);

// ── EXPENSE BREAKDOWN ─────────────────────────────────────────────────
$expBreakdown = q($conn,
    "SELECT e.description, c.name AS cat, SUM(e.amount) AS total, COUNT(*) AS cnt
     FROM expenses e
     JOIN categories c ON e.category_id = c.id
     WHERE e.expense_date BETWEEN ? AND ?
     GROUP BY e.description, e.category_id
     ORDER BY total DESC LIMIT 20",
    [$start, $end]
)->fetch_all(MYSQLI_ASSOC);

// ── DAILY BREAKDOWN ───────────────────────────────────────────────────
$dailyRows = [];
if ($type !== 'daily') {
    $res = q($conn,
        "SELECT d.day,
                COALESCE(s.revenue,0) AS sales,
                COALESCE(g.cogs,0)    AS cogs,
                COALESCE(e.total,0)   AS expenses,
                COALESCE(s.revenue,0) - COALESCE(g.cogs,0) - COALESCE(e.total,0) AS net
         FROM (
             SELECT DISTINCT sale_date AS day FROM sale_transactions WHERE sale_date BETWEEN ? AND ? AND type='sale'
             UNION
             SELECT DISTINCT expense_date FROM expenses WHERE expense_date BETWEEN ? AND ?
         ) d
         LEFT JOIN (
             SELECT sale_date, SUM(total) AS revenue FROM sale_transactions
             WHERE sale_date BETWEEN ? AND ? AND type='sale' GROUP BY sale_date
         ) s ON s.sale_date = d.day
         LEFT JOIN (
             SELECT st.sale_date, SUM(si.quantity * sk.cost_price) AS cogs
             FROM sale_items si
             JOIN sale_transactions st ON si.transaction_id = st.id
             JOIN stock_items sk ON si.stock_item_id = sk.id
             WHERE st.type='sale' AND st.sale_date BETWEEN ? AND ?
             GROUP BY st.sale_date
         ) g ON g.sale_date = d.day
         LEFT JOIN (
             SELECT expense_date, SUM(amount) AS total FROM expenses
             WHERE expense_date BETWEEN ? AND ? GROUP BY expense_date
         ) e ON e.expense_date = d.day
         ORDER BY d.day",
        [$start,$end,$start,$end,$start,$end,$start,$end,$start,$end]
    );
    $dailyRows = $res->fetch_all(MYSQLI_ASSOC);
}

// ── BUILD HTML FOR PDF ────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }

  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 11px;
    color: #1a1a2e;
    background: #fff;
  }

  /* ── HEADER ── */
  .header {
    border-bottom: 2px solid #1a1a2e;
    padding-bottom: 12px;
    margin-bottom: 18px;
    overflow: hidden;
  }
  .header-left { float: left; }
  .header-right { float: right; text-align: right; }
  .header h1 { font-size: 17px; font-weight: bold; color: #1a1a2e; }
  .header .sub { font-size: 10px; color: #888; margin-top: 3px; }
  .header .meta { font-size: 10px; color: #888; line-height: 1.6; }
  .clearfix { clear: both; }

  /* ── KPI GRID ── */
  .kpi-grid { width: 100%; margin-bottom: 18px; border-collapse: collapse; }
  .kpi-grid td { width: 16.66%; padding: 4px; vertical-align: top; }
  .kpi {
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 8px 10px;
    border-top: 3px solid #ccc;
  }
  .kpi.blue   { border-top-color: #2d2df0; }
  .kpi.amber  { border-top-color: #d97706; }
  .kpi.sky    { border-top-color: #0ea5e9; }
  .kpi.red    { border-top-color: #e03030; }
  .kpi.green  { border-top-color: #00a86b; }
  .kpi.teal   { border-top-color: #0ea5e9; }
  .kpi .lbl   { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #888; margin-bottom: 5px; }
  .kpi .val   { font-size: 12px; font-weight: bold; color: #1a1a2e; }
  .kpi .val.pos { color: #00a86b; }
  .kpi .val.neg { color: #e03030; }
  .kpi .sub   { font-size: 8.5px; color: #aaa; margin-top: 3px; }

  /* ── SECTION TITLE ── */
  .section-title {
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #1a1a2e;
    border-left: 3px solid #2d2df0;
    padding-left: 7px;
    margin: 16px 0 8px;
  }

  /* ── TABLE ── */
  table.data { width: 100%; border-collapse: collapse; margin-bottom: 4px; font-size: 10px; }
  table.data thead th {
    background: #f4f4f8;
    padding: 5px 7px;
    text-align: left;
    font-size: 9px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #555;
    border-bottom: 1px solid #e5e7eb;
  }
  table.data thead th.r { text-align: right; }
  table.data tbody td {
    padding: 5px 7px;
    border-bottom: 1px solid #f0f0f0;
    color: #2a2a3a;
  }
  table.data tbody td.r { text-align: right; }
  table.data tbody tr:nth-child(even) td { background: #fafafa; }
  table.data tbody tr:last-child td { border-bottom: none; }

  .pos   { color: #00a86b; font-weight: bold; }
  .neg   { color: #e03030; font-weight: bold; }
  .muted { color: #999; }
  .bold  { font-weight: bold; }

  /* ── FOOTER ── */
  .footer {
    margin-top: 30px;
    padding-top: 8px;
    border-top: 1px solid #e5e7eb;
    font-size: 9px;
    color: #bbb;
    overflow: hidden;
  }
  .footer .fl { float: left; }
  .footer .fr { float: right; }
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="header-left">
    <h1>Tretat Bar & Restaurant &mdash; Daily Report</h1>
    <div class="sub"><?= htmlspecialchars($label) ?></div>
  </div>
  <div class="header-right">
    <div class="meta">Generated: <?= date('d M Y, H:i') ?><br><?= $txnCount ?> transactions</div>
  </div>
  <div class="clearfix"></div>
</div>

<!-- KPI CARDS -->
<table class="kpi-grid">
  <tr>
    <td><div class="kpi blue">
      <div class="lbl">Revenue</div>
      <div class="val">TZS <?= number_format($totalSales, 0) ?></div>
      <div class="sub"><?= $txnCount ?> transactions</div>
    </div></td>
    <td><div class="kpi amber">
      <div class="lbl">COGS</div>
      <div class="val">TZS <?= number_format($totalCOGS, 0) ?></div>
      <div class="sub"><?= $totalSales > 0 ? number_format(($totalCOGS/$totalSales)*100,1) : 0 ?>% of revenue</div>
    </div></td>
    <td><div class="kpi sky">
      <div class="lbl">Gross Profit</div>
      <div class="val <?= $grossProfit >= 0 ? 'pos':'neg' ?>">TZS <?= number_format($grossProfit, 0) ?></div>
      <div class="sub">Margin: <?= $grossMargin ?>%</div>
    </div></td>
    <td><div class="kpi red">
      <div class="lbl">Expenses</div>
      <div class="val">TZS <?= number_format($totalExpenses, 0) ?></div>
      <div class="sub">Operating costs</div>
    </div></td>
    <td><div class="kpi <?= $netProfit >= 0 ? 'green':'red' ?>">
      <div class="lbl">Net Profit</div>
      <div class="val <?= $netProfit >= 0 ? 'pos':'neg' ?>">TZS <?= number_format($netProfit, 0) ?></div>
      <div class="sub">Margin: <?= $netMargin ?>%</div>
    </div></td>
    <td><div class="kpi teal">
      <div class="lbl">Avg Ticket</div>
      <div class="val">TZS <?= number_format($avgTicket, 0) ?></div>
      <div class="sub">Per transaction</div>
    </div></td>
  </tr>
</table>

<!-- SALES BY DEPT -->
<div class="section-title">Sales by Department</div>
<table class="data">
  <thead><tr><th>Department</th><th class="r">Revenue (TZS)</th><th class="r">Share</th></tr></thead>
  <tbody>
  <?php foreach ($salesByDept as $r):
    $share = $totalSales > 0 ? round(($r['total']/$totalSales)*100,1) : 0;
  ?>
  <tr>
    <td><?= htmlspecialchars($r['cat']) ?></td>
    <td class="r"><?= number_format($r['total'], 0) ?></td>
    <td class="r muted"><?= $share ?>%</td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<div class="section-title">Payment Breakdown</div>
<table class="data">
  <thead><tr><th>Method</th><th class="r">Amount (TZS)</th></tr></thead>
  <tbody>
    <tr><td>&#128181; Cash</td><td class="r"><?= number_format($payBreakdown['cash_sales'], 0) ?></td></tr>
    <tr><td>&#128242; Lipa / Mobile Money</td><td class="r"><?= number_format($payBreakdown['lipa_sales'], 0) ?></td></tr>
    <tr><td class="bold">Total</td><td class="r bold"><?= number_format($totalSales, 0) ?></td></tr>
  </tbody>
</table>

<!-- EXPENSES BY DEPT -->
<div class="section-title">Expenses by Department</div>
<table class="data">
  <thead><tr><th>Department</th><th class="r">Amount (TZS)</th><th class="r">Share</th></tr></thead>
  <tbody>
  <?php foreach ($expByDept as $r):
    $share = $totalExpenses > 0 ? round(($r['total']/$totalExpenses)*100,1) : 0;
  ?>
  <tr>
    <td><?= htmlspecialchars($r['cat']) ?></td>
    <td class="r"><?= number_format($r['total'], 0) ?></td>
    <td class="r muted"><?= $share ?>%</td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<!-- BEST SELLING ITEMS -->
<?php if (!empty($bestSelling)): ?>
<div class="section-title">Best Selling Items</div>
<table class="data">
  <thead>
    <tr>
      <th>#</th><th>Item</th><th>Dept</th>
      <th class="r">Qty</th><th class="r">Revenue (TZS)</th>
      <th class="r">COGS (TZS)</th><th class="r">Gross Profit (TZS)</th>
    </tr>
  </thead>
  <tbody>
  <?php $i = 1; foreach ($bestSelling as $r): ?>
  <tr>
    <td class="muted"><?= $i++ ?></td>
    <td class="bold"><?= htmlspecialchars($r['description']) ?></td>
    <td><?= htmlspecialchars($r['cat']) ?></td>
    <td class="r"><?= number_format($r['qty_sold'], 1) ?></td>
    <td class="r"><?= number_format($r['total_revenue'], 0) ?></td>
    <td class="r muted"><?= number_format($r['total_cost'], 0) ?></td>
    <td class="r <?= $r['gross_profit'] >= 0 ? 'pos':'neg' ?>"><?= number_format($r['gross_profit'], 0) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- EXPENSE BREAKDOWN -->
<?php if (!empty($expBreakdown)): ?>
<div class="section-title">Expense Breakdown</div>
<table class="data">
  <thead><tr><th>Description</th><th>Department</th><th class="r">Count</th><th class="r">Total (TZS)</th></tr></thead>
  <tbody>
  <?php foreach ($expBreakdown as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['description']) ?></td>
    <td><?= htmlspecialchars($r['cat']) ?></td>
    <td class="r muted"><?= $r['cnt'] ?>x</td>
    <td class="r"><?= number_format($r['total'], 0) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- DAILY BREAKDOWN -->
<?php if (!empty($dailyRows)): ?>
<div class="section-title">Daily Breakdown</div>
<table class="data">
  <thead>
    <tr>
      <th>Date</th>
      <th class="r">Revenue (TZS)</th>
      <th class="r">COGS (TZS)</th>
      <th class="r">Expenses (TZS)</th>
      <th class="r">Net (TZS)</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($dailyRows as $r): ?>
  <tr>
    <td><?= date('D, d M Y', strtotime($r['day'])) ?></td>
    <td class="r"><?= number_format($r['sales'], 0) ?></td>
    <td class="r muted"><?= number_format($r['cogs'], 0) ?></td>
    <td class="r muted"><?= number_format($r['expenses'], 0) ?></td>
    <td class="r <?= $r['net'] >= 0 ? 'pos':'neg' ?>"><?= number_format($r['net'], 0) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- FOOTER -->
<div class="footer">
  <span class="fl">Bar POS System &mdash; Confidential</span>
  <span class="fr">&copy; <?= date('Y') ?></span>
  <div class="clearfix"></div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// ── RENDER PDF ────────────────────────────────────────────────────────
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'report-' . $type . '-' . $date . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
