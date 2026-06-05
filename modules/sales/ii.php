<?php
/**
 * TABLE MANAGEMENT — Floor Map + Open Tabs
 * File: /barpos/tables/index.php
 */
define('APPDIR', 'barpos');
session_start();

require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

requireLogin();

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'cashier';
$errors   = [];

/* ─────────────────────────────────────────
   HELPERS
───────────────────────────────────────── */
function getOpenTab(mysqli $conn, int $tableId): ?array {
    $stmt = $conn->prepare("
        SELECT st.*, u.name AS cashier_name
        FROM sale_transactions st
        LEFT JOIN users u ON st.cashier_id = u.id
        WHERE st.table_id = ? AND st.tab_status = 'open' AND st.type = 'sale'
        ORDER BY st.created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $tableId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function getTabItems(mysqli $conn, int $txId): array {
    $stmt = $conn->prepare("
        SELECT si.*, c.name AS cat_name
        FROM sale_items si
        JOIN categories c ON si.category_id = c.id
        WHERE si.transaction_id = ?
        ORDER BY si.created_at ASC
    ");
    $stmt->bind_param('i', $txId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function recalcTransaction(mysqli $conn, int $txId): void {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(line_total),0) AS sub
        FROM sale_items WHERE transaction_id = ?
    ");
    $stmt->bind_param('i', $txId);
    $stmt->execute();
    $sub = (float)$stmt->get_result()->fetch_assoc()['sub'];
    $stmt->close();

    // Get tax rate
    $tx = $conn->query("SELECT tax_rate, discount FROM sale_transactions WHERE id=$txId")->fetch_assoc();
    $discount = (float)$tx['discount'];
    $taxRate  = (float)$tx['tax_rate'];
    $taxAmt   = round(($sub - $discount) * ($taxRate / 100), 2);
    $total    = round($sub - $discount + $taxAmt, 2);

    $stmt = $conn->prepare("UPDATE sale_transactions SET subtotal=?, tax_amount=?, total=? WHERE id=?");
    $stmt->bind_param('dddi', $sub, $taxAmt, $total, $txId);
    $stmt->execute();
    $stmt->close();
}

/* ─────────────────────────────────────────
   POST HANDLERS
───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    /* ── ADD TABLE ── */
    if ($_POST['action'] === 'add_table') {
        $name     = trim($_POST['table_name'] ?? '');
        $capacity = max(1, (int)($_POST['capacity'] ?? 2));
        $section  = trim($_POST['section'] ?? '');

        if ($name === '') { $errors[] = 'Table name is required.'; }

        if (!$errors) {
            $stmt = $conn->prepare("INSERT INTO restaurant_tables (name, capacity, section) VALUES (?,?,?)");
            $stmt->bind_param('sis', $name, $capacity, $section);
            $stmt->execute();
            $stmt->close();
            auditLog($conn, 'table_add', "Table '$name' added");
            $_SESSION['flash'] = ['msg' => "&#10003; Table '$name' added.", 'type' => 'success'];
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
    }

    /* ── OPEN TAB ── */
    if ($_POST['action'] === 'open_tab') {
        $tableId  = (int)($_POST['table_id'] ?? 0);
        $shiftId  = $_SESSION['shift_id'] ?? null;
        $saleDate = date('Y-m-d');
        $payMethod = 'cash';

        if ($tableId <= 0) { $errors[] = 'Invalid table.'; }
        if (!$errors && getOpenTab($conn, $tableId)) { $errors[] = 'Table already has an open tab.'; }

        if (!$errors) {
            $stmt = $conn->prepare("
                INSERT INTO sale_transactions
                    (cashier_id, shift_id, type, table_id, tab_status, payment_method, subtotal, discount, tax_rate, tax_amount, total, sale_date, created_at)
                VALUES (?, ?, 'sale', ?, 'open', ?, 0, 0, 0, 0, 0, ?, NOW())
            ");
            $stmt->bind_param('iiiss', $userId, $shiftId, $tableId, $payMethod, $saleDate);
            $stmt->execute();
            $newTxId = $stmt->insert_id;
            $stmt->close();

            auditLog($conn, 'tab_open', "Tab #$newTxId opened on table #$tableId");
            $_SESSION['flash'] = ['msg' => '&#10003; Tab opened.', 'type' => 'success'];
            header("Location: {$_SERVER['PHP_SELF']}?tab=$newTxId"); exit;
        }
    }

    /* ── ADD ITEM TO TAB ── */
    if ($_POST['action'] === 'add_item') {
        $txId    = (int)($_POST['tx_id'] ?? 0);
        $catId   = (int)($_POST['category_id'] ?? 0);
        $stockId = $_POST['stock_item_id'] !== '' ? (int)$_POST['stock_item_id'] : null;
        $desc    = trim($_POST['description'] ?? '');
        $qty     = (float)($_POST['quantity'] ?? 1);
        $price   = (float)($_POST['unit_price'] ?? 0);
        $lineTotal = round($qty * $price, 2);

        if ($txId <= 0 || $desc === '' || $qty <= 0 || $price <= 0) {
            $errors[] = 'All item fields are required.';
        }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("
                    INSERT INTO sale_items (transaction_id, category_id, stock_item_id, description, quantity, unit_price, line_total)
                    VALUES (?,?,?,?,?,?,?)
                ");
                $stmt->bind_param('iiisddd', $txId, $catId, $stockId, $desc, $qty, $price, $lineTotal);
                $stmt->execute();
                $stmt->close();

                recalcTransaction($conn, $txId);
                $conn->commit();

                auditLog($conn, 'tab_add_item', "Added '$desc' x$qty to tab #$txId");
                $_SESSION['flash'] = ['msg' => "&#10003; '$desc' added to tab.", 'type' => 'success'];
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = ['msg' => 'Error: ' . $e->getMessage(), 'type' => 'error'];
            }
            header("Location: {$_SERVER['PHP_SELF']}?tab=$txId"); exit;
        }
    }

    /* ── REMOVE ITEM FROM TAB ── */
    if ($_POST['action'] === 'remove_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $txId   = (int)($_POST['tx_id'] ?? 0);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM sale_items WHERE id=? AND transaction_id=?");
            $stmt->bind_param('ii', $itemId, $txId);
            $stmt->execute();
            $stmt->close();
            recalcTransaction($conn, $txId);
            $conn->commit();
            $_SESSION['flash'] = ['msg' => 'Item removed.', 'type' => 'success'];
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash'] = ['msg' => 'Error: ' . $e->getMessage(), 'type' => 'error'];
        }
        header("Location: {$_SERVER['PHP_SELF']}?tab=$txId"); exit;
    }

    /* ── CLOSE / PAY TAB ── */
    if ($_POST['action'] === 'pay_tab') {
        $txId      = (int)($_POST['tx_id'] ?? 0);
        $payMethod = $_POST['payment_method'] ?? 'cash';
        $discount  = (float)($_POST['discount'] ?? 0);
        $notes     = trim($_POST['notes'] ?? '');

        if ($txId <= 0) { $errors[] = 'Invalid tab.'; }

        if (!$errors) {
            $conn->begin_transaction();
            try {
                // Apply discount & recalc
                $stmt = $conn->prepare("UPDATE sale_transactions SET discount=? WHERE id=?");
                $stmt->bind_param('di', $discount, $txId);
                $stmt->execute();
                $stmt->close();

                recalcTransaction($conn, $txId);

                // Close the tab
                $stmt = $conn->prepare("
                    UPDATE sale_transactions
                    SET tab_status='closed', payment_method=?, note=?
                    WHERE id=?
                ");
                $stmt->bind_param('ssi', $payMethod, $notes, $txId);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                auditLog($conn, 'tab_close', "Tab #$txId closed. Payment: $payMethod");
                $_SESSION['flash'] = ['msg' => "&#10003; Tab #$txId closed & payment recorded.", 'type' => 'success'];
                header('Location: ' . $_SERVER['PHP_SELF']); exit;
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = ['msg' => 'Error: ' . $e->getMessage(), 'type' => 'error'];
            }
        }
    }

    /* ── VOID TAB ── */
    if ($_POST['action'] === 'void_tab') {
        if (!in_array($userRole, ['admin', 'manager'])) {
            $_SESSION['flash'] = ['msg' => 'Permission denied. Manager required.', 'type' => 'error'];
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
        $txId = (int)($_POST['tx_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE sale_transactions SET tab_status='voided', type='void' WHERE id=?");
        $stmt->bind_param('i', $txId);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, 'tab_void', "Tab #$txId voided by user #$userId");
        $_SESSION['flash'] = ['msg' => "Tab #$txId voided.", 'type' => 'warning'];
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    /* ── TOGGLE TABLE ACTIVE ── */
    if ($_POST['action'] === 'toggle_table') {
        $tid = (int)($_POST['table_id'] ?? 0);
        $conn->query("UPDATE restaurant_tables SET is_active = NOT is_active WHERE id=$tid");
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
}

/* ─────────────────────────────────────────
   DATA LOAD
───────────────────────────────────────── */
$viewTabId = isset($_GET['tab']) ? (int)$_GET['tab'] : 0;
$activeTab = null;
$tabItems  = [];

if ($viewTabId) {
    $r = $conn->query("SELECT st.*, rt.name AS table_name, u.name AS cashier_name
        FROM sale_transactions st
        LEFT JOIN restaurant_tables rt ON st.table_id = rt.id
        LEFT JOIN users u ON st.cashier_id = u.id
        WHERE st.id = $viewTabId");
    $activeTab = $r->fetch_assoc();
    $tabItems  = $activeTab ? getTabItems($conn, $viewTabId) : [];
}

$tables = $conn->query("SELECT * FROM restaurant_tables WHERE is_active=1 ORDER BY section, name")->fetch_all(MYSQLI_ASSOC);

// Get open tabs for all tables
$openTabs = [];
if ($tables) {
    $tableIds = implode(',', array_column($tables, 'id') ?: [0]);
    $res = $conn->query("
        SELECT st.table_id, st.id AS tx_id, st.total, st.created_at
        FROM sale_transactions st
        WHERE st.table_id IN ($tableIds) AND st.tab_status='open' AND st.type='sale'
    ");
    while ($row = $res->fetch_assoc()) {
        $openTabs[$row['table_id']] = $row;
    }
}

$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$stockItems = $conn->query("
    SELECT si.id, si.name, si.selling_price, si.quantity, si.unit, c.id AS cat_id
    FROM stock_items si JOIN categories c ON si.category_id=c.id WHERE si.is_active=1 ORDER BY si.name
")->fetch_all(MYSQLI_ASSOC);

// Sections
$sections = array_unique(array_filter(array_column($tables, 'section')));

require_once '../../includes/header.php';
?>
<style>
.floor-map{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:16px;margin-bottom:24px;}
.table-tile{border-radius:12px;padding:18px 12px;text-align:center;cursor:pointer;border:2.5px solid transparent;transition:all .18s;position:relative;min-height:110px;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:6px;}
.table-tile.free{background:#f0fdf4;border-color:#86efac;}
.table-tile.free:hover{background:#dcfce7;border-color:#22c55e;transform:translateY(-2px);}
.table-tile.occupied{background:#fef3c7;border-color:#fcd34d;}
.table-tile.occupied:hover{background:#fef08a;border-color:#f59e0b;transform:translateY(-2px);}
.table-tile .t-name{font-weight:700;font-size:1rem;color:#0f172a;}
.table-tile .t-cap{font-size:.75rem;color:#64748b;}
.table-tile .t-section{font-size:.7rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;}
.table-tile .t-amount{font-size:.8rem;font-weight:700;color:#d97706;margin-top:4px;}
.table-tile .t-time{font-size:.7rem;color:#94a3b8;}
.section-label{font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;margin:16px 0 8px;border-bottom:1px solid #e2e8f0;padding-bottom:4px;}
.tab-panel{background:#fff;border-radius:12px;border:1.5px solid #e2e8f0;overflow:hidden;}
.tab-header{background:#0f172a;color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;}
.tab-header h3{margin:0;font-size:1.1rem;}
.tab-items-table td,.tab-items-table th{padding:10px 14px;font-size:.875rem;}
.tab-items-table tbody tr:hover{background:#f8fafc;}
.tab-totals{background:#f8fafc;padding:16px 20px;border-top:1.5px solid #e2e8f0;}
.tab-totals-row{display:flex;justify-content:space-between;padding:4px 0;font-size:.9rem;}
.tab-totals-row.grand{font-size:1.1rem;font-weight:700;border-top:1.5px solid #e2e8f0;margin-top:8px;padding-top:8px;}
</style>

<h1 class="page-title">&#127960; Table Management</h1>

<?php if(!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= $f['msg'] ?></div>
<?php endif; ?>
<?php if(!empty($errors)): ?>
<div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:<?= $activeTab ? '1fr 420px' : '1fr' ?>;gap:24px;align-items:start;">

<!-- LEFT: FLOOR MAP -->
<div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
      <strong><?= count($tables) ?> Tables</strong> &nbsp;
      <span style="color:#10b981;">&#9679; <?= count($openTabs) ?> Occupied</span> &nbsp;
      <span style="color:#86efac;">&#9679; <?= count($tables)-count($openTabs) ?> Free</span>
    </div>
    <?php if (in_array($userRole,['admin','manager'])): ?>
    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('addTableForm').style.display=document.getElementById('addTableForm').style.display==='none'?'block':'none'">+ Add Table</button>
    <?php endif; ?>
  </div>

  <!-- ADD TABLE FORM -->
  <div id="addTableForm" style="display:none;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:16px;">
    <form method="POST" action="">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_table">
      <div class="form-grid">
        <div class="form-group"><label>Table Name</label><input name="table_name" required placeholder="e.g. Table 1"></div>
        <div class="form-group"><label>Capacity</label><input type="number" name="capacity" min="1" max="20" value="4"></div>
        <div class="form-group"><label>Section (optional)</label><input name="section" placeholder="e.g. Terrace, VIP"></div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Add Table</button>
    </form>
  </div>

  <!-- FLOOR MAP -->
  <?php
  $bySection = [];
  foreach ($tables as $t) {
      $sec = $t['section'] ?: 'Main';
      $bySection[$sec][] = $t;
  }
  foreach ($bySection as $secName => $secTables): ?>
  <div class="section-label">&#128204; <?= htmlspecialchars($secName) ?></div>
  <div class="floor-map">
    <?php foreach($secTables as $t):
        $tab = $openTabs[$t['id']] ?? null;
        $isOccupied = !empty($tab);
        $isActive = isset($_GET['tab']) && $tab && (int)$_GET['tab'] === (int)$tab['tx_id'];
    ?>
    <div class="table-tile <?= $isOccupied?'occupied':'free' ?>"
         style="<?= $isActive?'border-color:#3b82f6;box-shadow:0 0 0 3px #bfdbfe;':'' ?>"
         onclick="<?= $isOccupied ? "window.location='{$_SERVER['PHP_SELF']}?tab={$tab['tx_id']}'" : "openTabModal({$t['id']}, '".addslashes($t['name'])."')" ?>">
      <div style="font-size:1.5rem;"><?= $isOccupied?'&#128176;':'&#11036;' ?></div>
      <div class="t-name"><?= htmlspecialchars($t['name']) ?></div>
      <div class="t-cap">&#128101; <?= $t['capacity'] ?> seats</div>
      <?php if($isOccupied): ?>
      <div class="t-amount">TZS <?= number_format($tab['total'],0) ?></div>
      <div class="t-time"><?= date('H:i', strtotime($tab['created_at'])) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <?php if (empty($tables)): ?>
  <div class="empty-state"><div class="empty-icon">&#127960;</div><p>No tables yet. Add your first table above.</p></div>
  <?php endif; ?>
</div>

<!-- RIGHT: TAB PANEL -->
<?php if ($activeTab): ?>
<div>
  <div class="tab-panel">
    <div class="tab-header">
      <div>
        <h3>&#128196; Tab #<?= $activeTab['id'] ?> — <?= htmlspecialchars($activeTab['table_name'] ?? 'Unknown') ?></h3>
        <small style="opacity:.7;">Opened by <?= htmlspecialchars($activeTab['cashier_name']??'') ?> at <?= date('H:i', strtotime($activeTab['created_at'])) ?></small>
      </div>
      <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary btn-sm">&#x2715;</a>
    </div>

    <!-- ADD ITEM TO TAB -->
    <div style="padding:16px;border-bottom:1.5px solid #e2e8f0;background:#fffbeb;">
      <strong style="font-size:.8rem;color:#d97706;text-transform:uppercase;letter-spacing:.05em;">Add Item</strong>
      <form method="POST" action="" style="margin-top:8px;" id="addItemForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_item">
        <input type="hidden" name="tx_id" value="<?= $activeTab['id'] ?>">
        <input type="hidden" name="stock_item_id" id="tab_stock_id" value="">
        <div style="display:grid;grid-template-columns:1fr 60px 90px;gap:8px;align-items:end;">
          <div>
            <select name="category_id" id="tab_cat" style="margin-bottom:6px;width:100%;">
              <?php foreach($categories as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="description" id="tab_desc" placeholder="Item name..." required style="width:100%;">
          </div>
          <input type="number" name="quantity"   id="tab_qty"   value="1"  min="0.01" step="0.01" required placeholder="Qty">
          <input type="number" name="unit_price" id="tab_price" value=""   min="0.01" step="0.01" required placeholder="Price">
        </div>
        <!-- Quick select from stock -->
        <select id="tab_quick_select" style="margin-top:8px;width:100%;font-size:.8rem;" onchange="tabFillItem(this)">
          <option value="">— Quick pick from stock —</option>
          <?php foreach($stockItems as $si): ?>
          <option value="<?= $si['id'] ?>"
            data-name="<?= htmlspecialchars($si['name']) ?>"
            data-price="<?= $si['selling_price'] ?>"
            data-cat="<?= $si['cat_id'] ?>">
            <?= htmlspecialchars($si['name']) ?> — TZS <?= number_format($si['selling_price'],0) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-success btn-sm" style="width:100%;margin-top:8px;">+ Add to Tab</button>
      </form>
    </div>

    <!-- TAB ITEMS -->
    <?php if (empty($tabItems)): ?>
    <p style="padding:16px;color:#94a3b8;text-align:center;">No items yet — add some above.</p>
    <?php else: ?>
    <table class="tab-items-table" style="width:100%;border-collapse:collapse;">
      <thead><tr style="background:#f8fafc;font-size:.75rem;text-transform:uppercase;color:#64748b;">
        <th style="text-align:left;padding:8px 14px;">Item</th>
        <th>Qty</th>
        <th>Price</th>
        <th>Total</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach($tabItems as $item): ?>
      <tr style="border-bottom:1px solid #f1f5f9;">
        <td><?= htmlspecialchars($item['description']) ?><br><small style="color:#94a3b8;"><?= htmlspecialchars($item['cat_name']) ?></small></td>
        <td style="text-align:center;"><?= $item['quantity']+0 ?></td>
        <td style="text-align:right;"><?= number_format($item['unit_price'],0) ?></td>
        <td style="text-align:right;font-weight:700;"><?= number_format($item['line_total'],0) ?></td>
        <td style="text-align:center;">
          <?php if ($activeTab['tab_status'] === 'open'): ?>
          <form method="POST" action="" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="remove_item">
            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
            <input type="hidden" name="tx_id" value="<?= $activeTab['id'] ?>">
            <button type="button" class="btn btn-danger btn-xs"
              onclick="if(confirm('Remove item?'))this.closest('form').submit()">&#10005;</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- TOTALS -->
    <div class="tab-totals">
      <div class="tab-totals-row"><span>Subtotal</span><span>TZS <?= number_format($activeTab['subtotal'],0) ?></span></div>
      <?php if($activeTab['discount']>0): ?>
      <div class="tab-totals-row" style="color:#ef4444;"><span>Discount</span><span>-TZS <?= number_format($activeTab['discount'],0) ?></span></div>
      <?php endif; ?>
      <?php if($activeTab['tax_rate']>0): ?>
      <div class="tab-totals-row" style="color:#64748b;"><span>Tax (<?= $activeTab['tax_rate'] ?>%)</span><span>TZS <?= number_format($activeTab['tax_amount'],0) ?></span></div>
      <?php endif; ?>
      <div class="tab-totals-row grand"><span>TOTAL</span><span>TZS <?= number_format($activeTab['total'],0) ?></span></div>
    </div>

    <!-- PAYMENT / CLOSE TAB -->
    <?php if ($activeTab['tab_status'] === 'open'): ?>
    <div style="padding:16px;background:#f0fdf4;border-top:1.5px solid #bbf7d0;">
      <strong style="font-size:.8rem;color:#16a34a;text-transform:uppercase;letter-spacing:.05em;">Close & Pay</strong>
      <form method="POST" action="" style="margin-top:8px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="pay_tab">
        <input type="hidden" name="tx_id" value="<?= $activeTab['id'] ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
          <div>
            <label style="font-size:.75rem;color:#64748b;">Payment</label>
            <select name="payment_method" style="width:100%;">
              <option value="cash">&#128181; Cash</option>
              <option value="mobile_money">&#128241; Mobile Money</option>
              <option value="card">&#128179; Card</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div>
            <label style="font-size:.75rem;color:#64748b;">Discount (TZS)</label>
            <input type="number" name="discount" value="0" min="0" step="0.01" style="width:100%;">
          </div>
        </div>
        <input type="text" name="notes" placeholder="Notes (optional)" style="width:100%;margin-bottom:8px;">
        <button type="submit" class="btn btn-success" style="width:100%;" onclick="return confirm('Close tab and record payment?')">
          &#10003; Collect Payment — TZS <?= number_format($activeTab['total'],0) ?>
        </button>
      </form>
      <?php if (in_array($userRole,['admin','manager'])): ?>
      <form method="POST" action="" style="margin-top:6px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="void_tab">
        <input type="hidden" name="tx_id" value="<?= $activeTab['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%;" onclick="return confirm('Void this entire tab? This cannot be undone.')">&#9888; Void Tab</button>
      </form>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="padding:12px 16px;background:#f1f5f9;text-align:center;color:#64748b;font-size:.875rem;">
      Tab is <?= strtoupper($activeTab['tab_status']) ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div>

<!-- OPEN TAB MODAL -->
<div id="openTabModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;padding:32px;min-width:320px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <h3 style="margin:0 0 8px;">&#128196; Open Tab</h3>
    <p id="modalTableName" style="color:#64748b;margin:0 0 20px;"></p>
    <form method="POST" action="">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="open_tab">
      <input type="hidden" name="table_id" id="modalTableId" value="">
      <button type="submit" class="btn btn-success" style="width:100%;font-size:1rem;padding:12px;">&#9654; Open Tab</button>
    </form>
    <button onclick="document.getElementById('openTabModal').style.display='none'" class="btn btn-secondary btn-sm" style="width:100%;margin-top:8px;">Cancel</button>
  </div>
</div>

<script>
var STOCK_ITEMS = <?= json_encode($stockItems) ?>;

function openTabModal(tableId, tableName) {
    document.getElementById('modalTableId').value = tableId;
    document.getElementById('modalTableName').textContent = 'Table: ' + tableName;
    document.getElementById('openTabModal').style.display = 'flex';
}

function tabFillItem(sel) {
    if (!sel.value) return;
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('tab_desc').value  = opt.dataset.name;
    document.getElementById('tab_price').value = opt.dataset.price;
    document.getElementById('tab_stock_id').value = opt.value;
    var catSel = document.getElementById('tab_cat');
    for (var i=0; i<catSel.options.length; i++) {
        if (catSel.options[i].value == opt.dataset.cat) { catSel.selectedIndex=i; break; }
    }
    document.getElementById('tab_qty').focus();
}

document.getElementById('openTabModal').addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});
</script>

<?php require_once '../../includes/footer.php'; ?>