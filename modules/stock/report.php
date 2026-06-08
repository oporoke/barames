<?php
/**
 * Executive Stock Report
 * Roles: admin, manager
 */

define('APPDIR', 'barpos');
session_start();

require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

requireRole('admin', 'manager');

// ---------------------------------------------------------------------------
// Period filter
// ---------------------------------------------------------------------------
$period = $_GET['period'] ?? '30d';
$days   = match($period) { '7d' => 7, '90d' => 90, default => 30 };
$since  = date('Y-m-d', strtotime("-{$days} days"));

// ---------------------------------------------------------------------------
// 1. KPI — total stock value, potential revenue, avg margin, SKU count
// ---------------------------------------------------------------------------
$kpi = $conn->query("
    SELECT
        COUNT(*)                                             AS total_skus,
        SUM(quantity * cost_price)                          AS stock_value,
        SUM(quantity * selling_price)                       AS potential_revenue,
        SUM(quantity * selling_price - quantity * cost_price)
            / NULLIF(SUM(quantity * selling_price), 0) * 100 AS avg_margin
    FROM stock_items
    WHERE is_active = 1
")->fetch_assoc();

// ---------------------------------------------------------------------------
// 2. Low / out-of-stock items
// ---------------------------------------------------------------------------
$lowStock = $conn->query("
    SELECT name, quantity, low_stock_threshold
    FROM stock_items
    WHERE is_active = 1
      AND quantity <= low_stock_threshold
    ORDER BY quantity ASC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

$lowCount = (int)$conn->query("
    SELECT COUNT(*) AS c FROM stock_items
    WHERE is_active = 1 AND quantity <= low_stock_threshold
")->fetch_assoc()['c'];

// ---------------------------------------------------------------------------
// 3. Dead stock — no movement in $days days
// ---------------------------------------------------------------------------
$deadStock = $conn->query("
    SELECT s.name,
           COALESCE(MAX(m.moved_at), 'never') AS last_moved,
           DATEDIFF(NOW(), COALESCE(MAX(m.moved_at), s.created_at)) AS days_idle,
           s.quantity * s.cost_price AS idle_value
    FROM stock_items s
    LEFT JOIN stock_movements m ON m.stock_item_id = s.id
    WHERE s.is_active = 1
    GROUP BY s.id, s.name, s.quantity, s.cost_price, s.created_at
    HAVING days_idle >= $days OR last_moved = 'never'
    ORDER BY days_idle DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

$deadCount = (int)$conn->query("
    SELECT COUNT(*) AS c FROM (
        SELECT s.id,
               DATEDIFF(NOW(), COALESCE(MAX(m.moved_at), s.created_at)) AS days_idle
        FROM stock_items s
        LEFT JOIN stock_movements m ON m.stock_item_id = s.id
        WHERE s.is_active = 1
        GROUP BY s.id, s.created_at
        HAVING days_idle >= $days
    ) t
")->fetch_assoc()['c'];

// ---------------------------------------------------------------------------
// 4. Fast movers — highest stock-out quantity in period
// ---------------------------------------------------------------------------
$fastMovers = $conn->query("
    SELECT s.name, SUM(m.quantity) AS total_out
    FROM stock_movements m
    JOIN stock_items s ON s.id = m.stock_item_id
    WHERE m.movement_type = 'out'
      AND m.moved_at >= '$since'
    GROUP BY s.id, s.name
    ORDER BY total_out DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------------
// 5. Stock value by category
// ---------------------------------------------------------------------------
$byCategory = $conn->query("
    SELECT c.name AS category,
           SUM(s.quantity * s.cost_price) AS value
    FROM stock_items s
    JOIN categories c ON c.id = s.category_id
    WHERE s.is_active = 1
    GROUP BY c.id, c.name
    ORDER BY value DESC
")->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------------
// 6. Weekly stock in vs out (last 6 weeks, within period window)
// ---------------------------------------------------------------------------
$weeklyMovements = $conn->query("
    SELECT
        YEARWEEK(moved_at, 1)              AS yw,
        DATE_FORMAT(MIN(moved_at), '%d %b') AS week_label,
        SUM(CASE WHEN movement_type = 'in'  THEN quantity ELSE 0 END) AS stock_in,
        SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END) AS stock_out
    FROM stock_movements
    WHERE moved_at >= DATE_SUB(NOW(), INTERVAL 6 WEEK)
    GROUP BY YEARWEEK(moved_at, 1)
    ORDER BY yw
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------------
// 7. Top items by margin %
// ---------------------------------------------------------------------------
$topMargin = $conn->query("
    SELECT name,
           selling_price,
           cost_price,
           CASE WHEN selling_price > 0
                THEN ROUND((selling_price - cost_price) / selling_price * 100)
                ELSE 0 END AS margin_pct
    FROM stock_items
    WHERE is_active = 1
      AND selling_price > 0
    ORDER BY margin_pct DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------------
// 8. Shrinkage / wastage in period
// ---------------------------------------------------------------------------
$shrinkage = $conn->query("
    SELECT s.name, SUM(m.quantity) AS lost_qty,
           SUM(m.quantity * s.cost_price) AS lost_value
    FROM stock_movements m
    JOIN stock_items s ON s.id = m.stock_item_id
    WHERE m.movement_type = 'adjustment'
      AND m.quantity < 0
      AND m.moved_at >= '$since'
    GROUP BY s.id, s.name
    ORDER BY lost_value DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

$totalShrinkage = array_sum(array_column($shrinkage, 'lost_value'));

// ---------------------------------------------------------------------------
// Encode data for JS charts
// ---------------------------------------------------------------------------
$jsCategory  = json_encode(array_column($byCategory,     'category'));
$jsCatValues = json_encode(array_map('floatval', array_column($byCategory, 'value')));

$jsWeekLabels = json_encode(array_column($weeklyMovements, 'week_label'));
$jsWeekIn     = json_encode(array_map('floatval', array_column($weeklyMovements, 'stock_in')));
$jsWeekOut    = json_encode(array_map('floatval', array_column($weeklyMovements, 'stock_out')));

$jsMarginLabels = json_encode(array_column($topMargin, 'name'));
$jsMarginValues = json_encode(array_map('intval',  array_column($topMargin, 'margin_pct')));

require_once '../../includes/header.php';
?>

<style>
.rpt-page { font-family: 'DM Sans', system-ui, sans-serif; }
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:1.5rem; }
.kpi-card { background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:10px; padding:16px 18px; }
.kpi-label { font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin:0 0 6px; }
.kpi-value { font-size:1.6rem; font-weight:700; margin:0; line-height:1.1; }
.kpi-sub   { font-size:.72rem; color:var(--muted); margin:4px 0 0; }
.section-title { font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin:1.75rem 0 .75rem; }
.charts-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:1.5rem; }
.chart-card { background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:10px; padding:16px; }
.chart-card h4 { margin:0 0 12px; font-size:.82rem; color:var(--muted); font-weight:600; }
.risk-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin-bottom:1.5rem; }
.risk-card { background:var(--card-bg,#fff); border:1px solid var(--border); border-radius:10px; padding:14px 16px; }
.risk-card h4 { margin:0 0 10px; font-size:.8rem; font-weight:600; color:var(--muted); }
.risk-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid var(--border); font-size:.82rem; }
.risk-row:last-child { border-bottom:none; }
.risk-name { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-right:8px; }
.badge { font-size:.68rem; padding:2px 7px; border-radius:99px; font-weight:600; white-space:nowrap; }
.badge-out  { background:#fef2f2; color:#b91c1c; }
.badge-low  { background:#fff7ed; color:#c2410c; }
.badge-ok   { background:#f0fdf4; color:#15803d; }
.badge-muted{ background:#f1f5f9; color:#475569; }
.period-bar { display:flex; gap:6px; margin-bottom:1.25rem; }
.period-btn { padding:5px 14px; border-radius:6px; border:1px solid var(--border); background:transparent; font-size:.8rem; cursor:pointer; color:var(--text); }
.period-btn.active { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.legend-row { display:flex; gap:14px; font-size:.75rem; color:var(--muted); margin-bottom:8px; flex-wrap:wrap; }
.legend-dot { width:9px; height:9px; border-radius:2px; display:inline-block; vertical-align:middle; margin-right:4px; }
.export-btn { padding:6px 14px; border-radius:6px; border:1px solid var(--border); background:transparent; font-size:.8rem; cursor:pointer; }
@media (max-width: 680px) {
    .charts-row { grid-template-columns:1fr; }
}
</style>

<div class="rpt-page">
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
  <h1 class="page-title" style="margin:0;">&#128202; Executive Stock Report</h1>
  <a href="?period=<?= $period ?>&export=csv" class="export-btn">&#8681; Export CSV</a>
  <a href="stock_report_pdf.php?period=<?= $period ?>" class="export-btn">&#128196; Export PDF</a>
</div>

<!-- Period toggle -->
<div class="period-bar">
  <?php foreach (['7d'=>'7 days','30d'=>'30 days','90d'=>'90 days'] as $k=>$label): ?>
  <a href="?period=<?= $k ?>" class="period-btn <?= $period===$k?'active':'' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi-card">
    <p class="kpi-label">Stock value</p>
    <p class="kpi-value">TZS <?= number_format((float)$kpi['stock_value'], 0) ?></p>
    <p class="kpi-sub">at cost price</p>
  </div>
  <div class="kpi-card">
    <p class="kpi-label">Potential revenue</p>
    <p class="kpi-value">TZS <?= number_format((float)$kpi['potential_revenue'], 0) ?></p>
    <p class="kpi-sub">if all stock sold</p>
  </div>
  <div class="kpi-card">
    <p class="kpi-label">Avg margin</p>
    <p class="kpi-value" style="color:<?= (float)$kpi['avg_margin'] >= 30 ? '#15803d' : ((float)$kpi['avg_margin'] >= 10 ? '#c2410c' : '#b91c1c') ?>">
      <?= round((float)$kpi['avg_margin']) ?>%
    </p>
    <p class="kpi-sub">across all items</p>
  </div>
  <div class="kpi-card">
    <p class="kpi-label">Low stock items</p>
    <p class="kpi-value" style="color:#b91c1c;"><?= $lowCount ?></p>
    <p class="kpi-sub">need reordering</p>
  </div>
  <div class="kpi-card">
    <p class="kpi-label">Dead stock</p>
    <p class="kpi-value" style="color:#c2410c;"><?= $deadCount ?></p>
    <p class="kpi-sub">no movement <?= $days ?>d</p>
  </div>
  <div class="kpi-card">
    <p class="kpi-label">Total SKUs</p>
    <p class="kpi-value"><?= (int)$kpi['total_skus'] ?></p>
    <p class="kpi-sub">active items</p>
  </div>
  <div class="kpi-card">
    <p class="kpi-label">Shrinkage loss</p>
    <p class="kpi-value" style="color:#b91c1c;">TZS <?= number_format((float)$totalShrinkage, 0) ?></p>
    <p class="kpi-sub">past <?= $days ?> days</p>
  </div>
</div>

<!-- Charts row -->
<p class="section-title">Stock value &amp; movement</p>
<div class="charts-row">
  <div class="chart-card">
    <h4>Value by category</h4>
    <div class="legend-row" id="catLegend"></div>
    <div style="position:relative;width:100%;height:210px;">
      <canvas id="catChart" role="img" aria-label="Doughnut chart of stock value by category"></canvas>
    </div>
  </div>
  <div class="chart-card">
    <h4>Stock in vs out (units)</h4>
    <div class="legend-row">
      <span><span class="legend-dot" style="background:#2563eb;"></span>Stock in</span>
      <span><span class="legend-dot" style="background:#dc2626;"></span>Stock out</span>
    </div>
    <div style="position:relative;width:100%;height:210px;">
      <canvas id="movChart" role="img" aria-label="Bar chart of weekly stock in versus stock out"></canvas>
    </div>
  </div>
</div>

<!-- Margin chart -->
<p class="section-title">Profitability — top items by margin</p>
<div class="chart-card" style="margin-bottom:1.5rem;">
  <h4>Margin % per item</h4>
  <div style="position:relative;width:100%;height:<?= max(200, count($topMargin) * 36 + 40) ?>px;">
    <canvas id="marginChart" role="img" aria-label="Horizontal bar chart of top items by margin percentage"></canvas>
  </div>
</div>

<!-- Risk panels -->
<p class="section-title">Risk &amp; alerts</p>
<div class="risk-grid">

  <!-- Low stock -->
  <div class="risk-card">
    <h4>&#9888; Low / out of stock</h4>
    <?php if (empty($lowStock)): ?>
      <p style="font-size:.82rem;color:var(--muted);">All items adequately stocked.</p>
    <?php else: foreach ($lowStock as $r): ?>
    <div class="risk-row">
      <span class="risk-name"><?= htmlspecialchars($r['name']) ?></span>
      <?php if ((float)$r['quantity'] == 0): ?>
        <span class="badge badge-out">OUT</span>
      <?php else: ?>
        <span class="badge badge-low"><?= (float)$r['quantity'] ?> left</span>
      <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Dead stock -->
  <div class="risk-card">
    <h4>&#128564; Dead stock (<?= $days ?>d idle)</h4>
    <?php if (empty($deadStock)): ?>
      <p style="font-size:.82rem;color:var(--muted);">No dead stock in this period.</p>
    <?php else: foreach ($deadStock as $r): ?>
    <div class="risk-row">
      <span class="risk-name"><?= htmlspecialchars($r['name']) ?></span>
      <span class="badge badge-muted"><?= (int)$r['days_idle'] ?>d idle</span>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Fast movers -->
  <div class="risk-card">
    <h4>&#128293; Fast movers</h4>
    <?php if (empty($fastMovers)): ?>
      <p style="font-size:.82rem;color:var(--muted);">No movement data for this period.</p>
    <?php else: foreach ($fastMovers as $r): ?>
    <div class="risk-row">
      <span class="risk-name"><?= htmlspecialchars($r['name']) ?></span>
      <span class="badge badge-ok"><?= number_format((float)$r['total_out']) ?> units</span>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Shrinkage -->
  <div class="risk-card">
    <h4>&#128465; Shrinkage / wastage</h4>
    <?php if (empty($shrinkage)): ?>
      <p style="font-size:.82rem;color:var(--muted);">No losses recorded in this period.</p>
    <?php else: foreach ($shrinkage as $r): ?>
    <div class="risk-row">
      <span class="risk-name"><?= htmlspecialchars($r['name']) ?></span>
      <span class="badge badge-out">TZS <?= number_format((float)$r['lost_value'], 0) ?></span>
    </div>
    <?php endforeach; endif; ?>
  </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
var CAT_LABELS  = <?= $jsCategory ?>;
var CAT_VALUES  = <?= $jsCatValues ?>;
var WK_LABELS   = <?= $jsWeekLabels ?>;
var WK_IN       = <?= $jsWeekIn ?>;
var WK_OUT      = <?= $jsWeekOut ?>;
var MG_LABELS   = <?= $jsMarginLabels ?>;
var MG_VALUES   = <?= $jsMarginValues ?>;

var PALETTE = ['#2563eb','#059669','#d97706','#dc2626','#7c3aed','#0891b2','#db2777','#65a30d'];

// Category legend
var legend = document.getElementById('catLegend');
CAT_LABELS.forEach(function(l, i) {
    var total = CAT_VALUES.reduce(function(a,b){return a+b;}, 0);
    var pct   = total > 0 ? Math.round(CAT_VALUES[i] / total * 100) : 0;
    legend.innerHTML +=
        '<span><span class="legend-dot" style="background:' + PALETTE[i % PALETTE.length] + ';"></span>' +
        l + ' ' + pct + '%</span>';
});

// Doughnut
new Chart(document.getElementById('catChart'), {
    type: 'doughnut',
    data: { labels: CAT_LABELS, datasets: [{ data: CAT_VALUES, backgroundColor: PALETTE, borderWidth: 0 }] },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '62%',
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function(c) {
                var total = c.dataset.data.reduce(function(a,b){return a+b;}, 0);
                var pct   = total > 0 ? Math.round(c.parsed / total * 100) : 0;
                return c.label + ': TZS ' + c.parsed.toLocaleString() + ' (' + pct + '%)';
            }}}
        }
    }
});

// Movement bar
new Chart(document.getElementById('movChart'), {
    type: 'bar',
    data: {
        labels: WK_LABELS,
        datasets: [
            { label: 'Stock in',  data: WK_IN,  backgroundColor: '#2563eb' },
            { label: 'Stock out', data: WK_OUT, backgroundColor: '#dc2626' }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { ticks: { autoSkip: false } }, y: { ticks: { maxTicksLimit: 5 } } }
    }
});

// Margin horizontal bar
var marginColors = MG_VALUES.map(function(v) {
    return v >= 30 ? '#059669' : (v >= 10 ? '#d97706' : '#dc2626');
});
new Chart(document.getElementById('marginChart'), {
    type: 'bar',
    data: {
        labels: MG_LABELS,
        datasets: [{ data: MG_VALUES, backgroundColor: marginColors, borderWidth: 0 }]
    },
    options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c){ return ' ' + c.parsed.x + '% margin'; } } } },
        scales: {
            x: { ticks: { callback: function(v){ return v + '%'; }, maxTicksLimit: 6 }, max: 100 },
            y: { ticks: { font: { size: 11 } } }
        }
    }
});
</script>

<?php
// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Re-run as export — headers must come before any output in production.
    // In your app, move this block above require_once header.php.
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock-report-' . $period . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', 'Item', 'Detail', 'Value']);
    fputcsv($out, ['KPI', 'Stock value (TZS)',      '', number_format((float)$kpi['stock_value'], 0)]);
    fputcsv($out, ['KPI', 'Potential revenue (TZS)', '', number_format((float)$kpi['potential_revenue'], 0)]);
    fputcsv($out, ['KPI', 'Avg margin %',            '', round((float)$kpi['avg_margin'])]);
    fputcsv($out, ['KPI', 'Low stock count',         '', $lowCount]);
    fputcsv($out, ['KPI', 'Dead stock count',        '', $deadCount]);
    fputcsv($out, ['KPI', 'Shrinkage loss (TZS)',    '', number_format((float)$totalShrinkage, 0)]);
    fputcsv($out, []);
    fputcsv($out, ['Low Stock', 'Item', 'Qty', 'Threshold']);
    foreach ($lowStock as $r) fputcsv($out, ['', $r['name'], $r['quantity'], $r['low_stock_threshold']]);
    fputcsv($out, []);
    fputcsv($out, ['Dead Stock', 'Item', 'Days idle', 'Idle value (TZS)']);
    foreach ($deadStock as $r) fputcsv($out, ['', $r['name'], $r['days_idle'], number_format((float)$r['idle_value'], 0)]);
    fputcsv($out, []);
    fputcsv($out, ['Fast Movers', 'Item', 'Units out', '']);
    foreach ($fastMovers as $r) fputcsv($out, ['', $r['name'], $r['total_out'], '']);
    fputcsv($out, []);
    fputcsv($out, ['Shrinkage', 'Item', 'Qty lost', 'Value lost (TZS)']);
    foreach ($shrinkage as $r) fputcsv($out, ['', $r['name'], $r['lost_qty'], number_format((float)$r['lost_value'], 0)]);
    fclose($out);
    exit;
}
?>

<?php require_once '../../includes/footer.php'; ?>