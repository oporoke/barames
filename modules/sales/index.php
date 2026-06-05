<?php
define('APPDIR', 'barpos');
session_start();

require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/validate.php';
require_once '../../includes/print/receipt_printer.php';
require_once '../../includes/services/StockService.php';
require_once '../../includes/services/PaymentService.php';

requireLogin();

$formErrors = [];
$lastSaleId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrf();

    /* =========================
       DELETE SALE
    ==========================*/
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];

        $conn->query("DELETE FROM sales WHERE id=$id");
        auditLog($conn, 'sale_delete', "Deleted sale $id");

        $_SESSION['flash'] = ['msg' => 'Sale deleted.', 'type' => 'success'];

        header("Location: " . $_SERVER['PHP_SELF'] . "?date=" . ($_POST['current_date'] ?? date('Y-m-d')));
        exit;
    }

    /* =========================
       EDIT SALE
    ==========================*/
    if (isset($_POST['edit_id'])) {

        $id    = (int)$_POST['edit_id'];
        $cat   = (int)$_POST['category_id'];
        $desc  = trim($_POST['description'] ?? '');
        $qty   = (float)($_POST['quantity'] ?? 1);
        $price = (float)($_POST['unit_price'] ?? 0);
        $amt   = round($qty * $price, 2);
        $pay   = $_POST['payment_method'] ?? 'cash';
        $date  = trim($_POST['sale_date'] ?? '');

        $errs = validateSale(array_merge($_POST, ['amount' => $amt]));

        if (empty($errs)) {

            $stmt = $conn->prepare("
                UPDATE sales
                SET category_id=?, description=?, quantity=?, unit_price=?, amount=?, payment_method=?, sale_date=?
                WHERE id=?
            ");

            $stmt->bind_param(
                "isdddssi",
                $cat, $desc, $qty, $price, $amt, $pay, $date, $id
            );

            $stmt->execute();
            $stmt->close();

            auditLog($conn, 'sale_edit', "Edited sale $id");

            $_SESSION['flash'] = ['msg' => '&#10003; Sale updated.', 'type' => 'success'];

        } else {
            $_SESSION['flash'] = ['msg' => implode(' | ', $errs), 'type' => 'error'];
        }

        header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
        exit;
    }

    /* =========================
       ADD SALE — SPLIT PAYMENT
    ==========================*/
    $cat     = (int)($_POST['category_id'] ?? 0);
    $desc    = trim($_POST['description'] ?? '');
    $qty     = (float)($_POST['quantity'] ?? 1);
    $price   = (float)($_POST['unit_price'] ?? 0);
    $date    = trim($_POST['sale_date'] ?? date('Y-m-d'));
    $amt     = round($qty * $price, 2);
    $stockId = isset($_POST['stock_item_id']) ? (int)$_POST['stock_item_id'] : null;

    // Build payments array from POST
    $payments = [];
    foreach (($_POST['payments'] ?? []) as $p) {
        $payments[] = [
            'method'    => $p['method']    ?? 'cash',
            'amount'    => (float)($p['amount'] ?? 0),
            'reference' => trim($p['reference'] ?? ''),
        ];
    }

    // Fallback: legacy single-payment field
    if (empty($payments) && isset($_POST['payment_method'])) {
        $payments[] = [
            'method'    => $_POST['payment_method'],
            'amount'    => $amt,
            'reference' => trim($_POST['payment_reference'] ?? ''),
        ];
    }

    $formErrors = validateSale(array_merge($_POST, ['amount' => $amt]));
    $payErrors  = PaymentService::validate($payments, $amt);
    $formErrors = array_merge($formErrors, $payErrors);

    if (empty($formErrors)) {

        if (isDuplicateSale($conn, $cat, $desc, $amt, $date) && !isset($_POST['force_submit'])) {
            $formErrors[] = 'DUPLICATE_WARNING';
        } else {

            $conn->begin_transaction();

            try {
                // 1. Insert sale
                $methodSummary = implode('+', array_unique(array_column($payments, 'method')));

                $stmt = $conn->prepare("
                    INSERT INTO sales
                        (category_id, stock_item_id, description,
                         quantity, unit_price, amount, payment_method, sale_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "iissddss",
                    $cat, $stockId, $desc,
                    $qty, $price, $amt,
                    $methodSummary, $date
                );
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $newId = $stmt->insert_id;
                $stmt->close();

                // 2. Insert sale_transaction
                $userId = $_SESSION['user_id'] ?? null;
                $stmt2  = $conn->prepare("
                    INSERT INTO sale_transactions
                        (cashier_id, payment_method, subtotal, total, sale_date)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt2->bind_param("isdds", $userId, $methodSummary, $amt, $amt, $date);
                if (!$stmt2->execute()) throw new Exception($stmt2->error);
                $txnId = $stmt2->insert_id;
                $stmt2->close();

                // 3. Record each payment leg
                foreach ($payments as $p) {
                    PaymentService::addPayment(
                        $conn, $txnId,
                        $p['method'], $p['amount'], $p['reference']
                    );
                }

                // 4. Deduct stock
                if ($stockId) {
                    StockService::deduct($conn, $stockId, $qty, "Sale #$newId");
                }

                auditLog($conn, 'sale_add', "Sale #$newId TZS $amt via $methodSummary");

                $conn->commit();

                printSaleReceipt($conn, $newId);

                $change = PaymentService::changeDue($payments, $amt);
                $msg    = '&#10003; Sale recorded.';
                if ($change > 0) {
                    $msg .= ' Change due: TZS ' . number_format($change, 2);
                }

                $_SESSION['flash'] = ['msg' => $msg, 'type' => 'success'];

                header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = ['msg' => 'Sale failed: ' . $e->getMessage(), 'type' => 'error'];
            }
        }
    }
}

/* =========================
   DATA LOAD
==========================*/
$filter  = isset($_GET['date']) ? sanitize($conn, $_GET['date']) : date('Y-m-d');
$editRow = isset($_GET['edit']) ? $conn->query("SELECT * FROM sales WHERE id=".(int)$_GET['edit'])->fetch_assoc() : null;

$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$totalRows = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE sale_date='$filter'")->fetch_assoc()['c'];
$pages     = max(1, ceil($totalRows / $perPage));

$cols     = $conn->query("SHOW COLUMNS FROM sales")->fetch_all(MYSQLI_ASSOC);
$colNames = array_column($cols, 'Field');
$hasNew   = in_array('payment_method', $colNames);

$sales = $conn->query("
    SELECT s.*, c.name AS cat, c.type AS cat_type
    FROM sales s
    JOIN categories c ON s.category_id=c.id
    WHERE s.sale_date='$filter'
    ORDER BY s.id DESC
    LIMIT $perPage OFFSET $offset
");

$totals = $conn->query("
    SELECT c.type, SUM(s.amount) AS total
    FROM sales s
    JOIN categories c ON s.category_id=c.id
    WHERE s.sale_date='$filter'
    GROUP BY c.type
")->fetch_all(MYSQLI_ASSOC);

$byType = array_column($totals, 'total', 'type');

$payBreak = [];
if ($hasNew) {
    $res = $conn->query("
        SELECT payment_method, SUM(amount) AS total
        FROM sales
        WHERE sale_date='$filter'
        GROUP BY payment_method
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $payBreak[$r['payment_method']] = $r['total'];
        }
    }
}

$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$stockItems = $conn->query("
    SELECT si.id, si.name, si.sku, si.barcode, si.selling_price, si.quantity, si.unit, c.id AS cat_id
    FROM stock_items si
    JOIN categories c ON si.category_id=c.id
    WHERE si.is_active=1
    ORDER BY si.name
")->fetch_all(MYSQLI_ASSOC);

$isDuplicate = in_array('DUPLICATE_WARNING', $formErrors);
$formErrors  = array_filter($formErrors, function($e) {
    return $e !== 'DUPLICATE_WARNING';
});

require_once '../../includes/header.php';
?>

<h1 class="page-title">&#128176; Sales Entry</h1>

<!-- SCANNER STATUS BAR -->
<div id="scannerBar" style="background:#1a1a2e;color:#fff;padding:10px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:12px;font-size:.875rem;">
  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#10b981;" id="scannerDot"></span>
  <span id="scannerStatus">Scanner ready — scan a barcode to auto-fill item</span>
  <span id="scannerResult" style="margin-left:auto;font-weight:600;"></span>
</div>

<?php if (!empty($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= $f['msg'] ?></div>
<?php endif; ?>

<?php if ($isDuplicate): ?>
<div class="alert alert-warning">
  &#9888; <strong>Duplicate detected</strong> — same sale in last 60 seconds.
  <form method="POST" action="" style="display:inline;margin-left:12px;">
    <?= csrfField() ?>
    <?php foreach ($_POST as $k => $v): if ($k === 'csrf_token') continue; ?>
      <?php if (is_array($v)): ?>
        <?php foreach ($v as $i => $sv): ?>
          <?php if (is_array($sv)): ?>
            <?php foreach ($sv as $sk => $sv2): ?>
            <input type="hidden" name="<?= htmlspecialchars($k) ?>[<?= $i ?>][<?= $sk ?>]" value="<?= htmlspecialchars($sv2) ?>">
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php else: ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <input type="hidden" name="force_submit" value="1">
    <button type="submit" class="btn btn-warning btn-sm">Submit Anyway</button>
  </form>
</div>
<?php endif; ?>

<?php if ($editRow): ?>
<div class="card" style="border:2px solid var(--warning);">
  <h3 style="color:var(--warning);">&#9998; Editing Sale #<?= $editRow['id'] ?></h3>
  <form method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="edit_id" value="<?= $editRow['id'] ?>">
    <div class="form-grid">
      <div class="form-group"><label>Department</label>
        <select name="category_id">
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $c['id'] == $editRow['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Description</label>
        <input name="description" value="<?= htmlspecialchars($editRow['description'] ?? '') ?>">
      </div>
      <div class="form-group"><label>Quantity</label>
        <input type="number" name="quantity" step="0.01" min="0.01" value="<?= $editRow['quantity'] ?? 1 ?>" id="eQty">
      </div>
      <div class="form-group"><label>Unit Price</label>
        <input type="number" name="unit_price" step="0.01" min="0" value="<?= $editRow['unit_price'] ?? $editRow['amount'] ?>" id="ePrice">
      </div>
      <div class="form-group"><label>Total</label>
        <input type="text" id="eTotal" readonly style="background:#f8f9fa;font-weight:700;" value="TZS <?= number_format($editRow['amount'], 2) ?>">
      </div>
      <?php if ($hasNew): ?>
      <div class="form-group"><label>Payment Method</label>
        <select name="payment_method">
          <?php foreach (['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'card' => 'Card', 'other' => 'Other'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($editRow['payment_method'] ?? 'cash') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group"><label>Date</label>
        <input type="date" name="sale_date" value="<?= $editRow['sale_date'] ?>" max="<?= date('Y-m-d') ?>" required>
      </div>
    </div>
    <br>
    <div style="display:flex;gap:8px;">
      <button type="submit" class="btn btn-warning">
        <span class="btn-text">&#10003; Update</span><span class="spinner"></span>
      </button>
      <a href="<?= $_SERVER['PHP_SELF'] ?>?date=<?= $filter ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ADD SALE FORM -->
<div class="card">
  <h3>Record a Sale</h3>
  <?= renderErrors(array_values($formErrors)) ?>
  <form method="POST" action="" id="saleForm">
    <?= csrfField() ?>
    <input type="hidden" name="stock_item_id" id="stock_item_id" value="">
    <div class="form-grid">

      <div class="form-group"><label>Department</label>
        <select name="category_id" id="category_id">
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (isset($_POST['category_id']) && $_POST['category_id'] == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group"><label>Description / Item</label>
        <input type="text" name="description" id="description"
          placeholder="Scan barcode or type item name..."
          value="<?= htmlspecialchars($_POST['description'] ?? '') ?>">
      </div>

      <div class="form-group"><label>Quantity</label>
        <input type="number" name="quantity" id="qty"
          step="0.01" min="0.01"
          value="<?= htmlspecialchars($_POST['quantity'] ?? '1') ?>" required>
      </div>

      <div class="form-group"><label>Unit Price (TZS)</label>
        <input type="number" name="unit_price" id="unit_price"
          step="0.01" min="0.01" required placeholder="0.00"
          value="<?= htmlspecialchars($_POST['unit_price'] ?? '') ?>">
      </div>

      <div class="form-group"><label>Total (TZS)</label>
        <input type="text" id="total_display" readonly
          style="background:#f8f9fa;font-weight:700;color:var(--success);"
          placeholder="Auto calculated">
      </div>

      <div class="form-group"><label>Date</label>
        <input type="date" name="sale_date"
          value="<?= htmlspecialchars($_POST['sale_date'] ?? date('Y-m-d')) ?>"
          max="<?= date('Y-m-d') ?>" required>
      </div>

      <!-- SPLIT PAYMENT PANEL — full width -->
      <div class="form-group" style="grid-column:1/-1;">
        <label>Payment</label>
        <div id="paymentLines" style="display:flex;flex-direction:column;gap:8px;"></div>
        <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap;">
          <button type="button" class="btn btn-secondary btn-sm" onclick="addPaymentLine()">
            + Add Payment Method
          </button>
          <span style="margin-left:auto;font-size:.85rem;color:#6b7280;">
            Total: <strong>TZS <span id="saleTotal">0.00</span></strong> &nbsp;|&nbsp;
            Paid: <strong style="color:var(--success);">TZS <span id="paidSoFar">0.00</span></strong> &nbsp;|&nbsp;
            Balance: <strong style="color:var(--danger);">TZS <span id="balanceDue">0.00</span></strong>
          </span>
        </div>
        <div id="changeDueBox"
          style="display:none;background:#d1fae5;padding:10px 14px;border-radius:8px;
                 margin-top:8px;font-weight:700;color:#065f46;font-size:1rem;">
          &#128181; Change Due: TZS <span id="changeDueAmt">0.00</span>
        </div>
      </div>

    </div><!-- /.form-grid -->
    <br>
    <button type="submit" class="btn btn-success" id="submitBtn">
      <span class="btn-text">&#10003; Save &amp; Print Receipt</span>
      <span class="spinner"></span>
    </button>
  </form>
</div>

<!-- STATS -->
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="label">Drinks</div>
    <div class="value">TZS <?= number_format($byType['drinks'] ?? 0, 0) ?></div>
  </div>
  <div class="stat-card green">
    <div class="label">Kitchen</div>
    <div class="value">TZS <?= number_format($byType['kitchen'] ?? 0, 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Total</div>
    <div class="value">TZS <?= number_format(array_sum($byType), 0) ?></div>
  </div>
  <?php if ($hasNew && !empty($payBreak)): ?>
  <div class="stat-card blue">
    <div class="label">&#128181; Cash</div>
    <div class="value">TZS <?= number_format($payBreak['cash'] ?? 0, 0) ?></div>
  </div>
  <div class="stat-card blue">
    <div class="label">&#128241; M-Money</div>
    <div class="value">TZS <?= number_format($payBreak['mobile_money'] ?? 0, 0) ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- SALES TABLE -->
<div class="card">
  <div class="toolbar">
    <div class="toolbar-left">
      <h3 style="margin:0;">Sales — <?= htmlspecialchars($filter) ?> (<?= $totalRows ?>)</h3>
    </div>
    <div class="toolbar-right">
      <div class="search-bar">
        <span class="search-icon">&#128269;</span>
        <input type="text" id="salesSearch" placeholder="Search..." style="width:180px;">
      </div>
      <form method="GET" action="" style="display:flex;gap:6px;">
        <input type="date" name="date" value="<?= htmlspecialchars($filter) ?>"
          style="padding:7px 10px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem;">
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
      </form>
    </div>
  </div>

  <?php if ($sales->num_rows === 0): ?>
  <div class="empty-state">
    <div class="empty-icon">&#128176;</div>
    <p>No sales for this date.</p>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table id="salesTable">
      <thead>
        <tr>
          <th>Dept</th>
          <th>Item</th>
          <?php if ($hasNew): ?><th>Qty</th><th>Price</th><?php endif; ?>
          <th>Total</th>
          <?php if ($hasNew): ?><th>Payment</th><?php endif; ?>
          <th>Time</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $payLabels = ['cash' => 'Cash', 'mobile_money' => 'M-Money', 'card' => 'Card', 'other' => 'Other'];
      $payIcons  = ['cash' => '&#128181;', 'mobile_money' => '&#128241;', 'card' => '&#128179;', 'other' => '&#8226;'];
      while ($row = $sales->fetch_assoc()):
      ?>
      <tr>
        <td><span class="badge badge-<?= $row['cat_type'] ?>"><?= htmlspecialchars($row['cat']) ?></span></td>
        <td><?= htmlspecialchars($row['description'] ?: '—') ?></td>
        <?php if ($hasNew): ?>
        <td><?= $row['quantity'] ?? 1 ?></td>
        <td><?= number_format($row['unit_price'] ?? $row['amount'], 0) ?></td>
        <?php endif; ?>
        <td><strong><?= number_format($row['amount'], 0) ?></strong></td>
        <?php if ($hasNew): ?>
        <td><?= ($payIcons[$row['payment_method']] ?? '') . ' ' . ($payLabels[$row['payment_method']] ?? '') ?></td>
        <?php endif; ?>
        <td><?= date('H:i', strtotime($row['created_at'])) ?></td>
        <td>
          <div style="display:flex;gap:4px;">
            <a href="?date=<?= $filter ?>&edit=<?= $row['id'] ?>" class="btn btn-warning btn-xs">Edit</a>
            <a href="receipt.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-secondary btn-xs">&#128196;</a>
            <button type="button" class="btn btn-primary btn-xs" onclick="reprintReceipt(<?= $row['id'] ?>)">&#128424;</button>
            <form method="POST" action="" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
              <input type="hidden" name="current_date" value="<?= $filter ?>">
              <button type="button" class="btn btn-danger btn-xs"
                onclick="showConfirm('Delete Sale','Delete TZS <?= number_format($row['amount'], 0) ?>?',function(){
                  document.querySelectorAll('input[name=delete_id][value=\'<?= $row['id'] ?>\']').forEach(function(i){i.closest('form').submit();});
                })">Del</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?date=<?= $filter ?>&page=<?= $page - 1 ?>">&#8592;</a>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?date=<?= $filter ?>&page=<?= $i ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
    <a href="?date=<?= $filter ?>&page=<?= $page + 1 ?>">&#8594;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
var STOCK_ITEMS = <?= json_encode($stockItems) ?>;
var CSRF_TOKEN  = '<?= csrfToken() ?>';

// ── BARCODE SCANNER ──────────────────────────────────────────
(function() {
    var buffer  = '';
    var timer   = null;
    var TIMEOUT = 80;

    document.addEventListener('keydown', function(e) {
        var tag = document.activeElement.tagName;
        if (tag === 'INPUT' && document.activeElement.id !== 'description') {
            if (e.key === 'Enter') return;
        }

        if (e.key === 'Enter') {
            if (buffer.length >= 3) {
                lookupBarcode(buffer.trim());
            }
            buffer = '';
            clearTimeout(timer);
            return;
        }

        if (e.key.length === 1) {
            buffer += e.key;
            clearTimeout(timer);
            timer = setTimeout(function() {
                if (buffer.length >= 4 && !/\s/.test(buffer)) {
                    lookupBarcode(buffer.trim());
                }
                buffer = '';
            }, TIMEOUT);
        }
    });

    function lookupBarcode(code) {
        setScannerStatus('Scanning: ' + code + '...', '#f59e0b');
        fetch('/barpos/api/scan.php?code=' + encodeURIComponent(code))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.found) {
                fillItem(data.item);
                setScannerStatus('&#10003; Found: ' + data.item.name, '#10b981');
                document.getElementById('scannerResult').textContent = '';
            } else {
                setScannerStatus('&#9888; Not found: ' + code, '#ef4444');
                setTimeout(function() {
                    setScannerStatus('Scanner ready — scan a barcode to auto-fill item', '#10b981');
                }, 3000);
            }
        })
        .catch(function() {
            setScannerStatus('&#9888; Scanner error', '#ef4444');
        });
    }

    function fillItem(item) {
        document.getElementById('description').value   = item.name;
        document.getElementById('unit_price').value    = item.selling_price;
        document.getElementById('stock_item_id').value = item.id;

        var catSel = document.getElementById('category_id');
        for (var i = 0; i < catSel.options.length; i++) {
            if (catSel.options[i].value == item.cat_id) {
                catSel.selectedIndex = i;
                break;
            }
        }

        var qtyEl = document.getElementById('qty');
        qtyEl.value = 1;
        qtyEl.focus();
        qtyEl.select();
        calcTotal();

        var form = document.getElementById('saleForm');
        form.style.transition = 'background .2s';
        form.style.background = '#d1fae5';
        setTimeout(function() { form.style.background = ''; }, 600);
    }

    function setScannerStatus(msg, color) {
        document.getElementById('scannerStatus').innerHTML = msg;
        document.getElementById('scannerDot').style.background = color;
    }
})();

// ── PAYMENT LINES ─────────────────────────────────────────────
var paymentLineCount = 0;

var METHOD_LABELS = {
    cash:         '&#128181; Cash',
    mobile_money: '&#128241; Mobile Money',
    card:         '&#128179; Card',
    other:        'Other'
};

function addPaymentLine(defaultMethod, defaultAmount) {
    var idx = paymentLineCount++;
    var div = document.createElement('div');
    div.id  = 'pline_' + idx;
    div.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;';

    var options = Object.keys(METHOD_LABELS).map(function(k) {
        var sel = (k === (defaultMethod || 'cash')) ? ' selected' : '';
        return '<option value="' + k + '"' + sel + '>' + METHOD_LABELS[k] + '</option>';
    }).join('');

    var removeBtn = idx > 0
        ? '<button type="button" onclick="removePaymentLine(' + idx + ')" class="btn btn-danger btn-xs" style="flex-shrink:0;">&#10005;</button>'
        : '<span style="width:28px;display:inline-block;"></span>';

    div.innerHTML =
        '<select name="payments[' + idx + '][method]" onchange="onMethodChange(this,' + idx + ')" style="width:160px;">' +
            options +
        '</select>' +
        '<input type="number" name="payments[' + idx + '][amount]" id="pamt_' + idx + '" ' +
            'placeholder="Amount" step="0.01" min="0.01" style="width:140px;" ' +
            'oninput="recalcPayments()" value="' + (defaultAmount || '') + '">' +
        '<input type="text" name="payments[' + idx + '][reference]" id="pref_' + idx + '" ' +
            'placeholder="M-Pesa ref" style="display:none;width:160px;">' +
        removeBtn;

    document.getElementById('paymentLines').appendChild(div);

    if ((defaultMethod || 'cash') === 'mobile_money') {
        document.getElementById('pref_' + idx).style.display = '';
    }

    recalcPayments();
}

function onMethodChange(sel, idx) {
    var ref = document.getElementById('pref_' + idx);
    ref.style.display = sel.value === 'mobile_money' ? '' : 'none';
    if (sel.value !== 'mobile_money') ref.value = '';
}

function removePaymentLine(idx) {
    var el = document.getElementById('pline_' + idx);
    if (el) el.remove();
    recalcPayments();
}

function recalcPayments() {
    var q     = parseFloat(document.getElementById('qty').value || 0);
    var p     = parseFloat(document.getElementById('unit_price').value || 0);
    var total = q * p;
    var paid  = 0;

    document.querySelectorAll('[id^="pamt_"]').forEach(function(inp) {
        paid += parseFloat(inp.value || 0);
    });

    var balance = total - paid;
    var change  = paid > total ? paid - total : 0;

    document.getElementById('saleTotal').textContent =
        total.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('paidSoFar').textContent =
        paid.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('balanceDue').textContent =
        (balance > 0 ? balance : 0).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('total_display').value =
        'TZS ' + total.toLocaleString('en-US', {minimumFractionDigits: 2});

    var changeBox = document.getElementById('changeDueBox');
    if (change > 0) {
        document.getElementById('changeDueAmt').textContent =
            change.toLocaleString('en-US', {minimumFractionDigits: 2});
        changeBox.style.display = '';
    } else {
        changeBox.style.display = 'none';
    }
}

// ── TOTAL CALCULATOR ─────────────────────────────────────────
function calcTotal() {
    var q = parseFloat(document.getElementById('qty').value) || 0;
    var p = parseFloat(document.getElementById('unit_price').value) || 0;
    var t = q * p;

    document.getElementById('total_display').value =
        'TZS ' + t.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    // Auto-fill first payment line if still empty
    var firstAmt = document.getElementById('pamt_0');
    if (firstAmt && (parseFloat(firstAmt.value) || 0) === 0) {
        firstAmt.value = t.toFixed(2);
    }

    recalcPayments();
}

document.getElementById('qty').addEventListener('input', calcTotal);
document.getElementById('unit_price').addEventListener('input', calcTotal);

// Edit form total calculator
var eQ = document.getElementById('eQty');
var eP = document.getElementById('ePrice');
var eT = document.getElementById('eTotal');
if (eQ && eP && eT) {
    function calcEditTotal() {
        var val = (parseFloat(eQ.value) || 0) * (parseFloat(eP.value) || 0);
        eT.value = 'TZS ' + val.toLocaleString('en-US', {minimumFractionDigits: 2});
    }
    eQ.addEventListener('input', calcEditTotal);
    eP.addEventListener('input', calcEditTotal);
}

// ── REPRINT RECEIPT ───────────────────────────────────────────
function reprintReceipt(saleId) {
    fetch('/barpos/api/print_receipt.php?sale_id=' + saleId)
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) {
            alert('Receipt sent to printer.');
        } else {
            alert('Print failed: ' + (d.error || 'unknown error'));
        }
    });
}

initSearch('salesSearch', 'salesTable');

// Init: add first payment line on page load
addPaymentLine('cash', '');
</script>

<?php require_once '../../includes/footer.php'; ?>
