<?php
/**
 * Daily Cash Control Report — PDF Export (Dompdf)
 */
define('APPDIR', 'barpos');
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireRole('admin', 'manager');

require_once $_SERVER['DOCUMENT_ROOT'] . '/barpos/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// ── INPUT VALIDATION ─────────────────────────────────────────────────
$date   = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
          ? $_GET['date'] : date('Y-m-d');
$userId = $_SESSION['user_id'] ?? 0;

// ── HELPER ───────────────────────────────────────────────────────────
function q($conn, $sql, $params = []) {
    if (empty($params)) return $conn->query($sql);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

// ── CONTROL RECORD ────────────────────────────────────────────────────
$control = q($conn,
    "SELECT * FROM daily_cash_control WHERE report_date = ?",
    [$date]
)->fetch_assoc();

// ── SALES ─────────────────────────────────────────────────────────────
$salesRow = q($conn,
    "SELECT
        COALESCE(SUM(CASE WHEN c.type='drinks'  THEN s.amount ELSE 0 END),0) AS bar_sales,
        COALESCE(SUM(CASE WHEN c.type='kitchen' THEN s.amount ELSE 0 END),0) AS kitchen_sales,
        COALESCE(SUM(s.amount),0) AS total_sales,
        COALESCE(SUM(CASE WHEN s.payment_method='cash'         THEN s.amount ELSE 0 END),0) AS cash_sales,
        COALESCE(SUM(CASE WHEN s.payment_method='mobile_money' THEN s.amount ELSE 0 END),0) AS lipa_sales
     FROM sales s
     LEFT JOIN categories c ON s.category_id = c.id
     WHERE s.sale_date = ?",
    [$date]
)->fetch_assoc();

// ── EXPENSES ──────────────────────────────────────────────────────────
$expenses = q($conn,
    "SELECT e.description, c.name AS cat, SUM(e.amount) AS total
     FROM expenses e
     JOIN categories c ON e.category_id = c.id
     WHERE e.expense_date = ?
     GROUP BY e.description, e.category_id
     ORDER BY total DESC",
    [$date]
)->fetch_all(MYSQLI_ASSOC);

$totalExpenses = array_sum(array_column($expenses, 'total'));

// ── DERIVED ───────────────────────────────────────────────────────────
$openingCash         = (float)($control['opening_cash'] ?? 0);
$openingLipa         = (float)($control['opening_lipa'] ?? 0);
$totalOpening        = $openingCash + $openingLipa;
$totalSales          = (float)($salesRow['total_sales'] ?? 0);
$cashSales           = (float)($salesRow['cash_sales']  ?? 0);
$lipaSales           = (float)($salesRow['lipa_sales']  ?? 0);
$expectedClosingCash = $openingCash + $cashSales - $totalExpenses;
$expectedClosingLipa = $openingLipa + $lipaSales;
$expectedTotal       = $expectedClosingCash + $expectedClosingLipa;
$actualCash          = isset($control['closing_cash']) ? (float)$control['closing_cash'] : null;
$actualLipa          = isset($control['closing_lipa']) ? (float)$control['closing_lipa'] : null;
$actualTotal         = ($actualCash !== null && $actualLipa !== null) ? $actualCash + $actualLipa : null;
$difference          = $actualTotal !== null ? $actualTotal - $expectedTotal : null;
$hasClosing          = $control && $control['closing_cash'] !== null;

// ── BUILD HTML ────────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a2e; background: #fff; }

  /* HEADER */
  .header { border-bottom: 2px solid #1a1a2e; padding-bottom: 14px; margin-bottom: 20px; overflow: hidden; }
  .header h1 { font-size: 17px; font-weight: bold; letter-spacing: -0.02em; }
  .header .venue { font-size: 11px; font-weight: bold; color: #2d2df0; margin-top: 2px; }
  .header .sub { font-size: 10px; color: #888; margin-top: 3px; }
  .fl { float: left; } .fr { float: right; text-align: right; }
  .meta { font-size: 10px; color: #888; line-height: 1.8; }
  .clearfix { clear: both; }

  /* STATUS BADGE */
  .status { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; }
  .status.closed { background: #e6f9f2; color: #065f46; }
  .status.open   { background: #fef3e0; color: #92400e; }
  .status.none   { background: #f1f5f9; color: #475569; }

  /* SECTION */
  .section-title {
    font-size: 10px; font-weight: bold; text-transform: uppercase;
    letter-spacing: 0.07em; color: #1a1a2e;
    border-left: 3px solid #2d2df0; padding-left: 7px;
    margin: 16px 0 8px;
  }

  /* REPORT TABLE */
  table.rpt { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  table.rpt tr td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; font-size: 10.5px; }
  table.rpt tr:last-child td { border-bottom: none; }
  table.rpt tr.sub td { padding-left: 18px; color: #555; font-size: 10px; }
  table.rpt tr.total td { font-weight: bold; font-size: 11px; border-top: 1px solid #e5e7eb; background: #f9f9fc; }
  table.rpt tr.divider td { padding: 0; border-bottom: 1px solid #e5e7eb; }
  table.rpt td.r { text-align: right; font-variant-numeric: tabular-nums; }
  .green { color: #00a86b; }
  .red   { color: #e03030; }
  .blue  { color: #2d2df0; }
  .muted { color: #888; }
  .bold  { font-weight: bold; }

  /* 2-COL */
  table.two { width: 100%; border-collapse: collapse; }
  table.two td { width: 50%; vertical-align: top; padding: 0 8px 0 0; }
  table.two td:last-child { padding: 0 0 0 8px; }

  /* RECON BOX */
  .recon-box { border-radius: 8px; padding: 14px 16px; margin-top: 6px; overflow: hidden; }
  .recon-box.match    { background: #e6f9f2; border: 1px solid #a7f3d0; }
  .recon-box.mismatch { background: #fdeaea; border: 1px solid #fca5a5; }
  .recon-box.pending  { background: #f7f7fb; border: 1px solid #e5e7eb; }
  .recon-col { float: left; width: 33.33%; }
  .recon-lbl { font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; color: #666; margin-bottom: 5px; }
  .recon-val { font-size: 16px; font-weight: bold; color: #1a1a2e; }
  .recon-val.green { color: #00a86b; }
  .recon-val.red   { color: #e03030; }
  .recon-note { font-size: 9px; color: #555; margin-top: 3px; }

  /* FOOTER */
  .footer { margin-top: 30px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #bbb; overflow: hidden; }
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="fl">
    <h1>Daily Cash Control Report</h1>
    <div class="venue">TRETAT Bar &amp; Restaurant</div>
    <div class="sub">Date: <?= date('d F Y', strtotime($date)) ?></div>
  </div>
  <div class="fr">
    <div class="meta">
      Generated: <?= date('d M Y, H:i') ?><br>
      <?php if ($hasClosing): ?>
        <span class="status closed">Day Closed</span>
      <?php elseif ($control): ?>
        <span class="status open">Day Open</span>
      <?php else: ?>
        <span class="status none">No Record</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="clearfix"></div>
</div>

<!-- TOP: OPENING + SALES side by side -->
<table class="two">
  <tr>
    <td>
      <div class="section-title">Morning Opening</div>
      <table class="rpt">
        <tr class="sub"><td>Cash at Hand</td><td class="r">TZS <?= number_format($openingCash, 0) ?></td></tr>
        <tr class="sub"><td>Lipa Opening Balance</td><td class="r">TZS <?= number_format($openingLipa, 0) ?></td></tr>
        <tr class="total"><td>Total Opening Cash</td><td class="r blue">TZS <?= number_format($totalOpening, 0) ?></td></tr>
      </table>
    </td>
    <td>
      <div class="section-title">Daily Sales</div>
      <table class="rpt">
        <tr class="sub"><td>Bar Sales</td><td class="r green">TZS <?= number_format((float)$salesRow['bar_sales'], 0) ?></td></tr>
        <tr class="sub"><td>Kitchen Sales</td><td class="r green">TZS <?= number_format((float)$salesRow['kitchen_sales'], 0) ?></td></tr>
        <tr class="total"><td>Total Sales</td><td class="r green">TZS <?= number_format($totalSales, 0) ?></td></tr>
      </table>
    </td>
  </tr>
</table>

<!-- PAYMENT BREAKDOWN -->
<div class="section-title">Payment Breakdown</div>
<table class="rpt">
  <tr class="sub"><td>Cash Sales</td><td class="r">TZS <?= number_format($cashSales, 0) ?></td></tr>
  <tr class="sub"><td>Lipa / Mobile Money Sales</td><td class="r">TZS <?= number_format($lipaSales, 0) ?></td></tr>
  <tr class="total"><td>Total Receipts</td><td class="r">TZS <?= number_format($totalSales, 0) ?></td></tr>
</table>

<!-- EXPENSES -->
<div class="section-title">Daily Expenses</div>
<table class="rpt">
  <?php if (empty($expenses)): ?>
  <tr class="sub"><td colspan="2" class="muted">No expenses recorded for this date.</td></tr>
  <?php else: foreach ($expenses as $e): ?>
  <tr class="sub">
    <td><?= htmlspecialchars($e['description']) ?></td>
    <td class="r red">TZS <?= number_format((float)$e['total'], 0) ?></td>
  </tr>
  <?php endforeach; endif; ?>
  <tr class="total"><td>Total Expenses</td><td class="r red">TZS <?= number_format($totalExpenses, 0) ?></td></tr>
</table>

<!-- CASH MOVEMENT + ACTUAL CLOSING side by side -->
<table class="two">
  <tr>
    <td>
      <div class="section-title">Cash Movement</div>
      <table class="rpt">
        <tr class="sub"><td>Opening Cash</td><td class="r">TZS <?= number_format($openingCash, 0) ?></td></tr>
        <tr class="sub"><td>+ Cash Sales</td><td class="r green">TZS <?= number_format($cashSales, 0) ?></td></tr>
        <tr class="sub"><td>- Expenses</td><td class="r red">TZS <?= number_format($totalExpenses, 0) ?></td></tr>
        <tr class="total"><td>Expected Closing Cash</td><td class="r">TZS <?= number_format($expectedClosingCash, 0) ?></td></tr>
        <tr class="sub"><td>+ Lipa (Opening + Sales)</td><td class="r">TZS <?= number_format($expectedClosingLipa, 0) ?></td></tr>
        <tr class="total"><td>Expected Total Position</td><td class="r blue">TZS <?= number_format($expectedTotal, 0) ?></td></tr>
      </table>
    </td>
    <td>
      <div class="section-title">Actual Closing</div>
      <table class="rpt">
        <?php if ($hasClosing): ?>
        <tr class="sub"><td>Cash at Hand</td><td class="r">TZS <?= number_format($actualCash, 0) ?></td></tr>
        <tr class="sub"><td>Lipa Balance</td><td class="r">TZS <?= number_format($actualLipa, 0) ?></td></tr>
        <tr class="total"><td>Total Closing Position</td><td class="r blue">TZS <?= number_format($actualTotal, 0) ?></td></tr>
        <?php else: ?>
        <tr class="sub"><td colspan="2" class="muted">Closing not yet recorded.</td></tr>
        <?php endif; ?>
      </table>
    </td>
  </tr>
</table>

<!-- RECONCILIATION -->
<div class="section-title">Reconciliation Check</div>
<?php if ($hasClosing):
  $isMatch   = abs($difference) < 1;
  $boxClass  = $isMatch ? 'match' : 'mismatch';
?>
<div class="recon-box <?= $boxClass ?>">
  <div class="recon-col">
    <div class="recon-lbl">Expected Closing</div>
    <div class="recon-val">TZS <?= number_format($expectedTotal, 0) ?></div>
  </div>
  <div class="recon-col">
    <div class="recon-lbl">Actual Position</div>
    <div class="recon-val">TZS <?= number_format($actualTotal, 0) ?></div>
  </div>
  <div class="recon-col">
    <div class="recon-lbl">Difference</div>
    <div class="recon-val <?= $isMatch ? 'green' : 'red' ?>">
      <?= $difference >= 0 ? '+' : '' ?>TZS <?= number_format($difference, 0) ?>
    </div>
    <div class="recon-note">
      <?php if ($isMatch): ?>
        &#10003; Balanced — no discrepancy
      <?php elseif ($difference > 0): ?>
        &#9650; Overage — more cash than expected
      <?php else: ?>
        &#9660; Shortage — less cash than expected
      <?php endif; ?>
    </div>
  </div>
  <div class="clearfix"></div>
</div>
<?php else: ?>
<div class="recon-box pending">
  <div class="recon-lbl">Awaiting Closing</div>
  <div class="recon-note" style="margin-top:4px;">Closing balances have not been entered yet.</div>
</div>
<?php endif; ?>

<?php if ($control && $control['notes']): ?>
<div class="section-title">Notes</div>
<div style="font-size:10.5px;color:#555;padding:8px 10px;background:#f9f9fc;border-radius:6px;border:1px solid #e5e7eb;">
  <?= nl2br(htmlspecialchars($control['notes'])) ?>
</div>
<?php endif; ?>

<!-- FOOTER -->
<div class="footer">
  <span class="fl">TRETAT Bar &amp; Restaurant &mdash; Daily Cash Control &mdash; Confidential</span>
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

$filename = 'cash-control-' . $date . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);