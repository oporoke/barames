<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireRole('admin', 'manager');

// ── INPUT VALIDATION ────────────────────────────────────────────────
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

// ── HELPER: SAFE QUERY ───────────────────────────────────────────────
function q($conn, $sql, $params = []) {
    if (empty($params)) return $conn->query($sql);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

// ── REVENUE (from sale_transactions) ────────────────────────────────
$revenueRow = q($conn,
    "SELECT COALESCE(SUM(total),0) AS t FROM sale_transactions
     WHERE type='sale' AND sale_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc();
$totalSales = $revenueRow['t'];

// ── COGS (from sale_items × stock_items.cost_price) ─────────────────
$cogsRow = q($conn,
    "SELECT COALESCE(SUM(si.quantity * sk.cost_price),0) AS t
     FROM sale_items si
     JOIN stock_items sk ON si.stock_item_id = sk.id
     JOIN sale_transactions st ON si.transaction_id = st.id
     WHERE st.type = 'sale' AND st.sale_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc();
$totalCOGS = $cogsRow['t'];

// ── EXPENSES ─────────────────────────────────────────────────────────
// $expRow = q($conn,
//     "SELECT COALESCE(SUM(amount),0) AS t FROM expenses
//      WHERE expense_date BETWEEN ? AND ?",
//     [$start, $end]
// )->fetch_assoc();
// $totalExpenses = $expRow['t'];
// ── EXPENSES (exclude stock category) ────────────────────────────────
$expRow = q($conn,
    "SELECT COALESCE(SUM(amount),0) AS t FROM expenses
     WHERE expense_date BETWEEN ? AND ?
     AND category_id != 5",
    [$start, $end]
)->fetch_assoc();
$totalExpenses = $expRow['t'];

// ── DERIVED METRICS ──────────────────────────────────────────────────
$grossProfit = $totalSales - $totalCOGS;
$netProfit   = $grossProfit - $totalExpenses;
$grossMargin = $totalSales > 0 ? round(($grossProfit / $totalSales) * 100, 1) : 0;
$netMargin   = $totalSales > 0 ? round(($netProfit   / $totalSales) * 100, 1) : 0;

// ── CAPITAL INJECTIONS ───────────────────────────────────────────────
$injRow = q($conn,
    "SELECT COALESCE(SUM(amount),0) AS t FROM capital_injections
     WHERE injection_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc();
$totalInjections = $injRow['t'];

// ── STOCK PURCHASES (cash out) ───────────────────────────────────────
// ── STOCK PURCHASES (from expenses where category = Stock) ───────────
$stockBoughtRow = q($conn,
    "SELECT COALESCE(SUM(amount),0) AS t FROM expenses
     WHERE category_id = 5 AND expense_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc();
$totalStockBought = $stockBoughtRow['t'];

// ── OPENING CASH (from daily_cash_control) ───────────────────────────
// ── OPENING CASH (most recent record on or before start date) ────────
$cashCtrlRow = q($conn,
    "SELECT opening_cash, opening_lipa, closing_cash, closing_lipa
     FROM daily_cash_control
     WHERE report_date <= ?
     ORDER BY report_date DESC
     LIMIT 1",
    [$start]
)->fetch_assoc();

$openingCash = $cashCtrlRow 
    ? ($cashCtrlRow['closing_cash'] + $cashCtrlRow['closing_lipa']) 
    : 0;
$closingCash = $cashCtrlRow 
    ? ($cashCtrlRow['closing_cash'] + $cashCtrlRow['closing_lipa']) 
    : null;

// ── CASH POSITION ────────────────────────────────────────────────────
$cashPosition = $openingCash + $totalInjections + $totalSales - $totalExpenses - $totalStockBought;

// ── TRANSACTION COUNT ────────────────────────────────────────────────
$txnRow = q($conn,
    "SELECT COUNT(*) AS c FROM sale_transactions
     WHERE type='sale' AND sale_date BETWEEN ? AND ?",
    [$start, $end]
)->fetch_assoc();
$txnCount = $txnRow['c'];
$avgTicket = $txnCount > 0 ? $totalSales / $txnCount : 0;

// ── SALES BY DEPARTMENT (via sale_items → categories) ───────────────
$salesByDept = q($conn,
    "SELECT c.name AS cat, c.type,
            COALESCE(SUM(si.line_total),0) AS total
     FROM categories c
     LEFT JOIN sale_items si ON si.category_id = c.id
     LEFT JOIN sale_transactions st ON si.transaction_id = st.id
       AND st.type = 'sale' AND st.sale_date BETWEEN ? AND ?
     GROUP BY c.id ORDER BY total DESC",
    [$start, $end]
)->fetch_all(MYSQLI_ASSOC);

// ── EXPENSES BY DEPARTMENT ───────────────────────────────────────────
$expByDept = q($conn,
    "SELECT c.name AS cat, c.type,
            COALESCE(SUM(e.amount),0) AS total
     FROM categories c
     LEFT JOIN expenses e ON e.category_id = c.id
       AND e.expense_date BETWEEN ? AND ?
     GROUP BY c.id ORDER BY total DESC",
    [$start, $end]
)->fetch_all(MYSQLI_ASSOC);

// ── BEST SELLING ITEMS ───────────────────────────────────────────────
$bestSelling = q($conn,
    "SELECT si.description, c.name AS cat, c.type,
            SUM(si.line_total) AS total_revenue,
            SUM(si.quantity)   AS qty_sold,
            SUM(si.quantity * sk.cost_price) AS total_cost,
            SUM(si.line_total) - SUM(si.quantity * sk.cost_price) AS gross_profit
     FROM sale_items si
     JOIN sale_transactions st ON si.transaction_id = st.id
     JOIN categories c ON si.category_id = c.id
     JOIN stock_items sk ON si.stock_item_id = sk.id
     WHERE st.type = 'sale' AND st.sale_date BETWEEN ? AND ?
       AND si.description != ''
     GROUP BY si.description, si.category_id
     ORDER BY total_revenue DESC
     LIMIT 10",
    [$start, $end]
);

// ── EXPENSE BREAKDOWN ────────────────────────────────────────────────
$expBreakdown = q($conn,
    "SELECT e.description, c.name AS cat,
            SUM(e.amount) AS total, COUNT(*) AS cnt
     FROM expenses e
     JOIN categories c ON e.category_id = c.id
     WHERE e.expense_date BETWEEN ? AND ?
     GROUP BY e.description, e.category_id
     ORDER BY total DESC LIMIT 20",
    [$start, $end]
);

// ── PROFIT MARGIN PER STOCK ITEM ─────────────────────────────────────
$margins = q($conn,
    "SELECT name, cost_price, selling_price, unit,
            (selling_price - cost_price) AS profit,
            CASE WHEN selling_price > 0
                 THEN ROUND(((selling_price-cost_price)/selling_price)*100,1)
                 ELSE 0 END AS margin_pct
     FROM stock_items
     WHERE is_active=1 AND selling_price > 0
     ORDER BY margin_pct DESC"
);

// ── DAILY BREAKDOWN ───────────────────────────────────────────────────
$dailyBreakdown = null;
if ($type !== 'daily') {
    $dailyBreakdown = q($conn,
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
        [$start, $end, $start, $end, $start, $end, $start, $end, $start, $end]
    );
}

// ── MONTHLY SUMMARY (yearly view) ────────────────────────────────────
$monthlySummary = null;
if ($type === 'yearly') {
    $year = date('Y', strtotime($start));
    $monthlySummary = q($conn,
        "SELECT months.m,
                COALESCE(s.revenue,0) AS sales,
                COALESCE(g.cogs,0)    AS cogs,
                COALESCE(e.total,0)   AS expenses,
                COALESCE(s.revenue,0) - COALESCE(g.cogs,0) - COALESCE(e.total,0) AS net
         FROM (
             SELECT 1 AS m UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
             UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8
             UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12
         ) months
         LEFT JOIN (
             SELECT MONTH(sale_date) AS m, SUM(total) AS revenue
             FROM sale_transactions WHERE YEAR(sale_date)=? AND type='sale'
             GROUP BY MONTH(sale_date)
         ) s ON s.m = months.m
         LEFT JOIN (
             SELECT MONTH(st.sale_date) AS m, SUM(si.quantity * sk.cost_price) AS cogs
             FROM sale_items si
             JOIN sale_transactions st ON si.transaction_id = st.id
             JOIN stock_items sk ON si.stock_item_id = sk.id
             WHERE st.type='sale' AND YEAR(st.sale_date)=?
             GROUP BY MONTH(st.sale_date)
         ) g ON g.m = months.m
         LEFT JOIN (
             SELECT MONTH(expense_date) AS m, SUM(amount) AS total
             FROM expenses WHERE YEAR(expense_date)=?
             GROUP BY MONTH(expense_date)
         ) e ON e.m = months.m
         ORDER BY months.m",
        [$year, $year, $year]
    );
}

require_once '../../includes/header.php';

// ── BUILD CHART DATA ──────────────────────────────────────────────────
$deptLabels = [];
$deptSales  = [];
foreach ($salesByDept as $r) {
    if ($r['total'] > 0) {
        $deptLabels[] = $r['cat'];
        $deptSales[]  = (float)$r['total'];
    }
}

$dailyLabels = [];
$dailySales  = [];
$dailyNet    = [];
if ($dailyBreakdown) {
    $rows = $dailyBreakdown->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $dailyLabels[] = date('d M', strtotime($r['day']));
        $dailySales[]  = (float)$r['sales'];
        $dailyNet[]    = (float)$r['net'];
    }
}

$monthlyLabels = [];
$monthlySales  = [];
$monthlyNet    = [];
if ($monthlySummary) {
    $mnames = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $mrows  = $monthlySummary->fetch_all(MYSQLI_ASSOC);
    foreach ($mrows as $r) {
        $monthlyLabels[] = $mnames[$r['m']];
        $monthlySales[]  = (float)$r['sales'];
        $monthlyNet[]    = (float)$r['net'];
    }
}

$bestItems   = [];
$bestRevenue = [];
if ($bestSelling) {
    $brows = $bestSelling->fetch_all(MYSQLI_ASSOC);
    foreach ($brows as $r) {
        $bestItems[]   = $r['description'];
        $bestRevenue[] = (float)$r['total_revenue'];
    }
}
?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap');

  :root {
    --ink:       #0d0d14;
    --ink-soft:  #4a4a5a;
    --ink-muted: #8888a0;
    --surface:   #ffffff;
    --surface-2: #f7f7fb;
    --surface-3: #f0f0f6;
    --border:    rgba(0,0,0,0.07);
    --accent:    #2d2df0;
    --accent-2:  #e8f0ff;
    --green:     #00a86b;
    --green-bg:  #e6f9f2;
    --red:       #e03030;
    --red-bg:    #fdeaea;
    --amber:     #d97706;
    --amber-bg:  #fef3e0;
    --radius:    14px;
    --radius-sm: 8px;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  .rp-wrap {
    font-family: 'DM Sans', sans-serif;
    color: var(--ink);
    max-width: 1180px;
    padding: 0 0 60px;
  }

  /* ── HEADER ── */
  .rp-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border);
  }
  .rp-title {
    font-family: 'Syne', sans-serif;
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    color: var(--ink);
    line-height: 1;
  }
  .rp-subtitle {
    font-size: 0.85rem;
    color: var(--ink-muted);
    margin-top: 6px;
    font-weight: 400;
  }
  .rp-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .rp-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: var(--radius-sm);
    font-size: 0.8rem; font-weight: 500; text-decoration: none;
    transition: all 0.15s; cursor: pointer; border: none;
    font-family: 'DM Sans', sans-serif;
  }
  .rp-btn-outline {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--ink-soft);
  }
  .rp-btn-outline:hover { background: var(--surface-2); color: var(--ink); }
  .rp-btn-accent { background: var(--accent); color: #fff; }
  .rp-btn-accent:hover { opacity: 0.88; }
  .rp-btn-green { background: var(--green); color: #fff; }
  .rp-btn-green:hover { opacity: 0.88; }

  /* ── FILTER BAR ── */
  .rp-filter {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
    margin-bottom: 28px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
  }
  .rp-filter label {
    font-size: 0.72rem;
    font-weight: 500;
    color: var(--ink-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    display: block;
    margin-bottom: 5px;
  }
  .rp-filter select, .rp-filter input[type=date] {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 8px 12px;
    font-size: 0.85rem;
    font-family: 'DM Sans', sans-serif;
    color: var(--ink);
    outline: none;
  }
  .rp-filter select:focus, .rp-filter input[type=date]:focus {
    border-color: var(--accent);
  }

  /* ── PERIOD LABEL ── */
  .rp-period {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--accent-2);
    color: var(--accent);
    font-size: 0.8rem;
    font-weight: 500;
    padding: 6px 14px;
    border-radius: 100px;
    margin-bottom: 24px;
  }

  /* ── KPI GRID ── */
  .rp-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
  }
  .rp-kpi {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    position: relative;
    overflow: hidden;
    transition: box-shadow 0.2s;
  }
  .rp-kpi:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
  .rp-kpi::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: var(--radius) var(--radius) 0 0;
  }
  .rp-kpi.green::before { background: var(--green); }
  .rp-kpi.red::before   { background: var(--red); }
  .rp-kpi.blue::before  { background: var(--accent); }
  .rp-kpi.amber::before { background: var(--amber); }
  .rp-kpi.teal::before  { background: #0ea5e9; }
  .rp-kpi .kpi-label {
    font-size: 0.72rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ink-muted);
    margin-bottom: 10px;
  }
  .rp-kpi .kpi-value {
    font-family: 'Syne', sans-serif;
    font-size: 1.45rem;
    font-weight: 700;
    color: var(--ink);
    letter-spacing: -0.02em;
    line-height: 1;
  }
  .rp-kpi .kpi-sub {
    font-size: 0.75rem;
    color: var(--ink-muted);
    margin-top: 6px;
  }
  .kpi-value.positive { color: var(--green); }
  .kpi-value.negative { color: var(--red); }
  .kpi-value.warn     { color: var(--amber); }

  /* ── SECTION HEADING ── */
  .rp-section-title {
    font-family: 'Syne', sans-serif;
    font-size: 1rem;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 14px;
    letter-spacing: -0.01em;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .rp-section-title span {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent);
    display: inline-block;
  }

  /* ── CARD ── */
  .rp-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 22px 24px;
    margin-bottom: 20px;
  }

  /* ── 2-COL ── */
  .rp-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
  @media(max-width:760px){ .rp-2col { grid-template-columns: 1fr; } }

  /* ── TABLE ── */
  .rp-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
  .rp-table thead th {
    text-align: left;
    padding: 0 10px 10px;
    font-size: 0.7rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ink-muted);
    border-bottom: 1px solid var(--border);
  }
  .rp-table tbody td {
    padding: 10px;
    border-bottom: 1px solid var(--border);
    color: var(--ink);
    vertical-align: middle;
  }
  .rp-table tbody tr:last-child td { border-bottom: none; }
  .rp-table tbody tr:hover td { background: var(--surface-2); }
  .rp-table .num { text-align: right; font-variant-numeric: tabular-nums; }

  /* ── BAR VISUAL ── */
  .bar-wrap { display: flex; align-items: center; gap: 8px; }
  .bar-track { flex: 1; height: 5px; background: var(--surface-3); border-radius: 99px; }
  .bar-fill  { height: 5px; border-radius: 99px; }
  .bar-pct   { font-size: 0.75rem; color: var(--ink-muted); min-width: 34px; text-align: right; }

  /* ── BADGE ── */
  .badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 99px;
    font-size: 0.72rem;
    font-weight: 500;
    letter-spacing: 0.02em;
  }
  .badge-bar  { background: #e8f0ff; color: #1a40c0; }
  .badge-kitchen { background: #fef3e0; color: #92400e; }
  .badge-drinks  { background: #e6f9f2; color: #065f46; }
  .badge-default { background: var(--surface-3); color: var(--ink-soft); }

  /* ── RANK ── */
  .rank-num {
    font-family: 'Syne', sans-serif;
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--ink-muted);
    width: 24px;
  }
  .rank-num.top3 { color: var(--accent); }

  /* ── MARGIN PILL ── */
  .mpill {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 99px;
    font-size: 0.78rem;
    font-weight: 600;
  }
  .mpill.good  { background: var(--green-bg);  color: var(--green); }
  .mpill.avg   { background: var(--amber-bg);  color: var(--amber); }
  .mpill.low   { background: var(--red-bg);    color: var(--red);   }

  /* ── NET PROFIT ROW IN DAILY TABLE ── */
  .net-pos { color: var(--green); font-weight: 600; }
  .net-neg { color: var(--red);   font-weight: 600; }

  /* ── CHART CONTAINER ── */
  .chart-box { position: relative; width: 100%; }
</style>

<div class="rp-wrap">

  <!-- HEADER -->
  <div class="rp-header">
    <div>
      <div class="rp-title">Financial Reports</div>
      <div class="rp-subtitle">Bar &amp; Restaurant Management System</div>
    </div>
    <div class="rp-actions">
      <a href="export_pdf.php?type=<?= $type ?>&date=<?= $date ?>" target="_blank" class="rp-btn rp-btn-outline">&#128196; PDF</a>
      <a href="export_excel.php?type=<?= $type ?>&date=<?= $date ?>" class="rp-btn rp-btn-green">&#128196; Excel</a>
      <a href="http://localhost/barpos/modules/stock/report.php" target="_blank" class="rp-btn rp-btn-outline">&#128218; Stock</a>
      <a href="daily_cash_control.php?date=<?= date('Y-m-d') ?>" class="rp-btn rp-btn-accent">&#128197; Cash Control</a>
    </div>
  </div>

  <!-- FILTER -->
  <div class="rp-filter">
    <form method="GET" action="" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;width:100%;">
      <div>
        <label>Report Type</label>
        <select name="type" onchange="this.form.submit()">
          <option value="daily"   <?= $type==='daily'  ?'selected':'' ?>>Daily</option>
          <option value="weekly"  <?= $type==='weekly' ?'selected':'' ?>>Weekly</option>
          <option value="monthly" <?= $type==='monthly'?'selected':'' ?>>Monthly</option>
          <option value="yearly"  <?= $type==='yearly' ?'selected':'' ?>>Year-to-Date</option>
        </select>
      </div>
      <div>
        <label>Reference Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
      </div>
      <button type="submit" class="rp-btn rp-btn-accent">Generate</button>
    </form>
  </div>

  <!-- PERIOD LABEL -->
  <div class="rp-period">&#128197; <?= htmlspecialchars($label) ?></div>

  <!-- KPI CARDS -->
  <div class="rp-kpi-grid">
    <div class="rp-kpi green">
      <div class="kpi-label">Total Revenue</div>
      <div class="kpi-value">TZS <?= number_format($totalSales, 0) ?></div>
      <div class="kpi-sub"><?= number_format($txnCount) ?> transactions</div>
    </div>
    <div class="rp-kpi amber">
      <div class="kpi-label">Cost of Goods Sold</div>
      <div class="kpi-value"><?= $totalSales > 0 ? number_format(($totalCOGS/$totalSales)*100,1).'%' : '—' ?> of revenue</div>
      <div class="kpi-sub">TZS <?= number_format($totalCOGS, 0) ?></div>
    </div>
    <div class="rp-kpi blue">
      <div class="kpi-label">Gross Profit</div>
      <div class="kpi-value <?= $grossProfit >= 0 ? 'positive' : 'negative' ?>">
        TZS <?= number_format($grossProfit, 0) ?>
      </div>
      <div class="kpi-sub">Margin: <?= $grossMargin ?>%</div>
    </div>
    <div class="rp-kpi red">
      <div class="kpi-label">Operating Expenses</div>
      <div class="kpi-value">TZS <?= number_format($totalExpenses, 0) ?></div>
      <div class="kpi-sub">&nbsp;</div>
    </div>
    <div class="rp-kpi <?= $netProfit >= 0 ? 'green' : 'red' ?>">
      <div class="kpi-label">Net Profit</div>
      <div class="kpi-value <?= $netProfit >= 0 ? 'positive' : 'negative' ?>">
        TZS <?= number_format($netProfit, 0) ?>
      </div>
      <div class="kpi-sub">Net margin: <?= $netMargin ?>%</div>
    </div>
    <div class="rp-kpi teal">
      <div class="kpi-label">Avg Ticket Size</div>
      <div class="kpi-value">TZS <?= number_format($avgTicket, 0) ?></div>
      <div class="kpi-sub">Per transaction</div>
    </div>
  </div>

  <!-- CASH POSITION SECTION -->
<div class="rp-card" style="margin-bottom:28px; border-left: 4px solid #0ea5e9;">
  <div class="rp-section-title"><span style="background:#0ea5e9;"></span>Real-Time Business Cash Position</div>
  <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:16px;">

    <div style="padding:16px; background:var(--surface-2); border-radius:var(--radius-sm);">
      <div class="kpi-label">Opening Cash</div>
      <div class="kpi-value">TZS <?= number_format($openingCash, 0) ?></div>
      <div class="kpi-sub">Cash + Lipa at start</div>
    </div>

    <div style="padding:16px; background:var(--surface-2); border-radius:var(--radius-sm);">
      <div class="kpi-label">+ Capital Injected</div>
      <div class="kpi-value positive">TZS <?= number_format($totalInjections, 0) ?></div>
      <div class="kpi-sub">Investor top-ups</div>
    </div>

    <div style="padding:16px; background:var(--surface-2); border-radius:var(--radius-sm);">
      <div class="kpi-label">+ Sales Collected</div>
      <div class="kpi-value positive">TZS <?= number_format($totalSales, 0) ?></div>
      <div class="kpi-sub">Revenue received</div>
    </div>

    <div style="padding:16px; background:var(--surface-2); border-radius:var(--radius-sm);">
      <div class="kpi-label">− Expenses Paid</div>
      <div class="kpi-value negative">TZS <?= number_format($totalExpenses, 0) ?></div>
      <div class="kpi-sub">Operating costs</div>
    </div>

    <div style="padding:16px; background:var(--surface-2); border-radius:var(--radius-sm);">
      <div class="kpi-label">− Stock Purchased</div>
      <div class="kpi-value negative">TZS <?= number_format($totalStockBought, 0) ?></div>
      <div class="kpi-sub">Purchase orders received</div>
    </div>

    <div style="padding:16px; background:<?= $cashPosition >= 0 ? 'var(--green-bg)' : 'var(--red-bg)' ?>; border-radius:var(--radius-sm); border: 1px solid <?= $cashPosition >= 0 ? 'var(--green)' : 'var(--red)' ?>;">
      <div class="kpi-label" style="color:<?= $cashPosition >= 0 ? 'var(--green)' : 'var(--red)' ?>;">= Cash Position</div>
      <div class="kpi-value <?= $cashPosition >= 0 ? 'positive' : 'negative' ?>" style="font-size:1.6rem;">
        TZS <?= number_format($cashPosition, 0) ?>
      </div>
      <div class="kpi-sub"><?= $cashPosition >= 0 ? '✓ Business has cash' : '⚠ Cash is negative' ?></div>
    </div>

  </div>

  <?php if ($closingCash !== null): ?>
  <div style="font-size:0.8rem; color:var(--ink-muted); padding-top:10px; border-top:1px solid var(--border);">
    Actual closing balance recorded: <strong style="color:var(--ink);">TZS <?= number_format($closingCash, 0) ?></strong>
    <?php
      $variance = $closingCash - $cashPosition;
    ?>
    &nbsp;|&nbsp; Variance vs calculated: 
    <strong style="color:<?= abs($variance) < 1000 ? 'var(--green)' : 'var(--amber)' ?>;">
      TZS <?= number_format($variance, 0) ?>
    </strong>
  </div>
  <?php endif; ?>
</div>

  <!-- CHARTS ROW -->
  <?php if (!empty($deptLabels)): ?>
  <div class="rp-2col">
    <div class="rp-card">
      <div class="rp-section-title"><span></span>Revenue by Department</div>
      <div class="chart-box" style="height:220px;">
        <canvas id="deptChart" role="img" aria-label="Revenue by department bar chart">Revenue breakdown by department.</canvas>
      </div>
    </div>
    <?php if (!empty($bestItems)): ?>
    <div class="rp-card">
      <div class="rp-section-title"><span></span>Top Items by Revenue</div>
      <div class="chart-box" style="height:220px;">
        <canvas id="itemsChart" role="img" aria-label="Top selling items bar chart">Top items by revenue.</canvas>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($dailyLabels) && $type !== 'yearly'): ?>
  <div class="rp-card" style="margin-bottom:20px;">
    <div class="rp-section-title"><span></span>Revenue &amp; Net Profit Trend</div>
    <div class="chart-box" style="height:240px;">
      <canvas id="trendChart" role="img" aria-label="Revenue and net profit trend line chart">Daily revenue and net profit trend.</canvas>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($monthlyLabels)): ?>
  <div class="rp-card" style="margin-bottom:20px;">
    <div class="rp-section-title"><span></span>Monthly Performance — <?= date('Y', strtotime($date)) ?></div>
    <div class="chart-box" style="height:260px;">
      <canvas id="monthlyChart" role="img" aria-label="Monthly revenue and net profit chart">Monthly performance breakdown.</canvas>
    </div>
  </div>
  <?php endif; ?>

  <!-- SALES + EXPENSES BY DEPT TABLES -->
  <div class="rp-2col">
    <div class="rp-card">
      <div class="rp-section-title"><span></span>Sales by Department</div>
      <table class="rp-table">
        <thead><tr><th>Department</th><th class="num">Revenue (TZS)</th><th>Share</th></tr></thead>
        <tbody>
        <?php foreach ($salesByDept as $r):
          $share = $totalSales > 0 ? round(($r['total']/$totalSales)*100,1) : 0;
          $badge = in_array(strtolower($r['type']),['bar','drinks']) ? 'badge-drinks' :
                   (strtolower($r['type'])==='kitchen' ? 'badge-kitchen' : 'badge-default');
        ?>
        <tr>
          <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['cat']) ?></span></td>
          <td class="num"><?= number_format($r['total'],0) ?></td>
          <td>
            <div class="bar-wrap">
              <div class="bar-track"><div class="bar-fill" style="width:<?= $share ?>%;background:#2d2df0;"></div></div>
              <span class="bar-pct"><?= $share ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="rp-card">
      <div class="rp-section-title"><span></span>Expenses by Department</div>
      <table class="rp-table">
        <thead><tr><th>Department</th><th class="num">Amount (TZS)</th><th>Share</th></tr></thead>
        <tbody>
        <?php foreach ($expByDept as $r):
          $share = $totalExpenses > 0 ? round(($r['total']/$totalExpenses)*100,1) : 0;
          $badge = in_array(strtolower($r['type']),['bar','drinks']) ? 'badge-drinks' :
                   (strtolower($r['type'])==='kitchen' ? 'badge-kitchen' : 'badge-default');
        ?>
        <tr>
          <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['cat']) ?></span></td>
          <td class="num"><?= number_format($r['total'],0) ?></td>
          <td>
            <div class="bar-wrap">
              <div class="bar-track"><div class="bar-fill" style="width:<?= $share ?>%;background:#e03030;"></div></div>
              <span class="bar-pct"><?= $share ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- BEST SELLING ITEMS -->
  <?php if (!empty($brows)): ?>
  <div class="rp-card">
    <div class="rp-section-title"><span></span>Best Selling Items</div>
    <table class="rp-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Item</th>
          <th>Dept</th>
          <th class="num">Qty Sold</th>
          <th class="num">Revenue (TZS)</th>
          <th class="num">COGS (TZS)</th>
          <th class="num">Gross Profit (TZS)</th>
        </tr>
      </thead>
      <tbody>
      <?php $rank = 1; foreach ($brows as $r):
        $badge = in_array(strtolower($r['type']),['bar','drinks']) ? 'badge-drinks' :
                 (strtolower($r['type'])==='kitchen' ? 'badge-kitchen' : 'badge-default');
      ?>
      <tr>
        <td><span class="rank-num <?= $rank <= 3 ? 'top3':'' ?>"><?= $rank++ ?></span></td>
        <td style="font-weight:500;"><?= htmlspecialchars($r['description']) ?></td>
        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['cat']) ?></span></td>
        <td class="num"><?= number_format($r['qty_sold'],1) ?></td>
        <td class="num"><?= number_format($r['total_revenue'],0) ?></td>
        <td class="num" style="color:var(--ink-muted);"><?= number_format($r['total_cost'],0) ?></td>
        <td class="num" style="color:<?= $r['gross_profit'] >= 0 ? 'var(--green)':'var(--red)' ?>;font-weight:600;">
          <?= number_format($r['gross_profit'],0) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- EXPENSE BREAKDOWN -->
  <?php if ($expBreakdown && $expBreakdown->num_rows > 0): ?>
  <div class="rp-card">
    <div class="rp-section-title"><span></span>Expense Breakdown</div>
    <table class="rp-table">
      <thead><tr><th>Description</th><th>Department</th><th class="num">Occurrences</th><th class="num">Total (TZS)</th></tr></thead>
      <tbody>
      <?php while ($r = $expBreakdown->fetch_assoc()):
        $badge = strtolower($r['cat']) === 'drinks' ? 'badge-drinks' :
                 (strtolower($r['cat']) === 'kitchen' ? 'badge-kitchen' : 'badge-default');
      ?>
      <tr>
        <td><?= htmlspecialchars($r['description']) ?></td>
        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['cat']) ?></span></td>
        <td class="num"><?= $r['cnt'] ?>×</td>
        <td class="num"><?= number_format($r['total'],0) ?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- PROFIT MARGIN PER STOCK ITEM -->
  <?php if ($margins && $margins->num_rows > 0): ?>
  <div class="rp-card">
    <div class="rp-section-title"><span></span>Stock Item Margin Analysis</div>
    <table class="rp-table">
      <thead>
        <tr>
          <th>Item</th>
          <th>Unit</th>
          <th class="num">Cost (TZS)</th>
          <th class="num">Sell (TZS)</th>
          <th class="num">Profit/Unit</th>
          <th class="num">Margin</th>
        </tr>
      </thead>
      <tbody>
      <?php while ($r = $margins->fetch_assoc()):
        $cls = $r['margin_pct'] >= 30 ? 'good' : ($r['margin_pct'] >= 10 ? 'avg' : 'low');
      ?>
      <tr>
        <td style="font-weight:500;"><?= htmlspecialchars($r['name']) ?></td>
        <td style="color:var(--ink-muted);"><?= htmlspecialchars($r['unit']) ?></td>
        <td class="num"><?= number_format($r['cost_price'],0) ?></td>
        <td class="num"><?= number_format($r['selling_price'],0) ?></td>
        <td class="num"><?= number_format($r['profit'],0) ?></td>
        <td class="num"><span class="mpill <?= $cls ?>"><?= $r['margin_pct'] ?>%</span></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
    <div style="display:flex;gap:16px;margin-top:14px;font-size:0.75rem;">
      <span style="display:flex;align-items:center;gap:5px;color:var(--green);">&#9632; ≥30% Good</span>
      <span style="display:flex;align-items:center;gap:5px;color:var(--amber);">&#9632; 10–29% Average</span>
      <span style="display:flex;align-items:center;gap:5px;color:var(--red);">&#9632; &lt;10% Low</span>
    </div>
  </div>
  <?php endif; ?>

  <!-- DAILY BREAKDOWN TABLE -->
  <?php if (!empty($rows) && $type !== 'yearly'): ?>
  <div class="rp-card">
    <div class="rp-section-title"><span></span>Daily Breakdown</div>
    <table class="rp-table">
      <thead>
        <tr>
          <th>Date</th>
          <th class="num">Revenue (TZS)</th>
          <th class="num">COGS (TZS)</th>
          <th class="num">Expenses (TZS)</th>
          <th class="num">Net (TZS)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= date('D, d M Y', strtotime($r['day'])) ?></td>
        <td class="num"><?= number_format($r['sales'],0) ?></td>
        <td class="num" style="color:var(--ink-muted);"><?= number_format($r['cogs'],0) ?></td>
        <td class="num" style="color:var(--ink-muted);"><?= number_format($r['expenses'],0) ?></td>
        <td class="num <?= $r['net'] >= 0 ? 'net-pos':'net-neg' ?>"><?= number_format($r['net'],0) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- MONTHLY SUMMARY TABLE (yearly view) -->
  <?php if ($type === 'yearly' && !empty($mrows)): ?>
  <div class="rp-card">
    <div class="rp-section-title"><span></span>Monthly Summary — <?= date('Y', strtotime($date)) ?></div>
    <table class="rp-table">
      <thead>
        <tr>
          <th>Month</th>
          <th class="num">Revenue (TZS)</th>
          <th class="num">COGS (TZS)</th>
          <th class="num">Expenses (TZS)</th>
          <th class="num">Net (TZS)</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $mnames = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      foreach ($mrows as $r):
      ?>
      <tr>
        <td style="font-weight:500;"><?= $mnames[$r['m']] ?></td>
        <td class="num"><?= number_format($r['sales'],0) ?></td>
        <td class="num" style="color:var(--ink-muted);"><?= number_format($r['cogs'],0) ?></td>
        <td class="num" style="color:var(--ink-muted);"><?= number_format($r['expenses'],0) ?></td>
        <td class="num <?= $r['net'] >= 0 ? 'net-pos':'net-neg' ?>"><?= number_format($r['net'],0) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>

<!-- CHARTS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
const ACCENT  = '#2d2df0';
const GREEN   = '#00a86b';
const AMBER   = '#d97706';
const RED     = '#e03030';
const MUTED   = '#8888a0';
const SURFACE = '#f7f7fb';

Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.color = MUTED;

const fmtTZS = v => 'TZS ' + Math.round(v).toLocaleString();

<?php if (!empty($deptLabels)): ?>
new Chart(document.getElementById('deptChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($deptLabels) ?>,
    datasets: [{
      label: 'Revenue',
      data: <?= json_encode($deptSales) ?>,
      backgroundColor: ACCENT,
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11 } } },
      y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { callback: v => 'TZS ' + (v/1000).toFixed(0) + 'k' } }
    }
  }
});
<?php endif; ?>

<?php if (!empty($bestItems)): ?>
new Chart(document.getElementById('itemsChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($s) => strlen($s)>16?substr($s,0,14).'…':$s, $bestItems)) ?>,
    datasets: [{
      label: 'Revenue',
      data: <?= json_encode($bestRevenue) ?>,
      backgroundColor: '#0ea5e9',
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 35 } },
      y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { callback: v => 'TZS ' + (v/1000).toFixed(0) + 'k' } }
    }
  }
});
<?php endif; ?>

<?php if (!empty($dailyLabels) && $type !== 'yearly'): ?>
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($dailyLabels) ?>,
    datasets: [
      {
        label: 'Revenue',
        data: <?= json_encode($dailySales) ?>,
        borderColor: ACCENT,
        backgroundColor: 'rgba(45,45,240,0.07)',
        fill: true,
        tension: 0.35,
        pointRadius: 4,
        pointBackgroundColor: ACCENT,
        borderDash: [],
      },
      {
        label: 'Net Profit',
        data: <?= json_encode($dailyNet) ?>,
        borderColor: GREEN,
        backgroundColor: 'transparent',
        fill: false,
        tension: 0.35,
        pointRadius: 4,
        pointBackgroundColor: GREEN,
        borderDash: [5,3],
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: ctx => fmtTZS(ctx.raw) } }
    },
    scales: {
      x: { grid: { display: false } },
      y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { callback: v => 'TZS ' + (v/1000).toFixed(0)+'k' } }
    }
  }
});
<?php endif; ?>

<?php if (!empty($monthlyLabels)): ?>
new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($monthlyLabels) ?>,
    datasets: [
      {
        label: 'Revenue',
        data: <?= json_encode($monthlySales) ?>,
        backgroundColor: ACCENT,
        borderRadius: 5,
        borderSkipped: false,
      },
      {
        label: 'Net Profit',
        data: <?= json_encode($monthlyNet) ?>,
        backgroundColor: GREEN,
        borderRadius: 5,
        borderSkipped: false,
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + fmtTZS(ctx.raw) } }
    },
    scales: {
      x: { grid: { display: false } },
      y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { callback: v => 'TZS ' + (v/1000).toFixed(0)+'k' } }
    }
  }
});
<?php endif; ?>
</script>

<?php require_once '../../includes/footer.php'; ?>
