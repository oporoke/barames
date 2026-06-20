<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

// By hour of day (0-23)
$hourRows = q($conn, "
    SELECT HOUR(created_at) AS hr, COUNT(*) AS txns, COALESCE(SUM(total),0) AS revenue
    FROM sale_transactions
    WHERE type='sale' AND sale_date BETWEEN ? AND ?
    GROUP BY HOUR(created_at)
    ORDER BY hr
", [$start, $end])->fetch_all(MYSQLI_ASSOC);
$byHour = array_fill(0, 24, ['txns' => 0, 'revenue' => 0]);
foreach ($hourRows as $r) { $byHour[(int)$r['hr']] = ['txns' => $r['txns'], 'revenue' => $r['revenue']]; }
$maxHourRevenue = max(array_column($byHour, 'revenue')) ?: 1;

// By day of week (1=Sunday..7=Saturday in MySQL DAYOFWEEK)
$dowRows = q($conn, "
    SELECT DAYOFWEEK(sale_date) AS dow, COUNT(*) AS txns, COALESCE(SUM(total),0) AS revenue
    FROM sale_transactions
    WHERE type='sale' AND sale_date BETWEEN ? AND ?
    GROUP BY DAYOFWEEK(sale_date)
", [$start, $end])->fetch_all(MYSQLI_ASSOC);
$dowNames = [1=>'Sunday',2=>'Monday',3=>'Tuesday',4=>'Wednesday',5=>'Thursday',6=>'Friday',7=>'Saturday'];
$byDow = [];
foreach ($dowNames as $k => $name) { $byDow[$k] = ['name' => $name, 'txns' => 0, 'revenue' => 0]; }
foreach ($dowRows as $r) { $byDow[(int)$r['dow']]['txns'] = $r['txns']; $byDow[(int)$r['dow']]['revenue'] = $r['revenue']; }
$maxDowRevenue = max(array_column($byDow, 'revenue')) ?: 1;

$peakHour = array_keys($byHour, max($byHour))[0] ?? null;
// recompute peak hour properly by revenue
$peakHourIdx = 0; $peakHourVal = -1;
foreach ($byHour as $h => $v) { if ($v['revenue'] > $peakHourVal) { $peakHourVal = $v['revenue']; $peakHourIdx = $h; } }
$peakDowName = ''; $peakDowVal = -1;
foreach ($byDow as $d => $v) { if ($v['revenue'] > $peakDowVal) { $peakDowVal = $v['revenue']; $peakDowName = $v['name']; } }

layoutHead('Sales by Time', 'Peak hours and busiest days', 'sales_by_time', 'sales_by_time');
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi blue">
    <div class="kpi-label">Peak Hour</div>
    <div class="kpi-value"><?= str_pad($peakHourIdx,2,'0',STR_PAD_LEFT) ?>:00</div>
    <div class="kpi-sub">TZS <?= number_format($peakHourVal,0) ?> revenue</div>
  </div>
  <div class="rp-kpi teal">
    <div class="kpi-label">Busiest Day</div>
    <div class="kpi-value" style="font-size:1.2rem;"><?= $peakDowName ?: '—' ?></div>
    <div class="kpi-sub">TZS <?= number_format($peakDowVal,0) ?> revenue</div>
  </div>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Sales by Hour of Day</div>
  <table class="rp-table">
    <thead><tr><th>Hour</th><th class="num">Transactions</th><th class="num">Revenue</th><th>Share</th></tr></thead>
    <tbody>
    <?php for ($h = 6; $h < 24; $h++): $v = $byHour[$h]; $pct = round(($v['revenue']/$maxHourRevenue)*100); if ($v['txns']==0 && $v['revenue']==0) continue; ?>
      <tr>
        <td><?= str_pad($h,2,'0',STR_PAD_LEFT) ?>:00 – <?= str_pad($h+1,2,'0',STR_PAD_LEFT) ?>:00</td>
        <td class="num"><?= $v['txns'] ?></td>
        <td class="num">TZS <?= number_format($v['revenue'],0) ?></td>
        <td><div class="bar-wrap"><div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:#0ea5e9;"></div></div><div class="bar-pct"><?= $pct ?>%</div></div></td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Sales by Day of Week</div>
  <table class="rp-table">
    <thead><tr><th>Day</th><th class="num">Transactions</th><th class="num">Revenue</th><th>Share</th></tr></thead>
    <tbody>
    <?php foreach ($byDow as $v): $pct = round(($v['revenue']/$maxDowRevenue)*100); ?>
      <tr>
        <td><?= $v['name'] ?></td>
        <td class="num"><?= $v['txns'] ?></td>
        <td class="num">TZS <?= number_format($v['revenue'],0) ?></td>
        <td><div class="bar-wrap"><div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:var(--accent);"></div></div><div class="bar-pct"><?= $pct ?>%</div></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php layoutFoot(); ?>
