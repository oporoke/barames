<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

$byCategory = q($conn, "
    SELECT c.name AS category, COUNT(e.id) AS cnt, COALESCE(SUM(e.amount),0) AS total
    FROM expenses e
    JOIN categories c ON c.id = e.category_id
    WHERE e.expense_date BETWEEN ? AND ?
    GROUP BY c.id, c.name
    ORDER BY total DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$byDay = q($conn, "
    SELECT expense_date, COALESCE(SUM(amount),0) AS total
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
    GROUP BY expense_date
    ORDER BY expense_date DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$flagged = q($conn, "
    SELECT e.id, e.description, e.amount, e.expense_date, c.name AS category
    FROM expenses e
    JOIN categories c ON c.id = e.category_id
    WHERE e.expense_date BETWEEN ? AND ? AND e.is_duplicate_flag = 1
    ORDER BY e.expense_date DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$allRows = q($conn, "
    SELECT e.id, e.description, e.amount, e.expense_date, e.is_duplicate_flag, c.name AS category
    FROM expenses e
    JOIN categories c ON c.id = e.category_id
    WHERE e.expense_date BETWEEN ? AND ?
    ORDER BY e.expense_date DESC, e.id DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$totalExpense = array_sum(array_column($byCategory, 'total'));
$maxCategory = max(array_column($byCategory, 'total') ?: [1]) ?: 1;
$dayCount = max(count($byDay), 1);
$avgDaily = $totalExpense / $dayCount;

layoutHead('Expense Report', 'Spending by category, by day, and flagged duplicates', 'expenses', 'expense_report');
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi red">
    <div class="kpi-label">Total Expenses</div>
    <div class="kpi-value">TZS <?= number_format($totalExpense, 0) ?></div>
    <div class="kpi-sub"><?= array_sum(array_column($byCategory,'cnt')) ?> entries</div>
  </div>
  <div class="rp-kpi amber">
    <div class="kpi-label">Avg Daily Spend</div>
    <div class="kpi-value">TZS <?= number_format($avgDaily, 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
  <div class="rp-kpi blue">
    <div class="kpi-label">Categories</div>
    <div class="kpi-value"><?= count($byCategory) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
  <?php if (!empty($flagged)): ?>
  <div class="rp-kpi red">
    <div class="kpi-label">Flagged Duplicates</div>
    <div class="kpi-value"><?= count($flagged) ?></div>
    <div class="kpi-sub">TZS <?= number_format(array_sum(array_column($flagged,'amount')),0) ?> at risk</div>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($flagged)): ?>
<div class="rp-card" style="border-color:var(--red);background:var(--red-bg);">
  <div class="rp-section-title" style="color:var(--red);"><span style="background:var(--red);"></span>Flagged Possible Duplicate Expenses</div>
  <table class="rp-table">
    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($flagged as $f): ?>
      <tr>
        <td><?= date('d M Y', strtotime($f['expense_date'])) ?></td>
        <td><?= htmlspecialchars($f['category']) ?></td>
        <td><?= htmlspecialchars($f['description']) ?></td>
        <td class="num">TZS <?= number_format($f['amount'],0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if (empty($byCategory)): ?>
  <div class="rp-card"><div class="rp-empty">No expenses recorded in this period.</div></div>
<?php else: ?>

<div class="rp-card">
  <div class="rp-section-title"><span></span>By Category</div>
  <table class="rp-table">
    <thead><tr><th>Category</th><th class="num">Entries</th><th class="num">Total</th><th>Share</th></tr></thead>
    <tbody>
    <?php foreach ($byCategory as $c): $pct = round(($c['total']/$maxCategory)*100); ?>
      <tr>
        <td><strong><?= htmlspecialchars($c['category']) ?></strong></td>
        <td class="num"><?= $c['cnt'] ?></td>
        <td class="num">TZS <?= number_format($c['total'],0) ?></td>
        <td><div class="bar-wrap"><div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:var(--red);"></div></div><div class="bar-pct"><?= $pct ?>%</div></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Daily Trend</div>
  <table class="rp-table">
    <thead><tr><th>Date</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($byDay as $d): ?>
      <tr><td><?= date('d M Y', strtotime($d['expense_date'])) ?></td><td class="num">TZS <?= number_format($d['total'],0) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>All Expenses</div>
  <table class="rp-table">
    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($allRows as $r): ?>
      <tr>
        <td><?= date('d M Y', strtotime($r['expense_date'])) ?></td>
        <td><?= htmlspecialchars($r['category']) ?></td>
        <td><?= htmlspecialchars($r['description']) ?></td>
        <td class="num">TZS <?= number_format($r['amount'],0) ?></td>
        <td><?= $r['is_duplicate_flag'] ? '<span class="mpill low">Duplicate?</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; layoutFoot(); ?>
