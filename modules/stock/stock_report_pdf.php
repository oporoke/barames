<?php
/**
 * Stock Report — PDF Export (Dompdf)
 */
define('APPDIR', 'barpos');
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireRole('admin', 'manager');

require_once $_SERVER['DOCUMENT_ROOT'] . '/barpos/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// ── PERIOD ───────────────────────────────────────────────────────────
$allowed = ['7d', '30d', '90d'];
$period  = in_array($_GET['period'] ?? '', $allowed) ? $_GET['period'] : '30d';
$days    = match($period) { '7d' => 7, '90d' => 90, default => 30 };
$since   = date('Y-m-d', strtotime("-{$days} days"));
$periodLabel = match($period) { '7d' => 'Last 7 days', '90d' => 'Last 90 days', default => 'Last 30 days' };

// ── HELPER ───────────────────────────────────────────────────────────
function qs($conn, $sql, $params = []) {
    if (empty($params)) return $conn->query($sql);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

// ── KPIs ─────────────────────────────────────────────────────────────
$kpi = qs($conn, "
    SELECT
        COUNT(*)                                              AS total_skus,
        SUM(quantity * cost_price)                           AS stock_value,
        SUM(quantity * selling_price)                        AS potential_revenue,
        SUM(quantity * selling_price - quantity * cost_price)
            / NULLIF(SUM(quantity * selling_price), 0) * 100  AS avg_margin
    FROM stock_items WHERE is_active = 1
")->fetch_assoc();

// ── LOW STOCK ────────────────────────────────────────────────────────
$lowStock = qs($conn, "
    SELECT name, quantity, low_stock_threshold
    FROM stock_items WHERE is_active=1 AND quantity <= low_stock_threshold
    ORDER BY quantity ASC LIMIT 15
")->fetch_all(MYSQLI_ASSOC);

$lowCount = (int)qs($conn, "
    SELECT COUNT(*) AS c FROM stock_items
    WHERE is_active=1 AND quantity <= low_stock_threshold
")->fetch_assoc()['c'];

// ── DEAD STOCK ───────────────────────────────────────────────────────
$deadStock = qs($conn, "
    SELECT s.name,
           COALESCE(MAX(m.moved_at),'never') AS last_moved,
           DATEDIFF(NOW(), COALESCE(MAX(m.moved_at), s.created_at)) AS days_idle,
           s.quantity * s.cost_price AS idle_value
    FROM stock_items s
    LEFT JOIN stock_movements m ON m.stock_item_id = s.id
    WHERE s.is_active = 1
    GROUP BY s.id, s.name, s.quantity, s.cost_price, s.created_at
    HAVING days_idle >= $days OR last_moved = 'never'
    ORDER BY days_idle DESC LIMIT 15
")->fetch_all(MYSQLI_ASSOC);

$deadCount = (int)qs($conn, "
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

// ── FAST MOVERS ──────────────────────────────────────────────────────
$fastMovers = qs($conn, "
    SELECT s.name, SUM(m.quantity) AS total_out
    FROM stock_movements m
    JOIN stock_items s ON s.id = m.stock_item_id
    WHERE m.movement_type='out' AND m.moved_at >= ?
    GROUP BY s.id, s.name
    ORDER BY total_out DESC LIMIT 10
", [$since])->fetch_all(MYSQLI_ASSOC);

// ── VALUE BY CATEGORY ────────────────────────────────────────────────
$byCategory = qs($conn, "
    SELECT c.name AS category, SUM(s.quantity * s.cost_price) AS value
    FROM stock_items s
    JOIN categories c ON c.id = s.category_id
    WHERE s.is_active = 1
    GROUP BY c.id, c.name ORDER BY value DESC
")->fetch_all(MYSQLI_ASSOC);

// ── TOP MARGIN ───────────────────────────────────────────────────────
$topMargin = qs($conn, "
    SELECT name, selling_price, cost_price,
           CASE WHEN selling_price > 0
                THEN ROUND((selling_price - cost_price) / selling_price * 100)
                ELSE 0 END AS margin_pct
    FROM stock_items WHERE is_active=1 AND selling_price > 0
    ORDER BY margin_pct DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ── SHRINKAGE ────────────────────────────────────────────────────────
$shrinkage = qs($conn, "
    SELECT s.name, SUM(m.quantity) AS lost_qty,
           SUM(m.quantity * s.cost_price) AS lost_value
    FROM stock_movements m
    JOIN stock_items s ON s.id = m.stock_item_id
    WHERE m.movement_type='adjustment' AND m.quantity < 0 AND m.moved_at >= ?
    GROUP BY s.id, s.name ORDER BY lost_value DESC LIMIT 10
", [$since])->fetch_all(MYSQLI_ASSOC);

$totalShrinkage = array_sum(array_column($shrinkage, 'lost_value'));

// ── HTML ─────────────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a2e; background:#fff; }

  /* HEADER */
  .header { border-bottom: 2px solid #1a1a2e; padding-bottom: 12px; margin-bottom: 18px; overflow: hidden; }
  .header h1 { font-size: 16px; font-weight: bold; }
  .header .sub { font-size: 10px; color: #888; margin-top: 3px; }
  .fl { float: left; } .fr { float: right; text-align: right; }
  .meta { font-size: 10px; color: #888; line-height: 1.6; }
  .clearfix { clear: both; }

  /* KPI TABLE */
  .kpi-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  .kpi-table td { width: 14.28%; padding: 4px; vertical-align: top; }
  .kpi { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; border-top: 3px solid #ccc; }
  .kpi.blue   { border-top-color: #2563eb; }
  .kpi.green  { border-top-color: #059669; }
  .kpi.amber  { border-top-color: #d97706; }
  .kpi.red    { border-top-color: #dc2626; }
  .kpi.orange { border-top-color: #ea580c; }
  .kpi.sky    { border-top-color: #0891b2; }
  .kpi.purple { border-top-color: #7c3aed; }
  .kpi .lbl   { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #888; margin-bottom: 5px; }
  .kpi .val   { font-size: 12px; font-weight: bold; color: #1a1a2e; line-height: 1.2; }
  .kpi .val.pos { color: #059669; }
  .kpi .val.warn{ color: #d97706; }
  .kpi .val.neg { color: #dc2626; }
  .kpi .sub   { font-size: 8px; color: #aaa; margin-top: 3px; }

  /* SECTION */
  .section-title {
    font-size: 10px; font-weight: bold; text-transform: uppercase;
    letter-spacing: 0.06em; color: #1a1a2e;
    border-left: 3px solid #2563eb; padding-left: 7px;
    margin: 16px 0 8px;
  }

  /* TABLES */
  table.data { width: 100%; border-collapse: collapse; margin-bottom: 4px; font-size: 10px; }
  table.data thead th {
    background: #f4f4f8; padding: 5px 7px; text-align: left;
    font-size: 9px; font-weight: bold; text-transform: uppercase;
    letter-spacing: 0.04em; color: #555; border-bottom: 1px solid #e5e7eb;
  }
  table.data thead th.r { text-align: right; }
  table.data tbody td { padding: 5px 7px; border-bottom: 1px solid #f0f0f0; color: #2a2a3a; }
  table.data tbody td.r { text-align: right; }
  table.data tbody tr:nth-child(even) td { background: #fafafa; }
  table.data tbody tr:last-child td { border-bottom: none; }

  /* 2-COL LAYOUT */
  .two-col { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  .two-col td { width: 50%; padding: 0 6px 0 0; vertical-align: top; }
  .two-col td:last-child { padding: 0 0 0 6px; }

  /* BADGE */
  .badge { font-size: 8.5px; padding: 2px 7px; border-radius: 99px; font-weight: bold; white-space: nowrap; }
  .badge-out  { background: #fef2f2; color: #b91c1c; }
  .badge-low  { background: #fff7ed; color: #c2410c; }
  .badge-ok   { background: #f0fdf4; color: #15803d; }
  .badge-idle { background: #f1f5f9; color: #475569; }

  /* MARGIN BAR */
  .mbar-wrap { width: 100%; background: #f0f0f0; border-radius: 3px; height: 6px; }
  .mbar-fill { height: 6px; border-radius: 3px; }

  /* FOOTER */
  .footer { margin-top: 30px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #bbb; overflow: hidden; }
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="fl">
    <h1>Tretat Bar & Restaurant &mdash; Executive Stock Report</h1>
    <div class="sub">Period: <?= $periodLabel ?> &nbsp;|&nbsp; <?= date('d M Y', strtotime($since)) ?> &ndash; <?= date('d M Y') ?></div>
  </div>
  <div class="fr">
    <div class="meta">Generated: <?= date('d M Y, H:i') ?></div>
  </div>
  <div class="clearfix"></div>
</div>

<!-- KPIs -->
<table class="kpi-table">
  <tr>
    <td><div class="kpi blue">
      <div class="lbl">Stock Value</div>
      <div class="val">TZS <?= number_format((float)$kpi['stock_value'], 0) ?></div>
      <div class="sub">at cost price</div>
    </div></td>
    <td><div class="kpi green">
      <div class="lbl">Potential Revenue</div>
      <div class="val">TZS <?= number_format((float)$kpi['potential_revenue'], 0) ?></div>
      <div class="sub">if all stock sold</div>
    </div></td>
    <td><div class="kpi <?= (float)$kpi['avg_margin'] >= 30 ? 'green' : ((float)$kpi['avg_margin'] >= 10 ? 'amber' : 'red') ?>">
      <div class="lbl">Avg Margin</div>
      <div class="val <?= (float)$kpi['avg_margin'] >= 30 ? 'pos' : ((float)$kpi['avg_margin'] >= 10 ? 'warn' : 'neg') ?>"><?= round((float)$kpi['avg_margin']) ?>%</div>
      <div class="sub">across all items</div>
    </div></td>
    <td><div class="kpi sky">
      <div class="lbl">Total SKUs</div>
      <div class="val"><?= (int)$kpi['total_skus'] ?></div>
      <div class="sub">active items</div>
    </div></td>
    <td><div class="kpi red">
      <div class="lbl">Low Stock</div>
      <div class="val neg"><?= $lowCount ?></div>
      <div class="sub">need reordering</div>
    </div></td>
    <td><div class="kpi orange">
      <div class="lbl">Dead Stock</div>
      <div class="val warn"><?= $deadCount ?></div>
      <div class="sub">no movement <?= $days ?>d</div>
    </div></td>
    <td><div class="kpi purple">
      <div class="lbl">Shrinkage</div>
      <div class="val neg">TZS <?= number_format((float)$totalShrinkage, 0) ?></div>
      <div class="sub">past <?= $days ?> days</div>
    </div></td>
  </tr>
</table>

<!-- VALUE BY CATEGORY -->
<?php if (!empty($byCategory)): ?>
<div class="section-title">Stock Value by Category</div>
<table class="data">
  <thead><tr><th>Category</th><th class="r">Value (TZS)</th><th class="r">Share</th></tr></thead>
  <tbody>
  <?php
  $catTotal = array_sum(array_column($byCategory, 'value'));
  foreach ($byCategory as $r):
    $share = $catTotal > 0 ? round(($r['value']/$catTotal)*100,1) : 0;
  ?>
  <tr>
    <td><?= htmlspecialchars($r['category']) ?></td>
    <td class="r"><?= number_format((float)$r['value'], 0) ?></td>
    <td class="r" style="color:#888;"><?= $share ?>%</td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- LOW STOCK + DEAD STOCK (side by side) -->
<div class="section-title">Alerts</div>
<table class="two-col">
  <tr>
    <td>
      <table class="data">
        <thead><tr><th>Low / Out of Stock</th><th class="r">Qty</th><th class="r">Threshold</th></tr></thead>
        <tbody>
        <?php if (empty($lowStock)): ?>
          <tr><td colspan="3" style="color:#888;font-style:italic;">All items adequately stocked.</td></tr>
        <?php else: foreach ($lowStock as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="r">
            <?php if ((float)$r['quantity'] == 0): ?>
              <span class="badge badge-out">OUT</span>
            <?php else: ?>
              <span class="badge badge-low"><?= (float)$r['quantity'] ?></span>
            <?php endif; ?>
          </td>
          <td class="r" style="color:#888;"><?= (float)$r['low_stock_threshold'] ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </td>
    <td>
      <table class="data">
        <thead><tr><th>Dead Stock (<?= $days ?>d idle)</th><th class="r">Days</th><th class="r">Value (TZS)</th></tr></thead>
        <tbody>
        <?php if (empty($deadStock)): ?>
          <tr><td colspan="3" style="color:#888;font-style:italic;">No dead stock in this period.</td></tr>
        <?php else: foreach ($deadStock as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="r"><span class="badge badge-idle"><?= (int)$r['days_idle'] ?>d</span></td>
          <td class="r"><?= number_format((float)$r['idle_value'], 0) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </td>
  </tr>
</table>

<!-- FAST MOVERS + SHRINKAGE (side by side) -->
<div class="section-title">Movement &amp; Losses</div>
<table class="two-col">
  <tr>
    <td>
      <table class="data">
        <thead><tr><th>Fast Movers</th><th class="r">Units Out</th></tr></thead>
        <tbody>
        <?php if (empty($fastMovers)): ?>
          <tr><td colspan="2" style="color:#888;font-style:italic;">No movement data for this period.</td></tr>
        <?php else: foreach ($fastMovers as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="r"><span class="badge badge-ok"><?= number_format((float)$r['total_out']) ?></span></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </td>
    <td>
      <table class="data">
        <thead><tr><th>Shrinkage / Wastage</th><th class="r">Qty</th><th class="r">Loss (TZS)</th></tr></thead>
        <tbody>
        <?php if (empty($shrinkage)): ?>
          <tr><td colspan="3" style="color:#888;font-style:italic;">No losses recorded.</td></tr>
        <?php else: foreach ($shrinkage as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td class="r" style="color:#888;"><?= number_format(abs((float)$r['lost_qty']),1) ?></td>
          <td class="r" style="color:#dc2626;font-weight:bold;"><?= number_format((float)$r['lost_value'], 0) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </td>
  </tr>
</table>

<!-- TOP MARGIN ITEMS -->
<?php if (!empty($topMargin)): ?>
<div class="section-title">Top Items by Margin %</div>
<table class="data">
  <thead>
    <tr>
      <th>#</th>
      <th>Item</th>
      <th class="r">Cost (TZS)</th>
      <th class="r">Sell (TZS)</th>
      <th class="r">Margin</th>
      <th style="width:120px;">Bar</th>
    </tr>
  </thead>
  <tbody>
  <?php $i = 1; foreach ($topMargin as $r):
    $color = $r['margin_pct'] >= 30 ? '#059669' : ($r['margin_pct'] >= 10 ? '#d97706' : '#dc2626');
  ?>
  <tr>
    <td style="color:#aaa;"><?= $i++ ?></td>
    <td style="font-weight:bold;"><?= htmlspecialchars($r['name']) ?></td>
    <td class="r"><?= number_format((float)$r['cost_price'], 0) ?></td>
    <td class="r"><?= number_format((float)$r['selling_price'], 0) ?></td>
    <td class="r" style="color:<?= $color ?>;font-weight:bold;"><?= $r['margin_pct'] ?>%</td>
    <td>
      <div class="mbar-wrap">
        <div class="mbar-fill" style="width:<?= min($r['margin_pct'], 100) ?>%;background:<?= $color ?>;"></div>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- FOOTER -->
<div class="footer">
  <span class="fl">Bar POS System &mdash; Stock Report &mdash; Confidential</span>
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

$filename = 'stock-report-' . $period . '-' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);