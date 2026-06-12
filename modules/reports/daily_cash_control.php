<?php
/**
 * Daily Cash Control Report
 * Roles: admin, manager
 */
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireRole('admin', 'manager');

$date     = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
            ? $_GET['date'] : date('Y-m-d');
$userId   = $_SESSION['user_id'] ?? 0;
$message  = '';
$msgType  = '';

// ── HELPER ───────────────────────────────────────────────────────────
function q($conn, $sql, $params = []) {
    if (empty($params)) return $conn->query($sql);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

// ── HANDLE FORM SAVES ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_opening') {
        $oCash = (float)str_replace(',', '', $_POST['opening_cash'] ?? 0);
        $oLipa = (float)str_replace(',', '', $_POST['opening_lipa'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $stmt  = $conn->prepare("
            INSERT INTO daily_cash_control (report_date, opening_cash, opening_lipa, notes, created_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                opening_cash = VALUES(opening_cash),
                opening_lipa = VALUES(opening_lipa),
                notes        = VALUES(notes),
                updated_by   = VALUES(created_by)
        ");
        $stmt->bind_param('sddsi', $date, $oCash, $oLipa, $notes, $userId);
        $stmt->execute();
        $message = 'Opening balances saved.';
        $msgType = 'success';
    }

    if ($action === 'save_closing') {
        $cCash = (float)str_replace(',', '', $_POST['closing_cash'] ?? 0);
        $cLipa = (float)str_replace(',', '', $_POST['closing_lipa'] ?? 0);
        $stmt  = $conn->prepare("
            UPDATE daily_cash_control
            SET closing_cash = ?, closing_lipa = ?, updated_by = ?
            WHERE report_date = ?
        ");
        $stmt->bind_param('ddis', $cCash, $cLipa, $userId, $date);
        $stmt->execute();
        $message = 'Closing balances saved.';
        $msgType = 'success';
    }

    if ($action === 'add_injection') {
        $amount = (float)str_replace(',', '', $_POST['injection_amount'] ?? 0);
        $method = in_array($_POST['injection_method'] ?? '', ['cash','lipa']) ? $_POST['injection_method'] : 'cash';
        $desc   = trim($_POST['injection_desc'] ?? '');
        if ($amount > 0) {
            $stmt = $conn->prepare("
                INSERT INTO capital_injections (injection_date, amount, method, description, recorded_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sdssi', $date, $amount, $method, $desc, $userId);
            $stmt->execute();
            $message = 'Capital injection recorded.';
            $msgType = 'success';
        } else {
            $message = 'Amount must be greater than zero.';
            $msgType = 'error';
        }
    }

    if ($action === 'delete_injection') {
        $injId = (int)($_POST['injection_id'] ?? 0);
        $stmt  = $conn->prepare("DELETE FROM capital_injections WHERE id = ? AND injection_date = ?");
        $stmt->bind_param('is', $injId, $date);
        $stmt->execute();
        $message = 'Injection removed.';
        $msgType = 'success';
    }

    header("Location: ?date=$date" . ($message ? '&msg=' . urlencode($message) . '&mt=' . $msgType : ''));
    exit;
}

if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
    $msgType = $_GET['mt'] ?? 'success';
}

// ── LOAD CONTROL RECORD ───────────────────────────────────────────────
$control = q($conn,
    "SELECT * FROM daily_cash_control WHERE report_date = ?",
    [$date]
)->fetch_assoc() ?: [];

// ── LOAD PREVIOUS DAY CLOSING (as suggested opening) ─────────────────
$prevDate    = date('Y-m-d', strtotime($date . ' -1 day'));
$prevControl = q($conn,
    "SELECT closing_cash, closing_lipa FROM daily_cash_control
     WHERE report_date = ? AND closing_cash IS NOT NULL",
    [$prevDate]
)->fetch_assoc() ?: [];

if (empty($control) && !empty($prevControl)) {
    $control['opening_cash'] = $prevControl['closing_cash'];
    $control['opening_lipa'] = $prevControl['closing_lipa'];
}

// ── SALES DATA ────────────────────────────────────────────────────────
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

// ── EXPENSES DATA ─────────────────────────────────────────────────────
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

// ── COGS ─────────────────────────────────────────────────────────────
$cogsRow = q($conn,
    "SELECT COALESCE(SUM(si.quantity * sk.cost_price),0) AS t
     FROM sale_items si
     JOIN stock_items sk ON si.stock_item_id = sk.id
     JOIN sale_transactions st ON si.transaction_id = st.id
     WHERE st.type='sale' AND st.sale_date = ?",
    [$date]
)->fetch_assoc();
$totalCOGS   = (float)$cogsRow['t'];
$grossProfit = (float)($salesRow['total_sales'] ?? 0) - $totalCOGS;
$netProfit   = $grossProfit - $totalExpenses;

// ── CAPITAL INJECTIONS ────────────────────────────────────────────────
$injections = q($conn,
    "SELECT * FROM capital_injections WHERE injection_date = ? ORDER BY created_at ASC",
    [$date]
)->fetch_all(MYSQLI_ASSOC);

$totalInjCash = array_sum(array_column(array_filter($injections, fn($r) => $r['method'] === 'cash'), 'amount'));
$totalInjLipa = array_sum(array_column(array_filter($injections, fn($r) => $r['method'] === 'lipa'), 'amount'));
$totalInjections = $totalInjCash + $totalInjLipa;

// ── CUMULATIVE INVESTMENT ─────────────────────────────────────────────
$totalInvestmentRow = q($conn,
    "SELECT COALESCE(SUM(amount),0) AS t FROM capital_injections WHERE injection_date <= ?",
    [$date]
)->fetch_assoc();
$totalInvestmentToDate = (float)$totalInvestmentRow['t'];

// ── DERIVED FIGURES ───────────────────────────────────────────────────
$openingCash         = (float)($control['opening_cash'] ?? 0);
$openingLipa         = (float)($control['opening_lipa'] ?? 0);
$totalOpening        = $openingCash + $openingLipa;
$totalSales          = (float)($salesRow['total_sales'] ?? 0);
$cashSales           = (float)($salesRow['cash_sales']  ?? 0);
$lipaSales           = (float)($salesRow['lipa_sales']  ?? 0);

// Expected closing factors in injections by method
$expectedClosingCash = $openingCash + $cashSales + $totalInjCash - $totalExpenses;
$expectedClosingLipa = $openingLipa + $lipaSales + $totalInjLipa;
$expectedTotal       = $expectedClosingCash + $expectedClosingLipa;

$actualCash          = !empty($control['closing_cash']) ? (float)$control['closing_cash'] : null;
$actualLipa          = !empty($control['closing_lipa']) ? (float)$control['closing_lipa'] : null;
$actualTotal         = ($actualCash !== null && $actualLipa !== null) ? $actualCash + $actualLipa : null;
$difference          = $actualTotal !== null ? $actualTotal - $expectedTotal : null;

$hasOpening          = !empty($control);
$hasClosing          = $hasOpening && !empty($control['closing_cash']);

require_once '../../includes/header.php';
?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@300;400;500;600&display=swap');

  :root {
    --ink:      #0d0d14;
    --soft:     #4a4a5a;
    --muted:    #8888a0;
    --surface:  #ffffff;
    --surface2: #f7f7fb;
    --border:   rgba(0,0,0,0.07);
    --accent:   #2d2df0;
    --green:    #00a86b;
    --green-bg: #e6f9f2;
    --red:      #e03030;
    --red-bg:   #fdeaea;
    --amber:    #d97706;
    --amber-bg: #fef3e0;
    --purple:   #7c3aed;
    --purple-bg:#f3f0ff;
    --radius:   14px;
    --radius-sm:8px;
  }

  .cc-wrap { font-family: 'DM Sans', sans-serif; color: var(--ink); max-width: 860px; padding-bottom: 60px; }

  .cc-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:28px; padding-bottom:20px; border-bottom:1px solid var(--border); }
  .cc-title  { font-family:'Syne',sans-serif; font-size:1.8rem; font-weight:800; letter-spacing:-0.03em; line-height:1; }
  .cc-sub    { font-size:.82rem; color:var(--muted); margin-top:5px; }

  .date-nav  { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .date-nav input[type=date] { border:1px solid var(--border); border-radius:var(--radius-sm); padding:7px 12px; font-family:'DM Sans',sans-serif; font-size:.84rem; color:var(--ink); background:var(--surface2); outline:none; }
  .date-nav input[type=date]:focus { border-color:var(--accent); }
  .cc-btn { display:inline-flex; align-items:center; gap:5px; padding:7px 16px; border-radius:var(--radius-sm); font-size:.8rem; font-weight:500; border:none; cursor:pointer; font-family:'DM Sans',sans-serif; text-decoration:none; transition:opacity .15s; }
  .cc-btn-accent  { background:var(--accent);  color:#fff; }
  .cc-btn-accent:hover  { opacity:.88; }
  .cc-btn-outline { background:transparent; border:1px solid var(--border); color:var(--soft); }
  .cc-btn-outline:hover { background:var(--surface2); }
  .cc-btn-green   { background:var(--green);   color:#fff; }
  .cc-btn-green:hover   { opacity:.88; }
  .cc-btn-purple  { background:var(--purple);  color:#fff; }
  .cc-btn-purple:hover  { opacity:.88; }
  .cc-btn-red     { background:var(--red);     color:#fff; font-size:.75rem; padding:4px 10px; }
  .cc-btn-red:hover     { opacity:.88; }

  .cc-alert { border-radius:var(--radius-sm); padding:10px 16px; font-size:.84rem; margin-bottom:20px; }
  .cc-alert.success { background:var(--green-bg); color:#065f46; }
  .cc-alert.error   { background:var(--red-bg);   color:#991b1b; }

  .status-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:99px; font-size:.75rem; font-weight:600; }
  .status-pill.open   { background:var(--amber-bg); color:var(--amber); }
  .status-pill.closed { background:var(--green-bg); color:var(--green); }
  .status-pill.none   { background:var(--surface2); color:var(--muted); }
  .status-dot { width:6px; height:6px; border-radius:50%; background:currentColor; }

  .cc-section { margin-bottom:20px; }
  .cc-section-title { font-family:'Syne',sans-serif; font-size:.9rem; font-weight:700; letter-spacing:-.01em; color:var(--ink); display:flex; align-items:center; gap:8px; margin-bottom:12px; }
  .cc-section-title .dot { width:6px; height:6px; border-radius:50%; background:var(--accent); }
  .cc-section-title .dot.purple { background:var(--purple); }

  .cc-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:20px 22px; }

  .rpt-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--border); font-size:.9rem; }
  .rpt-row:last-child { border-bottom:none; }
  .rpt-row.total { font-weight:700; font-size:.95rem; }
  .rpt-row.total .rpt-val { color:var(--accent); }
  .rpt-row.sub { padding:6px 0 6px 16px; font-size:.83rem; color:var(--soft); }
  .rpt-row.sub .rpt-val { color:var(--soft); }
  .rpt-row.injection { padding:6px 0 6px 16px; font-size:.83rem; }
  .rpt-label { display:flex; align-items:center; gap:8px; }
  .rpt-val   { font-variant-numeric:tabular-nums; font-weight:500; }
  .rpt-val.green  { color:var(--green); }
  .rpt-val.red    { color:var(--red); }
  .rpt-val.purple { color:var(--purple); }
  .rpt-divider { height:1px; background:var(--border); margin:4px 0; }

  .cc-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .cc-form-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }
  @media(max-width:600px){ .cc-form-grid, .cc-form-grid-3 { grid-template-columns:1fr; } }
  .form-group { display:flex; flex-direction:column; gap:5px; }
  .form-group label { font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); }
  .form-group input[type=text], .form-group input[type=number], .form-group select, .form-group textarea {
    border:1px solid var(--border); border-radius:var(--radius-sm);
    padding:9px 12px; font-family:'DM Sans',sans-serif; font-size:.88rem;
    color:var(--ink); background:var(--surface2); outline:none;
  }
  .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:var(--accent); background:var(--surface); }
  .form-actions { margin-top:16px; display:flex; gap:8px; }

  /* INJECTION BADGE */
  .inj-badge { display:inline-block; padding:2px 8px; border-radius:99px; font-size:.7rem; font-weight:600; }
  .inj-badge.cash { background:var(--green-bg); color:var(--green); }
  .inj-badge.lipa { background:#e8f0ff; color:#1a40c0; }

  /* INVESTMENT SUMMARY CARD */
  .invest-summary { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-top:14px; padding-top:14px; border-top:1px solid var(--border); }
  .invest-kpi { text-align:center; }
  .invest-kpi .lbl { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-bottom:4px; }
  .invest-kpi .val { font-family:'Syne',sans-serif; font-size:1.1rem; font-weight:700; color:var(--purple); }

  .recon-card { border-radius:var(--radius); padding:20px 22px; }
  .recon-card.match    { background:var(--green-bg); border:1px solid #a7f3d0; }
  .recon-card.mismatch { background:var(--red-bg);   border:1px solid #fca5a5; }
  .recon-card.pending  { background:var(--surface2); border:1px solid var(--border); }
  .recon-big { font-family:'Syne',sans-serif; font-size:2rem; font-weight:800; margin:6px 0; }
  .recon-big.green { color:var(--green); }
  .recon-big.red   { color:var(--red); }
  .recon-big.muted { color:var(--muted); }
  .recon-label { font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--soft); }
  .recon-note  { font-size:.8rem; color:var(--soft); margin-top:4px; }
</style>

<div class="cc-wrap">

  <!-- HEADER -->
  <div class="cc-header">
    <div>
      <div class="cc-title">&#128197; Cash Control</div>
      <div class="cc-sub">TRETAT Bar &amp; Restaurant &mdash; Daily Reconciliation</div>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:10px;">
      <form method="GET" action="" class="date-nav">
        <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" onchange="this.form.submit()">
        <a href="cash_control_pdf.php?date=<?= $date ?>" class="cc-btn cc-btn-outline" target="_blank">&#128196; PDF</a>
      </form>
      <?php if ($hasClosing): ?>
        <span class="status-pill closed"><span class="status-dot"></span>Day Closed</span>
      <?php elseif ($hasOpening): ?>
        <span class="status-pill open"><span class="status-dot"></span>Day Open</span>
      <?php else: ?>
        <span class="status-pill none"><span class="status-dot"></span>No Record</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($message): ?>
  <div class="cc-alert <?= $msgType ?>"><?= $message ?></div>
  <?php endif; ?>

  <!-- OPENING FORM -->
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot"></span>Morning Opening</div>
    <div class="cc-card">
      <form method="POST" action="?date=<?= $date ?>">
        <input type="hidden" name="action" value="save_opening">
        <div class="cc-form-grid">
          <div class="form-group">
            <label>Cash at Hand (Opening) TZS</label>
            <input type="text" class="cc-money" name="opening_cash"
              value="<?= htmlspecialchars($control['opening_cash'] ?? '') ?>"
              placeholder="0" <?= $hasClosing ? 'readonly' : '' ?>>
          </div>
          <div class="form-group">
            <label>Lipa Opening Balance TZS</label>
            <input type="text" class="cc-money" name="opening_lipa"
              value="<?= htmlspecialchars($control['opening_lipa'] ?? '') ?>"
              placeholder="0" <?= $hasClosing ? 'readonly' : '' ?>>
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label>Notes (optional)</label>
            <textarea name="notes" rows="2" placeholder="Any opening notes..."
              <?= $hasClosing ? 'readonly' : '' ?>><?= htmlspecialchars($control['notes'] ?? '') ?></textarea>
          </div>
        </div>
        <?php if (!$hasClosing): ?>
        <div class="form-actions">
          <button type="submit" class="cc-btn cc-btn-accent">&#10003; Save Opening</button>
        </div>
        <?php endif; ?>
      </form>

      <?php if ($hasOpening): ?>
      <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
        <div class="rpt-row sub">
          <span class="rpt-label">Cash at Hand</span>
          <span class="rpt-val">TZS <?= number_format($openingCash, 0) ?></span>
        </div>
        <div class="rpt-row sub">
          <span class="rpt-label">Lipa Balance</span>
          <span class="rpt-val">TZS <?= number_format($openingLipa, 0) ?></span>
        </div>
        <div class="rpt-row total">
          <span class="rpt-label">Total Opening Cash</span>
          <span class="rpt-val">TZS <?= number_format($totalOpening, 0) ?></span>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- DAILY SALES -->
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot"></span>Daily Sales</div>
    <div class="cc-card">
      <div class="rpt-row sub">
        <span class="rpt-label">Bar Sales</span>
        <span class="rpt-val green">TZS <?= number_format((float)$salesRow['bar_sales'], 0) ?></span>
      </div>
      <div class="rpt-row sub">
        <span class="rpt-label">Kitchen Sales</span>
        <span class="rpt-val green">TZS <?= number_format((float)$salesRow['kitchen_sales'], 0) ?></span>
      </div>
      <div class="rpt-row total">
        <span class="rpt-label">Total Sales</span>
        <span class="rpt-val green">TZS <?= number_format($totalSales, 0) ?></span>
      </div>
      <div class="rpt-divider"></div>
      <div style="margin-top:4px; font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); padding:6px 0 4px;">Payment Breakdown</div>
      <div class="rpt-row sub">
        <span class="rpt-label">&#128181; Cash Sales</span>
        <span class="rpt-val">TZS <?= number_format($cashSales, 0) ?></span>
      </div>
      <div class="rpt-row sub">
        <span class="rpt-label">&#128242; Lipa Sales</span>
        <span class="rpt-val">TZS <?= number_format($lipaSales, 0) ?></span>
      </div>
      <div class="rpt-row total">
        <span class="rpt-label">Total Receipts</span>
        <span class="rpt-val">TZS <?= number_format($totalSales, 0) ?></span>
      </div>
    </div>
  </div>

  <!-- CAPITAL INJECTIONS -->
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot purple"></span>Capital Injections</div>
    <div class="cc-card">

      <!-- ADD INJECTION FORM -->
      <?php if (!$hasClosing): ?>
      <form method="POST" action="?date=<?= $date ?>">
        <input type="hidden" name="action" value="add_injection">
        <div class="cc-form-grid-3">
          <div class="form-group">
            <label>Amount TZS</label>
            <input type="text" class="cc-money" name="injection_amount" placeholder="0">
          </div>
          <div class="form-group">
            <label>Method</label>
            <select name="injection_method">
              <option value="cash">&#128181; Cash</option>
              <option value="lipa">&#128242; Lipa</option>
            </select>
          </div>
          <div class="form-group">
            <label>Description</label>
            <input type="text" name="injection_desc" placeholder="e.g. Owner top-up">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="cc-btn cc-btn-purple">+ Add Injection</button>
        </div>
      </form>
      <?php endif; ?>

      <!-- INJECTION LIST -->
      <?php if (!empty($injections)): ?>
      <div style="margin-top:<?= !$hasClosing ? '16px' : '0' ?>;<?= !$hasClosing ? 'padding-top:16px;border-top:1px solid var(--border);' : '' ?>">
        <?php foreach ($injections as $inj): ?>
        <div class="rpt-row injection">
          <span class="rpt-label">
            <span class="inj-badge <?= $inj['method'] ?>"><?= strtoupper($inj['method']) ?></span>
            <?= htmlspecialchars($inj['description'] ?: 'Capital injection') ?>
            <span style="color:var(--muted);font-size:.75rem;"><?= date('H:i', strtotime($inj['created_at'])) ?></span>
          </span>
          <span style="display:flex;align-items:center;gap:10px;">
            <span class="rpt-val purple">TZS <?= number_format((float)$inj['amount'], 0) ?></span>
            <?php if (!$hasClosing): ?>
            <form method="POST" action="?date=<?= $date ?>" style="margin:0;">
              <input type="hidden" name="action" value="delete_injection">
              <input type="hidden" name="injection_id" value="<?= $inj['id'] ?>">
              <button type="submit" class="cc-btn cc-btn-red" onclick="return confirm('Remove this injection?')">&#10005;</button>
            </form>
            <?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border);">
          <div class="rpt-row sub">
            <span class="rpt-label">Cash Injections</span>
            <span class="rpt-val purple">TZS <?= number_format($totalInjCash, 0) ?></span>
          </div>
          <div class="rpt-row sub">
            <span class="rpt-label">Lipa Injections</span>
            <span class="rpt-val purple">TZS <?= number_format($totalInjLipa, 0) ?></span>
          </div>
          <div class="rpt-row total">
            <span class="rpt-label">Total Injected Today</span>
            <span class="rpt-val" style="color:var(--purple);">TZS <?= number_format($totalInjections, 0) ?></span>
          </div>
        </div>
      </div>
      <?php elseif ($hasClosing): ?>
        <div style="font-size:.84rem;color:var(--muted);padding:8px 0;">No capital injections recorded for this date.</div>
      <?php endif; ?>

      <!-- CUMULATIVE INVESTMENT -->
      <div class="invest-summary">
        <div class="invest-kpi">
          <div class="lbl">Today's Injection</div>
          <div class="val">TZS <?= number_format($totalInjections, 0) ?></div>
        </div>
        <div class="invest-kpi">
          <div class="lbl">Total Investment to Date</div>
          <div class="val">TZS <?= number_format($totalInvestmentToDate, 0) ?></div>
        </div>
        <div class="invest-kpi">
          <div class="lbl">Business Worth (Cash + Lipa)</div>
          <div class="val">TZS <?= number_format($expectedTotal, 0) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- DAILY EXPENSES -->
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot"></span>Daily Expenses</div>
    <div class="cc-card">
      <?php if (empty($expenses)): ?>
        <div style="font-size:.84rem;color:var(--muted);text-align:center;padding:12px 0;">No expenses recorded for this date.</div>
      <?php else: foreach ($expenses as $e): ?>
      <div class="rpt-row sub">
        <span class="rpt-label"><?= htmlspecialchars($e['description']) ?></span>
        <span class="rpt-val red">TZS <?= number_format((float)$e['total'], 0) ?></span>
      </div>
      <?php endforeach; endif; ?>
      <div class="rpt-row total">
        <span class="rpt-label">Total Expenses</span>
        <span class="rpt-val red">TZS <?= number_format($totalExpenses, 0) ?></span>
      </div>
    </div>
  </div>

  <!-- NET PROFIT SUMMARY -->
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot"></span>Profitability Summary</div>
    <div class="cc-card">
      <div class="rpt-row sub">
        <span class="rpt-label">Total Sales</span>
        <span class="rpt-val green">TZS <?= number_format($totalSales, 0) ?></span>
      </div>
      <div class="rpt-row sub">
        <span class="rpt-label">- Cost of Goods Sold (COGS)</span>
        <span class="rpt-val red">TZS <?= number_format($totalCOGS, 0) ?></span>
      </div>
      <div class="rpt-row total">
        <span class="rpt-label">Gross Profit</span>
        <span class="rpt-val" style="color:<?= $grossProfit >= 0 ? 'var(--green)' : 'var(--red)' ?>">
          TZS <?= number_format($grossProfit, 0) ?>
        </span>
      </div>
      <div class="rpt-row sub">
        <span class="rpt-label">- Operating Expenses</span>
        <span class="rpt-val red">TZS <?= number_format($totalExpenses, 0) ?></span>
      </div>
      <div class="rpt-divider"></div>
      <div class="rpt-row total">
        <span class="rpt-label">Net Profit / Loss</span>
        <span class="rpt-val" style="color:<?= $netProfit >= 0 ? 'var(--green)' : 'var(--red)' ?>;font-size:1.1rem;">
          <?= $netProfit >= 0 ? '▲' : '▼' ?> TZS <?= number_format(abs($netProfit), 0) ?>
        </span>
      </div>
      <?php if ($totalInjections > 0): ?>
      <div style="margin-top:10px;padding:10px 12px;background:var(--purple-bg);border-radius:var(--radius-sm);font-size:.82rem;color:var(--purple);">
        &#9432; TZS <?= number_format($totalInjections, 0) ?> capital injected today is not included in net profit —
        it is owner/investor funding, not business earnings.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CASH MOVEMENT -->
  <?php if ($hasOpening): ?>
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot"></span>Cash Movement</div>
    <div class="cc-card">
      <div class="rpt-row sub">
        <span class="rpt-label">Opening Cash</span>
        <span class="rpt-val">TZS <?= number_format($openingCash, 0) ?></span>
      </div>
      <div class="rpt-row sub">
        <span class="rpt-label">+ Cash Sales</span>
        <span class="rpt-val green">TZS <?= number_format($cashSales, 0) ?></span>
      </div>
      <?php if ($totalInjCash > 0): ?>
      <div class="rpt-row sub">
        <span class="rpt-label">+ Cash Injections</span>
        <span class="rpt-val purple">TZS <?= number_format($totalInjCash, 0) ?></span>
      </div>
      <?php endif; ?>
      <div class="rpt-row sub">
        <span class="rpt-label">- Expenses</span>
        <span class="rpt-val red">TZS <?= number_format($totalExpenses, 0) ?></span>
      </div>
      <div class="rpt-divider"></div>
      <div class="rpt-row total">
        <span class="rpt-label">Expected Closing Cash</span>
        <span class="rpt-val">TZS <?= number_format($expectedClosingCash, 0) ?></span>
      </div>
      <div class="rpt-row sub">
        <span class="rpt-label">Opening Lipa + Lipa Sales<?= $totalInjLipa > 0 ? ' + Lipa Injections' : '' ?></span>
        <span class="rpt-val">TZS <?= number_format($expectedClosingLipa, 0) ?></span>
      </div>
      <div class="rpt-row total">
        <span class="rpt-label">Expected Total Closing Position</span>
        <span class="rpt-val">TZS <?= number_format($expectedTotal, 0) ?></span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ACTUAL CLOSING FORM -->
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot"></span>Actual Closing</div>
    <div class="cc-card">
      <?php if (!$hasOpening): ?>
        <div style="font-size:.84rem;color:var(--muted);text-align:center;padding:12px 0;">Save opening balances first before entering closing figures.</div>
      <?php else: ?>
      <form method="POST" action="?date=<?= $date ?>">
        <input type="hidden" name="action" value="save_closing">
        <div class="cc-form-grid">
          <div class="form-group">
            <label>Actual Cash at Hand TZS</label>
            <input type="text" class="cc-money" name="closing_cash"
              value="<?= htmlspecialchars($control['closing_cash'] ?? '') ?>"
              placeholder="0">
          </div>
          <div class="form-group">
            <label>Actual Lipa Balance TZS</label>
            <input type="text" class="cc-money" name="closing_lipa"
              value="<?= htmlspecialchars($control['closing_lipa'] ?? '') ?>"
              placeholder="0">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="cc-btn cc-btn-green">&#10003; Save Closing</button>
        </div>
      </form>

      <?php if ($hasClosing): ?>
      <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
        <div class="rpt-row sub">
          <span class="rpt-label">Cash at Hand</span>
          <span class="rpt-val">TZS <?= number_format($actualCash, 0) ?></span>
        </div>
        <div class="rpt-row sub">
          <span class="rpt-label">Lipa Balance</span>
          <span class="rpt-val">TZS <?= number_format($actualLipa, 0) ?></span>
        </div>
        <div class="rpt-row total">
          <span class="rpt-label">Total Closing Position</span>
          <span class="rpt-val">TZS <?= number_format($actualTotal, 0) ?></span>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- RECONCILIATION -->
  <?php if ($hasClosing): ?>
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot"></span>Reconciliation</div>
    <?php $isMatch = abs($difference) < 1; ?>
    <div class="recon-card <?= $isMatch ? 'match' : 'mismatch' ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
        <div>
          <div class="recon-label">Expected Closing</div>
          <div class="recon-big muted">TZS <?= number_format($expectedTotal, 0) ?></div>
        </div>
        <div>
          <div class="recon-label">Actual Position</div>
          <div class="recon-big muted">TZS <?= number_format($actualTotal, 0) ?></div>
        </div>
        <div>
          <div class="recon-label">Difference</div>
          <div class="recon-big <?= $isMatch ? 'green' : 'red' ?>">
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
      </div>
    </div>
  </div>
  <?php elseif ($hasOpening): ?>
  <div class="cc-section">
    <div class="cc-section-title"><span class="dot"></span>Reconciliation</div>
    <div class="recon-card pending">
      <div class="recon-label">Awaiting Closing</div>
      <div style="font-size:.84rem;color:var(--muted);margin-top:6px;">Enter actual closing balances above to generate the reconciliation check.</div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
function fmtInput(el) {
    let raw = el.value.replace(/[^0-9.]/g, '');
    el.dataset.raw = raw;
    if (raw === '') { el.value = ''; return; }
    el.value = Math.round(parseFloat(raw)).toLocaleString('en-US');
}

function unFmt(el) {
    el.value = el.dataset.raw || el.value.replace(/[^0-9.]/g, '');
}

document.querySelectorAll('.cc-money').forEach(el => {
    if (el.value) {
        el.dataset.raw = el.value.replace(/[^0-9.]/g, '');
        el.value = Math.round(parseFloat(el.dataset.raw)).toLocaleString('en-US');
    }
    el.addEventListener('focus', () => unFmt(el));
    el.addEventListener('blur',  () => fmtInput(el));
    el.closest('form').addEventListener('submit', () => unFmt(el));
});
</script>

<?php require_once '../../includes/footer.php'; ?>
