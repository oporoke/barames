<?php
/**
 * Shared layout for Advanced Reports module.
 * Include AFTER setting: $pageTitle, $pageSubtitle, $activeTab
 * Call layoutHead() then your content then layoutFoot()
 */

$ADV_TABS = [
    'stock_ledger'    => ['label' => 'Stock Ledger',     'icon' => '&#128230;', 'file' => 'stock_ledger.php'],
    'daily_profit'    => ['label' => 'Daily Profit',     'icon' => '&#128200;', 'file' => 'daily_profit.php'],
    'sales_by_item'   => ['label' => 'Sales by Item',    'icon' => '&#127866;', 'file' => 'sales_by_item.php'],
    'profit_by_item'  => ['label' => 'Profit by Item',   'icon' => '&#128176;', 'file' => 'profit_by_item.php'],
    'sales_by_time'   => ['label' => 'Sales by Time',    'icon' => '&#9201;',   'file' => 'sales_by_time.php'],
    'staff'           => ['label' => 'Staff Performance','icon' => '&#128100;', 'file' => 'staff_performance.php'],
    'audit'           => ['label' => 'Void/Discount Audit','icon' => '&#128737;', 'file' => 'void_audit.php'],
    'tabs'            => ['label' => 'Open Tabs',        'icon' => '&#128221;', 'file' => 'tab_report.php'],
    'tax'             => ['label' => 'Tax / VAT',        'icon' => '&#127974;', 'file' => 'tax_report.php'],
    'suppliers'       => ['label' => 'Supplier Spend',   'icon' => '&#128666;', 'file' => 'supplier_spend.php'],
    'expenses'        => ['label' => 'Expenses',         'icon' => '&#128184;', 'file' => 'expense_report.php'],
];

function layoutHead($pageTitle, $pageSubtitle, $activeTab, $exportType = null) {
    global $ADV_TABS;
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
  .rp-wrap { font-family: 'DM Sans', sans-serif; color: var(--ink); max-width: 1180px; padding: 0 0 60px; }
  .rp-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:24px; padding-bottom:24px; border-bottom:1px solid var(--border); }
  .rp-title { font-family:'Syne',sans-serif; font-size:2rem; font-weight:800; letter-spacing:-0.03em; color:var(--ink); line-height:1; }
  .rp-subtitle { font-size:0.85rem; color:var(--ink-muted); margin-top:6px; font-weight:400; }
  .rp-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .rp-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:var(--radius-sm); font-size:0.8rem; font-weight:500; text-decoration:none; transition:all 0.15s; cursor:pointer; border:none; font-family:'DM Sans',sans-serif; }
  .rp-btn-outline { background:transparent; border:1px solid var(--border); color:var(--ink-soft); }
  .rp-btn-outline:hover { background:var(--surface-2); color:var(--ink); }
  .rp-btn-accent { background:var(--accent); color:#fff; }
  .rp-btn-accent:hover { opacity:0.88; }
  .rp-btn-green { background:var(--green); color:#fff; }
  .rp-btn-green:hover { opacity:0.88; }

  /* ── TAB STRIP ── */
  .rp-tabstrip { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:24px; border-bottom:1px solid var(--border); padding-bottom:0; }
  .rp-tab { display:inline-flex; align-items:center; gap:6px; padding:10px 14px; font-size:0.82rem; font-weight:500; color:var(--ink-muted); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; }
  .rp-tab:hover { color:var(--ink); }
  .rp-tab.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }

  .rp-filter { background:var(--surface-2); border:1px solid var(--border); border-radius:var(--radius); padding:16px 20px; margin-bottom:28px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
  .rp-filter label { font-size:0.72rem; font-weight:500; color:var(--ink-muted); text-transform:uppercase; letter-spacing:0.06em; display:block; margin-bottom:5px; }
  .rp-filter select, .rp-filter input[type=date], .rp-filter input[type=text] { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); padding:8px 12px; font-size:0.85rem; font-family:'DM Sans',sans-serif; color:var(--ink); outline:none; }
  .rp-filter select:focus, .rp-filter input:focus { border-color:var(--accent); }

  .rp-period { display:inline-flex; align-items:center; gap:8px; background:var(--accent-2); color:var(--accent); font-size:0.8rem; font-weight:500; padding:6px 14px; border-radius:100px; margin-bottom:24px; }

  .rp-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:28px; }
  .rp-kpi { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:20px; position:relative; overflow:hidden; transition:box-shadow 0.2s; }
  .rp-kpi:hover { box-shadow:0 4px 20px rgba(0,0,0,0.06); }
  .rp-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:var(--radius) var(--radius) 0 0; }
  .rp-kpi.green::before { background:var(--green); }
  .rp-kpi.red::before   { background:var(--red); }
  .rp-kpi.blue::before  { background:var(--accent); }
  .rp-kpi.amber::before { background:var(--amber); }
  .rp-kpi.teal::before  { background:#0ea5e9; }
  .rp-kpi .kpi-label { font-size:0.72rem; font-weight:500; text-transform:uppercase; letter-spacing:0.07em; color:var(--ink-muted); margin-bottom:10px; }
  .rp-kpi .kpi-value { font-family:'Syne',sans-serif; font-size:1.45rem; font-weight:700; color:var(--ink); letter-spacing:-0.02em; line-height:1; }
  .rp-kpi .kpi-sub { font-size:0.75rem; color:var(--ink-muted); margin-top:6px; }
  .kpi-value.positive { color:var(--green); }
  .kpi-value.negative { color:var(--red); }
  .kpi-value.warn     { color:var(--amber); }

  .rp-section-title { font-family:'Syne',sans-serif; font-size:1rem; font-weight:700; color:var(--ink); margin-bottom:14px; letter-spacing:-0.01em; display:flex; align-items:center; gap:8px; }
  .rp-section-title span { width:6px; height:6px; border-radius:50%; background:var(--accent); display:inline-block; }

  .rp-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:22px 24px; margin-bottom:20px; }
  .rp-2col { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
  @media(max-width:760px){ .rp-2col { grid-template-columns:1fr; } }

  .rp-table { width:100%; border-collapse:collapse; font-size:0.84rem; }
  .rp-table thead th { text-align:left; padding:0 10px 10px; font-size:0.7rem; font-weight:500; text-transform:uppercase; letter-spacing:0.07em; color:var(--ink-muted); border-bottom:1px solid var(--border); }
  .rp-table tbody td { padding:10px; border-bottom:1px solid var(--border); color:var(--ink); vertical-align:middle; }
  .rp-table tbody tr:last-child td { border-bottom:none; }
  .rp-table tbody tr:hover td { background:var(--surface-2); }
  .rp-table .num { text-align:right; font-variant-numeric:tabular-nums; }

  .bar-wrap { display:flex; align-items:center; gap:8px; }
  .bar-track { flex:1; height:5px; background:var(--surface-3); border-radius:99px; }
  .bar-fill  { height:5px; border-radius:99px; }
  .bar-pct   { font-size:0.75rem; color:var(--ink-muted); min-width:34px; text-align:right; }

  .mpill { display:inline-flex; align-items:center; padding:3px 10px; border-radius:99px; font-size:0.78rem; font-weight:600; }
  .mpill.good { background:var(--green-bg); color:var(--green); }
  .mpill.avg  { background:var(--amber-bg); color:var(--amber); }
  .mpill.low  { background:var(--red-bg); color:var(--red); }

  .rp-badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:99px; font-size:0.74rem; font-weight:600; }
  .rp-badge.open   { background:var(--amber-bg); color:var(--amber); }
  .rp-badge.closed { background:var(--green-bg); color:var(--green); }
  .rp-badge.void   { background:var(--red-bg); color:var(--red); }

  .rp-empty { text-align:center; padding:50px 20px; color:var(--ink-muted); font-size:0.88rem; }
</style>

<div class="rp-wrap">
  <div class="rp-header">
    <div>
      <div class="rp-title"><?= htmlspecialchars($pageTitle) ?></div>
      <div class="rp-subtitle"><?= htmlspecialchars($pageSubtitle) ?></div>
    </div>
    <div class="rp-actions">
      <a href="../index.php" class="rp-btn rp-btn-outline">&#8592; Financial Reports</a>
      <?php if ($exportType): ?>
      <a href="export_pdf.php?report=<?= urlencode($exportType) ?>&<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') ?>" target="_blank" class="rp-btn rp-btn-outline">&#128196; PDF</a>
      <a href="export_excel.php?report=<?= urlencode($exportType) ?>&<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') ?>" class="rp-btn rp-btn-green">&#128196; Excel</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="rp-tabstrip">
    <?php foreach ($ADV_TABS as $key => $tab): ?>
      <a href="<?= $tab['file'] ?>" class="rp-tab <?= $activeTab === $key ? 'active' : '' ?>">
        <?= $tab['icon'] ?> <?= htmlspecialchars($tab['label']) ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php
}

function layoutFoot() {
    echo "</div>\n";
}

/** Margin pill helper: returns class good/avg/low based on % margin */
function marginClass($pct) {
    if ($pct >= 50) return 'good';
    if ($pct >= 25) return 'avg';
    return 'low';
}

/** Safe parametrized query helper (same pattern as existing reports/index.php) */
function q($conn, $sql, $params = []) {
    if (empty($params)) return $conn->query($sql);
    $types = str_repeat('s', count($params));
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Get the cost price for a set of items as of a given date.
 * Looks for the latest cost_price_history row with effective_from <= $asOfDate.
 * Falls back to stock_items.cost_price (today's price) when:
 *  - no history row exists for that item before the date (not logged yet / pre-migration)
 *  - the cost_price_history table doesn't exist at all (not migrated yet)
 * Returns [item_id => ['cost' => float, 'is_estimated' => bool]]
 */
function costPricesAsOf($conn, array $itemIds, string $asOfDate) {
    $result = [];
    if (empty($itemIds)) return $result;

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    // Always fetch current cost_price as the fallback baseline first
    $currentRows = q($conn, "SELECT id, cost_price FROM stock_items WHERE id IN ($placeholders)", $itemIds)
        ->fetch_all(MYSQLI_ASSOC);
    foreach ($currentRows as $r) {
        $result[$r['id']] = ['cost' => (float)$r['cost_price'], 'is_estimated' => true];
    }

    // Check if the history table exists before querying it (graceful pre-migration fallback)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'cost_price_history'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return $result; // table not migrated yet -> everything stays "current price, estimated"
    }

    // For each item, the latest history row at or before $asOfDate
    $histRows = q($conn, "
        SELECT h1.stock_item_id, h1.cost_price
        FROM cost_price_history h1
        INNER JOIN (
            SELECT stock_item_id, MAX(effective_from) AS max_date
            FROM cost_price_history
            WHERE stock_item_id IN ($placeholders) AND effective_from <= ?
            GROUP BY stock_item_id
        ) h2 ON h2.stock_item_id = h1.stock_item_id AND h1.effective_from = h2.max_date
    ", array_merge($itemIds, [$asOfDate . ' 23:59:59']))->fetch_all(MYSQLI_ASSOC);

    foreach ($histRows as $h) {
        // Found an actual historical price -> overwrite the fallback, mark as real (not estimated)
        $result[$h['stock_item_id']] = ['cost' => (float)$h['cost_price'], 'is_estimated' => false];
    }

    return $result;
}

/** Resolve date range from $_GET (start/end) with sensible default: last 30 days */
function resolveRange() {
    $end = isset($_GET['end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end']) ? $_GET['end'] : date('Y-m-d');
    $start = isset($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-29 days'));
    return [$start, $end];
}

function rangeFilterBar($start, $end, $extra = '') {
    ?>
  <div class="rp-filter">
    <form method="GET" action="" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;width:100%;">
      <div>
        <label>From</label>
        <input type="date" name="start" value="<?= htmlspecialchars($start) ?>">
      </div>
      <div>
        <label>To</label>
        <input type="date" name="end" value="<?= htmlspecialchars($end) ?>">
      </div>
      <?= $extra ?>
      <button type="submit" class="rp-btn rp-btn-accent">Apply</button>
    </form>
  </div>
<?php
}