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
       EDIT SALE (single legacy row)
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
            $stmt->bind_param("isdddssi", $cat, $desc, $qty, $price, $amt, $pay, $date, $id);
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
       ADD SALE — MULTI-ITEM CART
    ==========================*/
    $date   = trim($_POST['sale_date'] ?? date('Y-m-d'));
    $userId = $_SESSION['user_id'] ?? null;

    // Parse cart items from POST
    $cartItems = [];
    foreach (($_POST['cart'] ?? []) as $item) {
        $qty   = (float)($item['quantity']  ?? 1);
        $price = (float)($item['unit_price'] ?? 0);
        $cartItems[] = [
            'category_id'   => (int)($item['category_id'] ?? 0),
            'stock_item_id' => ($item['stock_item_id'] ?? '') !== '' ? (int)$item['stock_item_id'] : null,
            'description'   => trim($item['description'] ?? ''),
            'quantity'      => $qty,
            'unit_price'    => $price,
            'line_total'    => round($qty * $price, 2),
        ];
    }

    // Parse payment legs from POST
    $payments = [];
    foreach (($_POST['payments'] ?? []) as $p) {
        $payments[] = [
            'method'    => $p['method']    ?? 'cash',
            'amount'    => (float)($p['amount'] ?? 0),
            'reference' => trim($p['reference'] ?? ''),
        ];
    }

    // Validate
    if (empty($cartItems)) {
        $formErrors[] = 'Cart is empty — add at least one item.';
    }

    $grandTotal = array_sum(array_column($cartItems, 'line_total'));

    if (empty($formErrors)) {
        $formErrors = array_merge($formErrors, PaymentService::validate($payments, $grandTotal));
    }

    if (empty($formErrors)) {

        $conn->begin_transaction();

        try {
            $methodSummary = implode('+', array_unique(array_column($payments, 'method')));

            // 1. Insert sale_transaction header
            $stmt = $conn->prepare("
                INSERT INTO sale_transactions
                    (cashier_id, payment_method, subtotal, total, sale_date)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isdds", $userId, $methodSummary, $grandTotal, $grandTotal, $date);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $txnId = (int)$stmt->insert_id;
            $stmt->close();

            // 2. Insert each item into sale_items + legacy sales
            foreach ($cartItems as $item) {

                // sale_items (primary)
                $siStmt = $conn->prepare("
                    INSERT INTO sale_items
                        (transaction_id, category_id, stock_item_id, description, quantity, unit_price, line_total)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $siStmt->bind_param(
                    "iiisddd",
                    $txnId,
                    $item['category_id'],
                    $item['stock_item_id'],
                    $item['description'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['line_total']
                );
                if (!$siStmt->execute()) throw new Exception($siStmt->error);
                $siStmt->close();

                // Legacy sales row (backward compatibility with reports/stats)
                $lsStmt = $conn->prepare("
                    INSERT INTO sales
                        (category_id, stock_item_id, description,
                         quantity, unit_price, amount, payment_method, sale_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $lsStmt->bind_param(
                    "iisdddss",
                    $item['category_id'],
                    $item['stock_item_id'],
                    $item['description'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['line_total'],
                    $methodSummary,
                    $date
                );
                if (!$lsStmt->execute()) throw new Exception($lsStmt->error);
                $lastSaleId = $lsStmt->insert_id;
                $lsStmt->close();

                // Deduct stock if linked to a stock item
                if ($item['stock_item_id']) {
                    StockService::deduct($conn, $item['stock_item_id'], $item['quantity'], "Txn #$txnId");
                }
            }

            // 3. Record payment legs
            foreach ($payments as $p) {
                PaymentService::addPayment($conn, $txnId, $p['method'], $p['amount'], $p['reference']);
            }

            auditLog(
                $conn,
                'sale_add',
                "Txn #$txnId — " . count($cartItems) . " items — TZS $grandTotal via $methodSummary"
            );

            $conn->commit();

            // One receipt per transaction
           
            printSaleReceipt($conn, $txnId);
            // printSaleReceipt($conn, $lastSaleId).

            $change = PaymentService::changeDue($payments, $grandTotal);
            $itemWord = count($cartItems) === 1 ? 'item' : 'items';
            $msg = '&#10003; Sale recorded (' . count($cartItems) . ' ' . $itemWord . ').';
            if ($change > 0) {
                $msg .= ' Change due: TZS ' . number_format($change, 2);
            }

            $_SESSION['flash'] = ['msg' => $msg, 'type' => 'success'];
            header("Location: " . $_SERVER['PHP_SELF'] . "?date=$date");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $formErrors[] = 'Sale failed: ' . $e->getMessage();
        }
    }
}

/* =========================
   DATA LOAD
==========================*/
$filter  = isset($_GET['date']) ? sanitize($conn, $_GET['date']) : date('Y-m-d');
$editRow = isset($_GET['edit'])
    ? $conn->query("SELECT * FROM sales WHERE id=" . (int)$_GET['edit'])->fetch_assoc()
    : null;

$perPage   = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;
$totalRows = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE sale_date='$filter'")->fetch_assoc()['c'];
$pages     = max(1, ceil($totalRows / $perPage));

$sales = $conn->query("
    SELECT s.*, c.name AS cat, c.type AS cat_type
    FROM sales s
    JOIN categories c ON s.category_id = c.id
    WHERE s.sale_date = '$filter'
    ORDER BY s.id DESC
    LIMIT $perPage OFFSET $offset
");

$totals = $conn->query("
    SELECT c.type, SUM(s.amount) AS total
    FROM sales s
    JOIN categories c ON s.category_id = c.id
    WHERE s.sale_date = '$filter'
    GROUP BY c.type
")->fetch_all(MYSQLI_ASSOC);

$byType = array_column($totals, 'total', 'type');

$payBreak = [];
$pbRes = $conn->query("
    SELECT payment_method, SUM(amount) AS total
    FROM sales
    WHERE sale_date = '$filter'
    GROUP BY payment_method
");
if ($pbRes) {
    while ($r = $pbRes->fetch_assoc()) {
        $payBreak[$r['payment_method']] = $r['total'];
    }
}

$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$stockItems = $conn->query("
    SELECT si.id, si.name, si.sku, si.barcode, si.selling_price,
           si.quantity, si.unit, c.id AS cat_id, c.name AS cat_name, c.type AS cat_type
    FROM stock_items si
    JOIN categories c ON si.category_id = c.id
    WHERE si.is_active = 1
    ORDER BY si.name
")->fetch_all(MYSQLI_ASSOC);

require_once '../../includes/header.php';
?>

<h1 class="page-title">&#128176; Sales Entry</h1>

<?php if (!empty($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert alert-<?= $f['type'] ?>"><?= $f['msg'] ?></div>
<?php endif; ?>

<?php if (!empty($formErrors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $formErrors)) ?></div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════
     EDIT FORM  (single legacy row — unchanged)
═══════════════════════════════════════════════════ -->
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
            <option value="<?= $c['id'] ?>" <?= $c['id'] == $editRow['category_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Description</label>
        <input name="description" value="<?= htmlspecialchars($editRow['description'] ?? '') ?>">
      </div>
      <div class="form-group"><label>Quantity</label>
        <input type="number" name="quantity" step="0.01" min="0.01"
               value="<?= $editRow['quantity'] ?? 1 ?>" id="eQty">
      </div>
      <div class="form-group"><label>Unit Price</label>
        <input type="number" name="unit_price" step="0.01" min="0"
               value="<?= $editRow['unit_price'] ?? $editRow['amount'] ?>" id="ePrice">
      </div>
      <div class="form-group"><label>Total</label>
        <input type="text" id="eTotal" readonly
               style="background:#f8f9fa;font-weight:700;"
               value="TZS <?= number_format($editRow['amount'], 2) ?>">
      </div>
      <div class="form-group"><label>Payment Method</label>
        <select name="payment_method">
          <?php foreach (['cash'=>'Cash','mobile_money'=>'Mobile Money','card'=>'Card','other'=>'Other'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($editRow['payment_method'] ?? 'cash') === $v ? 'selected' : '' ?>>
              <?= $l ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Date</label>
        <input type="date" name="sale_date"
               value="<?= $editRow['sale_date'] ?>"
               max="<?= date('Y-m-d') ?>" required>
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

<!-- ══════════════════════════════════════════════════
     SCANNER STATUS BAR
═══════════════════════════════════════════════════ -->
<div id="scannerBar" style="background:#1a1a2e;color:#fff;padding:10px 16px;border-radius:8px;margin-bottom:12px;display:flex;align-items:center;gap:12px;font-size:.875rem;">
  <span id="scannerDot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#10b981;flex-shrink:0;transition:background .2s;"></span>
  <span id="scannerStatus">Scanner ready — scan a barcode to add to cart</span>
</div>

<!-- ══════════════════════════════════════════════════
     CART + PAYMENT FORM
═══════════════════════════════════════════════════ -->
<form method="POST" action="" id="saleForm">
  <?= csrfField() ?>
  <input type="hidden" name="sale_date" id="sale_date" value="<?= date('Y-m-d') ?>">

  <div style="display:grid;grid-template-columns:1fr 360px;gap:16px;align-items:start;">

    <!-- ── LEFT: search + cart ── -->
    <div class="card" style="padding:1.25rem;">

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <h3 style="margin:0;">&#128722; Cart</h3>
        <div style="display:flex;align-items:center;gap:8px;">
          <label style="font-size:.8rem;color:#6b7280;">Date</label>
          <input type="date" id="saleDatePicker"
                 value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"
                 onchange="document.getElementById('sale_date').value=this.value"
                 style="padding:5px 8px;border-radius:6px;border:1.5px solid var(--border);font-size:.8rem;">
        </div>
      </div>

      <!-- Item search -->
      <div style="position:relative;margin-bottom:12px;">
        <input type="text" id="itemSearch"
               placeholder="&#128269;  Search by name or SKU..."
               autocomplete="off"
               style="width:100%;padding:9px 14px;border-radius:8px;border:1.5px solid var(--border);font-size:.875rem;">
        <div id="searchDropdown"
             style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;
                    background:#fff;border:1.5px solid var(--border);border-radius:8px;
                    z-index:200;max-height:220px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.12);">
        </div>
      </div>

      <!-- Empty state -->
      <div id="cartEmpty" style="text-align:center;padding:48px 20px;color:#9ca3af;">
        <div style="font-size:2.8rem;margin-bottom:8px;">&#128722;</div>
        <p style="font-size:.875rem;line-height:1.6;">Cart is empty.<br>Scan a barcode or search above.</p>
      </div>

      <!-- Cart table -->
      <div id="cartWrap" style="display:none;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
          <thead>
            <tr style="border-bottom:2px solid var(--border);">
              <th style="text-align:left;padding:8px 6px;font-size:.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Item</th>
              <th style="text-align:center;padding:8px 6px;font-size:.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Qty</th>
              <th style="text-align:right;padding:8px 6px;font-size:.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Price</th>
              <th style="text-align:right;padding:8px 6px;font-size:.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Total</th>
              <th style="width:32px;"></th>
            </tr>
          </thead>
          <tbody id="cartBody"></tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--border);">
              <td colspan="3" style="padding:10px 6px;font-weight:700;font-size:.95rem;">Grand Total</td>
              <td style="padding:10px 6px;font-weight:700;font-size:.95rem;text-align:right;color:var(--success);" id="grandTotalDisplay">TZS 0.00</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

    </div><!-- /left -->

    <!-- ── RIGHT: payment + submit ── -->
    <div class="card" style="padding:1.25rem;display:flex;flex-direction:column;gap:14px;">

      <h3 style="margin:0;">&#128179; Payment</h3>

      <div id="paymentLines" style="display:flex;flex-direction:column;gap:8px;"></div>

      <button type="button" class="btn btn-secondary btn-sm"
              onclick="addPaymentLine()" style="align-self:flex-start;">
        + Add Payment Method
      </button>

      <!-- Balance summary -->
      <div style="background:#f8f9fa;border-radius:8px;padding:12px 14px;font-size:.85rem;display:flex;flex-direction:column;gap:7px;">
        <div style="display:flex;justify-content:space-between;">
          <span style="color:#6b7280;">Total</span>
          <strong id="payTotal">TZS 0.00</strong>
        </div>
        <div style="display:flex;justify-content:space-between;">
          <span style="color:#6b7280;">Paid</span>
          <strong style="color:var(--success);" id="payPaid">TZS 0.00</strong>
        </div>
        <div style="border-top:1px solid var(--border);padding-top:7px;display:flex;justify-content:space-between;">
          <span style="color:#6b7280;">Balance</span>
          <strong style="color:var(--danger);" id="payBalance">TZS 0.00</strong>
        </div>
      </div>

      <div id="changeDueBox"
           style="display:none;background:#d1fae5;padding:10px 14px;border-radius:8px;
                  font-weight:700;color:#065f46;font-size:.95rem;">
        &#128181; Change: TZS <span id="changeDueAmt">0.00</span>
      </div>

      <button type="submit" class="btn btn-success" id="submitBtn" disabled>
        <span class="btn-text">&#10003; Save &amp; Print Receipt</span>
        <span class="spinner"></span>
      </button>

      <button type="button" class="btn btn-secondary btn-sm"
              onclick="clearCart()" style="text-align:center;">
        &#128465; Clear Cart
      </button>

    </div><!-- /right -->

  </div><!-- /grid -->
</form>

<!-- ══════════════════════════════════════════════════
     STATS
═══════════════════════════════════════════════════ -->
<div class="stat-grid" style="margin-top:20px;">
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
  <?php if (!empty($payBreak)): ?>
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

<!-- ══════════════════════════════════════════════════
     SALES TABLE
═══════════════════════════════════════════════════ -->
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
            <th>Dept</th><th>Item</th><th>Qty</th><th>Price</th>
            <th>Total</th><th>Payment</th><th>Time</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $payLabels = ['cash'=>'Cash','mobile_money'=>'M-Money','card'=>'Card','other'=>'Other'];
        $payIcons  = ['cash'=>'&#128181;','mobile_money'=>'&#128241;','card'=>'&#128179;','other'=>'&#8226;'];
        while ($row = $sales->fetch_assoc()):
        ?>
          <tr>
            <td><span class="badge badge-<?= $row['cat_type'] ?>"><?= htmlspecialchars($row['cat']) ?></span></td>
            <td><?= htmlspecialchars($row['description'] ?: '—') ?></td>
            <td><?= $row['quantity'] ?? 1 ?></td>
            <td><?= number_format($row['unit_price'] ?? $row['amount'], 0) ?></td>
            <td><strong><?= number_format($row['amount'], 0) ?></strong></td>
            <td><?= ($payIcons[$row['payment_method']] ?? '') . ' ' . ($payLabels[$row['payment_method']] ?? '') ?></td>
            <td><?= date('H:i', strtotime($row['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:4px;">
                <a href="?date=<?= $filter ?>&edit=<?= $row['id'] ?>" class="btn btn-warning btn-xs">Edit</a>
                <a href="receipt.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-secondary btn-xs">&#128196;</a>
                <button type="button" class="btn btn-primary btn-xs"
                        onclick="reprintReceipt(<?= $row['id'] ?>)">&#128424;</button>
                <form method="POST" action="" style="display:inline;">
                  <?= csrfField() ?>
                  <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                  <input type="hidden" name="current_date" value="<?= $filter ?>">
                  <button type="button" class="btn btn-danger btn-xs"
                    onclick="showConfirm('Delete Sale','Delete TZS <?= number_format($row['amount'], 0) ?>?',function(){
                      document.querySelectorAll('input[name=delete_id][value=\'<?= $row['id'] ?>\']')
                        .forEach(function(i){i.closest('form').submit();});
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
        <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
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

<!-- ══════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════ -->
<script>
var STOCK_ITEMS = <?= json_encode($stockItems) ?>;

/* ─────────────────────────────────────────────────
   CART STATE
───────────────────────────────────────────────── */
var cart = [];
// Each entry: { id, name, cat_name, cat_type, cat_id, price, qty, stock_item_id }

function cartTotal() {
    return cart.reduce(function(s, i) { return s + i.qty * i.price; }, 0);
}

function addToCart(item) {
    var existing = cart.find(function(c) { return c.id === item.id; });
    if (existing) {
        existing.qty = Math.round((existing.qty + 1) * 1000) / 1000;
    } else {
        cart.push({
            id:            item.id,
            name:          item.name,
            cat_name:      item.cat_name,
            cat_type:      item.cat_type,
            cat_id:        item.cat_id,
            price:         parseFloat(item.selling_price),
            qty:           1,
            stock_item_id: item.id
        });
    }
    renderCart();
    flashCartWrap();
}

function removeFromCart(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function changeQty(idx, delta) {
    var newQty = Math.round((cart[idx].qty + delta) * 1000) / 1000;
    if (newQty <= 0) { removeFromCart(idx); return; }
    cart[idx].qty = newQty;
    renderCart();
}

function setQty(idx, val) {
    var v = parseFloat(val) || 0;
    if (v <= 0) { removeFromCart(idx); return; }
    cart[idx].qty = v;
    renderCart();
}

function clearCart() {
    cart = [];
    renderCart();
}

/* ─────────────────────────────────────────────────
   RENDER CART
───────────────────────────────────────────────── */
function fmt(n) {
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderCart() {
    var empty = document.getElementById('cartEmpty');
    var wrap  = document.getElementById('cartWrap');
    var body  = document.getElementById('cartBody');
    var form  = document.getElementById('saleForm');

    // Remove previous hidden cart inputs
    form.querySelectorAll('input[name^="cart["]').forEach(function(el) { el.remove(); });

    if (cart.length === 0) {
        empty.style.display = '';
        wrap.style.display  = 'none';
        document.getElementById('grandTotalDisplay').textContent = 'TZS 0.00';
        recalcPayments();
        return;
    }

    empty.style.display = 'none';
    wrap.style.display  = '';
    body.innerHTML = '';

    cart.forEach(function(item, idx) {
        var lineTotal = item.qty * item.price;
        var tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid var(--border)';
        tr.innerHTML =
            '<td style="padding:8px 6px;">' +
                '<div style="font-weight:600;font-size:.875rem;line-height:1.3;">' + escH(item.name) + '</div>' +
                '<span class="badge badge-' + item.cat_type + '" style="font-size:.7rem;margin-top:2px;">' + escH(item.cat_name) + '</span>' +
            '</td>' +
            '<td style="padding:8px 4px;">' +
                '<div style="display:flex;align-items:center;gap:3px;justify-content:center;">' +
                    '<button type="button" class="btn btn-secondary btn-xs" ' +
                            'onclick="changeQty(' + idx + ',-1)" ' +
                            'style="width:24px;height:24px;padding:0;line-height:1;flex-shrink:0;">&#8722;</button>' +
                    '<input type="number" value="' + item.qty + '" min="0.01" step="0.01" ' +
                           'onchange="setQty(' + idx + ',this.value)" ' +
                           'style="width:50px;text-align:center;padding:3px 4px;border-radius:6px;' +
                                  'border:1.5px solid var(--border);font-size:.85rem;">' +
                    '<button type="button" class="btn btn-secondary btn-xs" ' +
                            'onclick="changeQty(' + idx + ',1)" ' +
                            'style="width:24px;height:24px;padding:0;line-height:1;flex-shrink:0;">+</button>' +
                '</div>' +
            '</td>' +
            '<td style="padding:8px 6px;text-align:right;font-size:.85rem;white-space:nowrap;">' + fmt(item.price) + '</td>' +
            '<td style="padding:8px 6px;text-align:right;font-weight:700;font-size:.875rem;white-space:nowrap;">' + fmt(lineTotal) + '</td>' +
            '<td style="padding:8px 4px;text-align:center;">' +
                '<button type="button" onclick="removeFromCart(' + idx + ')" ' +
                        'style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:1rem;' +
                               'padding:3px 5px;border-radius:4px;" title="Remove item">' +
                    '&#128465;' +
                '</button>' +
            '</td>';
        body.appendChild(tr);

        // Inject hidden inputs so the cart submits with the form
        var fields = {
            'category_id':   item.cat_id,
            'stock_item_id': item.stock_item_id,
            'description':   item.name,
            'quantity':      item.qty,
            'unit_price':    item.price,
            'line_total':    lineTotal.toFixed(2)
        };
        Object.keys(fields).forEach(function(k) {
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'cart[' + idx + '][' + k + ']';
            inp.value = fields[k];
            form.appendChild(inp);
        });
    });

    var total = cartTotal();
    document.getElementById('grandTotalDisplay').textContent = 'TZS ' + fmt(total);

    // Auto-fill first payment line if still untouched
    var firstPay = document.getElementById('pamt_0');
    if (firstPay && (parseFloat(firstPay.value) || 0) === 0) {
        firstPay.value = total.toFixed(2);
    }

    recalcPayments();
}

/* ─────────────────────────────────────────────────
   PAYMENT LINES
───────────────────────────────────────────────── */
var payLineCount = 0;

function addPaymentLine(defaultMethod, defaultAmount) {
    var idx = payLineCount++;
    var div = document.createElement('div');
    div.id  = 'pline_' + idx;
    div.style.cssText = 'display:flex;gap:6px;align-items:center;flex-wrap:wrap;';

    var methods = {
        cash:         '&#128181; Cash',
        mobile_money: '&#128241; Mobile Money',
        card:         '&#128179; Card',
        other:        'Other'
    };
    var options = Object.keys(methods).map(function(k) {
        return '<option value="' + k + '"' + (k === (defaultMethod || 'cash') ? ' selected' : '') + '>' + methods[k] + '</option>';
    }).join('');

    var removeHtml = idx > 0
        ? '<button type="button" onclick="removePaymentLine(' + idx + ')" class="btn btn-danger btn-xs" style="flex-shrink:0;">&#10005;</button>'
        : '<span style="width:28px;display:inline-block;"></span>';

    div.innerHTML =
        '<select name="payments[' + idx + '][method]" onchange="onMethodChange(this,' + idx + ')" ' +
                'style="width:152px;padding:6px 8px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem;">' +
            options +
        '</select>' +
        '<input type="number" name="payments[' + idx + '][amount]" id="pamt_' + idx + '" ' +
               'placeholder="Amount" step="0.01" min="0.01" oninput="recalcPayments()" ' +
               'value="' + (defaultAmount || '') + '" ' +
               'style="flex:1;min-width:90px;padding:6px 8px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem;">' +
        '<input type="text" name="payments[' + idx + '][reference]" id="pref_' + idx + '" ' +
               'placeholder="M-Pesa ref" ' +
               'style="display:none;flex:1;min-width:110px;padding:6px 8px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem;">' +
        removeHtml;

    document.getElementById('paymentLines').appendChild(div);
    if ((defaultMethod || '') === 'mobile_money') {
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
    var total = cartTotal();
    var paid  = 0;

    document.querySelectorAll('[id^="pamt_"]').forEach(function(inp) {
        paid += parseFloat(inp.value || 0);
    });

    var balance = total - paid;
    var change  = paid > total ? paid - total : 0;

    document.getElementById('payTotal').textContent   = 'TZS ' + fmt(total);
    document.getElementById('payPaid').textContent    = 'TZS ' + fmt(paid);
    document.getElementById('payBalance').textContent = 'TZS ' + fmt(balance > 0 ? balance : 0);
    document.getElementById('payBalance').style.color = balance > 0.01 ? 'var(--danger)' : 'var(--success)';

    var cb = document.getElementById('changeDueBox');
    if (change > 0.01) {
        document.getElementById('changeDueAmt').textContent = fmt(change);
        cb.style.display = '';
    } else {
        cb.style.display = 'none';
    }

    document.getElementById('submitBtn').disabled = !(cart.length > 0 && total > 0 && balance <= 0.01);
}

/* ─────────────────────────────────────────────────
   ITEM SEARCH
───────────────────────────────────────────────── */
var searchInput = document.getElementById('itemSearch');
var dropdown    = document.getElementById('searchDropdown');

searchInput.addEventListener('input', function() {
    var q = this.value.toLowerCase().trim();
    dropdown.innerHTML = '';
    if (!q) { dropdown.style.display = 'none'; return; }

    var hits = STOCK_ITEMS.filter(function(i) {
        return i.name.toLowerCase().includes(q) ||
               (i.sku && i.sku.toLowerCase().includes(q));
    }).slice(0, 10);

    if (hits.length === 0) { dropdown.style.display = 'none'; return; }

    hits.forEach(function(item) {
        var div = document.createElement('div');
        div.style.cssText = 'padding:10px 14px;cursor:pointer;display:flex;justify-content:space-between;' +
                            'align-items:center;gap:8px;border-bottom:1px solid #f3f4f6;';
        div.innerHTML =
            '<div>' +
                '<div style="font-weight:600;font-size:.875rem;">' + escH(item.name) + '</div>' +
                '<div style="font-size:.75rem;color:#9ca3af;">' +
                    escH(item.sku || '') + ' &middot; ' + item.quantity + ' in stock' +
                '</div>' +
            '</div>' +
            '<div style="font-weight:700;font-size:.875rem;color:var(--success);white-space:nowrap;">' +
                'TZS ' + fmt(parseFloat(item.selling_price)) +
            '</div>';
        div.addEventListener('mouseenter', function() { this.style.background = '#f9fafb'; });
        div.addEventListener('mouseleave', function() { this.style.background = ''; });
        div.addEventListener('click', function() {
            addToCart(item);
            searchInput.value      = '';
            dropdown.style.display = 'none';
            setScannerStatus('Added: ' + item.name, '#10b981');
        });
        dropdown.appendChild(div);
    });
    dropdown.style.display = '';
});

document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

/* ─────────────────────────────────────────────────
   BARCODE SCANNER
───────────────────────────────────────────────── */
(function() {
    var buffer = '', timer = null, TIMEOUT = 80;

    document.addEventListener('keydown', function(e) {
        var active = document.activeElement;
        // Let the user type normally in the search box and other inputs
        if (active && active.id === 'itemSearch') return;
        if (active && active.tagName === 'INPUT' && active.type !== 'hidden') {
            if (e.key !== 'Enter') return;
        }

        if (e.key === 'Enter') {
            if (buffer.length >= 3) lookupBarcode(buffer.trim());
            buffer = '';
            clearTimeout(timer);
            return;
        }

        if (e.key.length === 1) {
            buffer += e.key;
            clearTimeout(timer);
            timer = setTimeout(function() {
                if (buffer.length >= 4 && !/\s/.test(buffer)) lookupBarcode(buffer.trim());
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
                addToCart(data.item);
                setScannerStatus('&#10003; Added: ' + data.item.name, '#10b981');
                setTimeout(function() {
                    setScannerStatus('Scanner ready — scan a barcode to add to cart', '#10b981');
                }, 2500);
            } else {
                setScannerStatus('&#9888; Not found: ' + code, '#ef4444');
                setTimeout(function() {
                    setScannerStatus('Scanner ready — scan a barcode to add to cart', '#10b981');
                }, 3000);
            }
        })
        .catch(function() {
            setScannerStatus('&#9888; Scanner error', '#ef4444');
        });
    }
})();

function setScannerStatus(msg, color) {
    document.getElementById('scannerStatus').innerHTML = msg;
    document.getElementById('scannerDot').style.background = color;
}

/* ─────────────────────────────────────────────────
   REPRINT
───────────────────────────────────────────────── */
function reprintReceipt(saleId) {
    fetch('/barpos/api/print_receipt.php?sale_id=' + saleId)
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) alert('Receipt sent to printer.');
        else alert('Print failed: ' + (d.error || 'unknown error'));
    });
}

/* ─────────────────────────────────────────────────
   EDIT FORM TOTAL
───────────────────────────────────────────────── */
var eQ = document.getElementById('eQty');
var eP = document.getElementById('ePrice');
var eT = document.getElementById('eTotal');
if (eQ && eP && eT) {
    function calcEditTotal() {
        var val = (parseFloat(eQ.value) || 0) * (parseFloat(eP.value) || 0);
        eT.value = 'TZS ' + val.toLocaleString('en-US', { minimumFractionDigits: 2 });
    }
    eQ.addEventListener('input', calcEditTotal);
    eP.addEventListener('input', calcEditTotal);
}

/* ─────────────────────────────────────────────────
   UTILS
───────────────────────────────────────────────── */
function escH(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(s)));
    return d.innerHTML;
}

function flashCartWrap() {
    var wrap = document.getElementById('cartWrap');
    if (!wrap) return;
    wrap.style.transition = 'background .15s';
    wrap.style.background = '#d1fae5';
    setTimeout(function() { wrap.style.background = ''; }, 400);
}

initSearch('salesSearch', 'salesTable');

/* ─────────────────────────────────────────────────
   INIT
───────────────────────────────────────────────── */
addPaymentLine('cash', '');
</script>

<?php require_once '../../includes/footer.php'; ?>
