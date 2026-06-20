<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();

$summary = q($conn, "
    SELECT COALESCE(SUM(subtotal),0) AS subtotal,
           COALESCE(SUM(discount),0) AS discount,
           COALESCE(SUM(tax_amount),0) AS tax,
           COALESCE(SUM(total),0) AS total,
           COUNT(*) AS txns
    FROM sale_transactions
    WHERE type='sale' AND sale_date BETWEEN ? AND ?
", [$start, $end])->fetch_assoc();

$byRate = q($conn, "
    SELECT tax_rate, COUNT(*) AS txns, COALESCE(SUM(subtotal),0) AS subtotal, COALESCE(SUM(tax_amount),0) AS tax
    FROM sale_transactions
    WHERE type='sale' AND sale_date BETWEEN ? AND ?
    GROUP BY tax_rate
    ORDER BY tax_rate DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

$byDay = q($conn, "
    SELECT sale_date, COALESCE(SUM(subtotal),0) AS subtotal, COALESCE(SUM(tax_amount),0) AS tax, COALESCE(SUM(total),0) AS total
    FROM sale_transactions
    WHERE type='sale' AND sale_date BETWEEN ? AND ?
    GROUP BY sale_date
    ORDER BY sale_date DESC
", [$start, $end])->fetch_all(MYSQLI_ASSOC);

layoutHead('Tax / VAT Report', 'Tax collected, for compliance filing', 'tax', 'tax_report');
rangeFilterBar($start, $end);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi blue">
    <div class="kpi-label">Taxable Subtotal</div>
    <div class="kpi-value">TZS <?= number_format($summary['subtotal'], 0) ?></div>
    <div class="kpi-sub"><?= $summary['txns'] ?> sales</div>
  </div>
  <div class="rp-kpi green">
    <div class="kpi-label">Tax Collected</div>
    <div class="kpi-value">TZS <?= number_format($summary['tax'], 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
  <div class="rp-kpi amber">
    <div class="kpi-label">Discounts Applied</div>
    <div class="kpi-value">TZS <?= number_format($summary['discount'], 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
  <div class="rp-kpi teal">
    <div class="kpi-label">Gross Total (incl. tax)</div>
    <div class="kpi-value">TZS <?= number_format($summary['total'], 0) ?></div>
    <div class="kpi-sub">&nbsp;</div>
  </div>
</div>

<?php if (empty($byRate)): ?>
  <div class="rp-card"><div class="rp-empty">No sales in this period.</div></div>
<?php else: ?>

<div class="rp-card">
  <div class="rp-section-title"><span></span>By Tax Rate</div>
  <table class="rp-table">
    <thead><tr><th>Rate</th><th class="num">Txns</th><th class="num">Subtotal</th><th class="num">Tax Collected</th></tr></thead>
    <tbody>
    <?php foreach ($byRate as $r): ?>
      <tr>
        <td><?= number_format($r['tax_rate'],2) ?>%</td>
        <td class="num"><?= $r['txns'] ?></td>
        <td class="num">TZS <?= number_format($r['subtotal'],0) ?></td>
        <td class="num">TZS <?= number_format($r['tax'],0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Daily Breakdown</div>
  <table class="rp-table">
    <thead><tr><th>Date</th><th class="num">Subtotal</th><th class="num">Tax</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($byDay as $d): ?>
      <tr>
        <td><?= date('d M Y', strtotime($d['sale_date'])) ?></td>
        <td class="num">TZS <?= number_format($d['subtotal'],0) ?></td>
        <td class="num">TZS <?= number_format($d['tax'],0) ?></td>
        <td class="num">TZS <?= number_format($d['total'],0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; layoutFoot(); ?>
