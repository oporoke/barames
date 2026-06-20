<?php
/**
 * Stock Management — Professional Rewrite
 * Roles: admin, manager
 *
 * Improvements:
 *  - Server-side search (name/SKU) via prepared statement
 *  - Fully parameterised COUNT and list queries (no raw vars in SQL)
 *  - Edit modal (name, SKU, category, unit, prices, threshold)
 *  - Unified adjust modal replaces 15 per-row inline forms
 *  - Removed dead $jsName variable
 *  - history link moved outside the adjust <form>
 *  - Bulk soft-delete via checkboxes
 *  - CSV export of current filtered view
 */

define('APPDIR', 'barpos');
session_start();

require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/validate.php';

requireRole('admin', 'manager');

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------

$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $action = $_POST['action'];

    // ── ADD ──────────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $formErrors = validateStock($conn, $_POST);

        if (empty($formErrors)) {
            $cat       = (int)($_POST['category_id']          ?? 0);
            $name      = trim($_POST['name']                   ?? '');
            $sku       = trim($_POST['sku']                    ?? '') ?: null;
            $unit      = trim($_POST['unit']                   ?? 'pcs');
            $qty       = (float)($_POST['quantity']            ?? 0);
            $threshold = (float)($_POST['low_stock_threshold'] ?? 5);
            $cost      = (float)($_POST['cost_price']          ?? 0);
            $sell      = (float)($_POST['selling_price']       ?? 0);

            $stmt = $conn->prepare(
    "INSERT INTO stock_items
        (category_id, name, sku, unit, quantity, low_stock_threshold, cost_price, selling_price)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param("isssdddd", $cat, $name, $sku, $unit, $qty, $threshold, $cost, $sell);
$stmt->execute();
$newItemId = $stmt->insert_id;
$stmt->close();

$histStmt = $conn->prepare(
    "INSERT INTO cost_price_history (stock_item_id, cost_price, changed_by, note)
     VALUES (?, ?, ?, 'Item created')"
);
$userId = $_SESSION['user_id'] ?? null;
$histStmt->bind_param("idi", $newItemId, $cost, $userId);
$histStmt->execute();
$histStmt->close();

auditLog($conn, 'stock_add', "Added: $name");
            $_SESSION['flash'] = ['msg' => "&#10003; '$name' added.", 'type' => 'success'];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

    // ── EDIT ─────────────────────────────────────────────────────────────────
    } elseif ($action === 'edit') {
        $id        = (int)$_POST['item_id'];
        $cat       = (int)($_POST['category_id']          ?? 0);
        $name      = trim($_POST['name']                   ?? '');
        $sku       = trim($_POST['sku']                    ?? '') ?: null;
        $unit      = trim($_POST['unit']                   ?? 'pcs');
        $threshold = (float)($_POST['low_stock_threshold'] ?? 5);
        $cost      = (float)($_POST['cost_price']          ?? 0);
        $sell      = (float)($_POST['selling_price']       ?? 0);

        if ($name === '') {
    $_SESSION['flash'] = ['msg' => 'Item name is required.', 'type' => 'error'];
} else {
    $oldCostStmt = $conn->prepare("SELECT cost_price FROM stock_items WHERE id = ?");
    $oldCostStmt->bind_param("i", $id);
    $oldCostStmt->execute();
    $oldCost = (float)($oldCostStmt->get_result()->fetch_assoc()['cost_price'] ?? 0);
    $oldCostStmt->close();

    $stmt = $conn->prepare(
        "UPDATE stock_items
            SET category_id = ?, name = ?, sku = ?, unit = ?,
                low_stock_threshold = ?, cost_price = ?, selling_price = ?
          WHERE id = ?"
    );
    $stmt->bind_param("isssdddi", $cat, $name, $sku, $unit, $threshold, $cost, $sell, $id);
    $stmt->execute();
    $stmt->close();

    if (round($oldCost, 2) !== round($cost, 2)) {
        $histStmt = $conn->prepare(
            "INSERT INTO cost_price_history (stock_item_id, cost_price, changed_by, note)
             VALUES (?, ?, ?, 'Manual edit')"
        );
        $userId = $_SESSION['user_id'] ?? null;
        $histStmt->bind_param("idi", $id, $cost, $userId);
        $histStmt->execute();
        $histStmt->close();
    }

    auditLog($conn, 'stock_edit', "Edited item $id: $name");
    $_SESSION['flash'] = ['msg' => "&#10003; '$name' updated.", 'type' => 'success'];
}
header("Location: " . $_SERVER['PHP_SELF']);
exit;

    // ── ADJUST ───────────────────────────────────────────────────────────────
    } elseif ($action === 'adjust') {
        $adjErrors = validateStockAdjust($conn, $_POST);

        if (empty($adjErrors)) {
            $id   = (int)$_POST['item_id'];
            $type = $_POST['movement_type'];
            $qty  = (float)$_POST['quantity'];
            $note = trim($_POST['note'] ?? '');
            $today = date('Y-m-d');

            if ($type === 'in') {
                $stmt = $conn->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE id = ?");
            } elseif ($type === 'out') {
                $stmt = $conn->prepare("UPDATE stock_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
            }
            $stmt->bind_param("di", $qty, $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO stock_movements (stock_item_id, movement_type, quantity, note, moved_at)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("isdss", $id, $type, $qty, $note, $today);
            $stmt->execute();
            $stmt->close();

            auditLog($conn, 'stock_adjust', "Item $id: $type $qty");
            $_SESSION['flash'] = ['msg' => '&#10003; Stock adjusted.', 'type' => 'success'];
        } else {
            $_SESSION['flash'] = ['msg' => implode(' ', $adjErrors), 'type' => 'error'];
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    // ── BULK DELETE ───────────────────────────────────────────────────────────
    } elseif ($action === 'bulk_delete') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $stmt = $conn->prepare("UPDATE stock_items SET is_active = 0 WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $stmt->close();
            auditLog($conn, 'stock_bulk_delete', "Removed IDs: " . implode(',', $ids));
            $_SESSION['flash'] = ['msg' => count($ids) . ' item(s) removed.', 'type' => 'success'];
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    // ── DELETE (single soft) ──────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $id = (int)$_POST['item_id'];
        $stmt = $conn->prepare("UPDATE stock_items SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, 'stock_delete', "Removed item $id");
        $_SESSION['flash'] = ['msg' => 'Item removed.', 'type' => 'success'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ---------------------------------------------------------------------------
// CSV export (before any output)
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filter = isset($_GET['cat'])    ? (int)$_GET['cat']           : 0;
    $search = isset($_GET['search']) ? trim($_GET['search'])        : '';

    $sql  = "SELECT s.name, s.sku, c.name AS category, s.unit,
                    s.quantity, s.low_stock_threshold, s.cost_price, s.selling_price
               FROM stock_items s
               JOIN categories  c ON s.category_id = c.id
              WHERE s.is_active = 1";
    $params = [];
    $types  = '';
    if ($filter) { $sql .= " AND s.category_id = ?"; $types .= 'i'; $params[] = $filter; }
    if ($search !== '') {
        $like = "%$search%";
        $sql .= " AND (s.name LIKE ? OR s.sku LIKE ?)";
        $types .= 'ss'; $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY c.name, s.name";

    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name','SKU','Category','Unit','Qty','Low-Stock At','Cost (TZS)','Sell (TZS)','Margin %']);
    while ($r = $res->fetch_assoc()) {
        $s = (float)$r['selling_price']; $c = (float)$r['cost_price'];
        $m = $s > 0 ? round((($s - $c) / $s) * 100) : 0;
        fputcsv($out, [$r['name'], $r['sku'] ?? '', $r['category'], $r['unit'],
                       $r['quantity'], $r['low_stock_threshold'], $c, $s, $m]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Read — paginated + server-side search
// ---------------------------------------------------------------------------
$perPage = 15;
$page    = max(1, (int)($_GET['page']   ?? 1));
$filter  = isset($_GET['cat'])    ? (int)$_GET['cat']    : 0;
$search  = isset($_GET['search']) ? trim($_GET['search']) : '';
$offset  = ($page - 1) * $perPage;

// Build WHERE with full parameterisation
$whereSql  = "s.is_active = 1";
$cntTypes  = '';
$cntParams = [];
if ($filter) {
    $whereSql .= " AND s.category_id = ?";
    $cntTypes  .= 'i'; $cntParams[] = $filter;
}
if ($search !== '') {
    $like = "%$search%";
    $whereSql  .= " AND (s.name LIKE ? OR s.sku LIKE ?)";
    $cntTypes  .= 'ss'; $cntParams[] = $like; $cntParams[] = $like;
}

// COUNT
$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM stock_items s WHERE $whereSql");
if ($cntParams) $cntStmt->bind_param($cntTypes, ...$cntParams);
$cntStmt->execute();
$total = (int)$cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();
$pages = max(1, (int)ceil($total / $perPage));

// LIST — parameterised LIMIT/OFFSET via prepared statement
$listTypes  = $cntTypes . 'ii';
$listParams = array_merge($cntParams, [$perPage, $offset]);
$listStmt   = $conn->prepare(
    "SELECT s.*, c.name AS cat, c.type AS cat_type
       FROM stock_items s
       JOIN categories  c ON s.category_id = c.id
      WHERE $whereSql
      ORDER BY c.name, s.name
      LIMIT ? OFFSET ?"
);
if ($listParams) $listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$items = $listStmt->get_result();
$listStmt->close();

$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

require_once '../../includes/header.php';
?>
<!--------------------------------------------------------------------------
  Inline styles — drop these into your main stylesheet once reviewed
--------------------------------------------------------------------------->
<style>
/* ── Page shell ─────────────────────────────────────────────── */
.stk-page { font-family: 'DM Sans', system-ui, sans-serif; }

/* ── Search bar with clear btn ──────────────────────────────── */
.search-wrap { position:relative; display:inline-flex; }
.search-wrap input { padding-right:2rem; }
.search-wrap .clear-search {
    position:absolute; right:.5rem; top:50%; transform:translateY(-50%);
    background:none; border:none; cursor:pointer; color:var(--muted);
    font-size:1rem; line-height:1; display:none;
}
.search-wrap input:not(:placeholder-shown) + .clear-search { display:block; }

/* ── Checkbox column ────────────────────────────────────────── */
.col-check { width:36px; text-align:center; }
.bulk-bar {
    display:none; align-items:center; gap:.75rem;
    padding:.5rem 1rem; background:var(--primary-light, #eff6ff);
    border-radius:8px; margin-bottom:.75rem; font-size:.85rem;
}
.bulk-bar.visible { display:flex; }

/* ── Search form ─────────────────────────────────────────────── */
#searchForm input[type="text"] {
    padding: 7px 32px 7px 10px;
    border-radius: 6px;
    border: 1.5px solid var(--border);
    font-size: .85rem;
    outline: none;
    transition: border-color .15s;
}
#searchForm input[type="text"]:focus {
    border-color: var(--primary, #2563eb);
}

/* ── Modal backdrop ─────────────────────────────────────────── */
.modal-backdrop {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.45); z-index:1000;
    align-items:center; justify-content:center;
}
.modal-backdrop.open { display:flex; }

/* ── Modal card ─────────────────────────────────────────────── */
.modal-card {
    background:var(--card-bg, #fff); border-radius:14px;
    padding:1.75rem 2rem; width:min(560px, 95vw);
    box-shadow:0 20px 60px rgba(0,0,0,.2);
    animation:modalIn .18s ease;
    max-height:90vh; overflow-y:auto;
}
@keyframes modalIn {
    from { opacity:0; transform:translateY(-12px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.modal-title {
    font-size:1.1rem; font-weight:700; margin:0 0 1.25rem;
    display:flex; align-items:center; justify-content:space-between;
}
.modal-close {
    background:none; border:none; cursor:pointer;
    font-size:1.25rem; color:var(--muted); line-height:1;
}
.modal-card .form-grid { grid-template-columns: 1fr 1fr; }
.modal-footer {
    display:flex; justify-content:flex-end; gap:.5rem; margin-top:1.25rem;
}

/* ── Qty badge in adjust modal ──────────────────────────────── */
.current-qty {
    display:inline-block; background:var(--primary-light,#eff6ff);
    color:var(--primary,#2563eb); border-radius:6px;
    padding:.15rem .55rem; font-size:.82rem; font-weight:600;
}

/* ── Sortable headers ───────────────────────────────────────── */
th[data-sort] { cursor:pointer; user-select:none; white-space:nowrap; }
th[data-sort]:hover { background:var(--table-hover, #f8fafc); }
th[data-sort]::after { content:' ⇅'; opacity:.35; font-size:.75em; }
th[data-sort].asc::after  { content:' ↑'; opacity:1; }
th[data-sort].desc::after { content:' ↓'; opacity:1; }
</style>

<div class="stk-page">
<h1 class="page-title">&#128230; Stock Management</h1>

<?php if (!empty($_SESSION['flash'])):
    $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= $f['msg'] ?></div>
<?php endif; ?>

<!-- =========================================================
     ADD FORM
     ========================================================= -->
<div class="card">
  <h3>Add New Stock Item</h3>
  <?= renderErrors($formErrors) ?>

  <form method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">

    <div class="form-grid">
      <div class="form-group">
        <label for="f-name">Item Name</label>
        <input id="f-name" type="text" name="name" required maxlength="150"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
               placeholder="e.g. Tusker Lager, Rice 25 kg&hellip;"
               class="<?= in_array('Item name is required.', $formErrors) ? 'error' : '' ?>">
      </div>

      <div class="form-group">
        <label for="f-sku">SKU <span class="form-hint">(optional)</span></label>
        <input id="f-sku" type="text" name="sku" maxlength="80"
               value="<?= htmlspecialchars($_POST['sku'] ?? '') ?>"
               placeholder="e.g. BEV-001">
      </div>

      <div class="form-group">
        <label for="f-cat">Category</label>
        <select id="f-cat" name="category_id">
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>"
            <?= (isset($_POST['category_id']) && (int)$_POST['category_id'] === (int)$c['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="f-unit">Unit</label>
        <select id="f-unit" name="unit">
          <?php foreach (['pcs','bottles','crates','kg','ltr','packets','boxes'] as $u): ?>
          <option value="<?= $u ?>"
            <?= (isset($_POST['unit']) && $_POST['unit'] === $u) ? 'selected' : '' ?>>
            <?= $u ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="f-qty">Opening Qty</label>
        <input id="f-qty" type="number" name="quantity" step="0.01" min="0"
               value="<?= htmlspecialchars($_POST['quantity'] ?? '0') ?>">
      </div>

      <div class="form-group">
        <label for="f-threshold">Low Stock Alert At</label>
        <input id="f-threshold" type="number" name="low_stock_threshold" step="1" min="1"
               value="<?= htmlspecialchars($_POST['low_stock_threshold'] ?? '2') ?>">
      </div>

      <div class="form-group">
        <label for="f-cost">Cost Price (TZS)</label>
        <input id="f-cost" type="number" name="cost_price" step="0.01" min="0"
               value="<?= htmlspecialchars($_POST['cost_price'] ?? '0') ?>">
      </div>

      <div class="form-group">
        <label for="f-sell">Selling Price (TZS)</label>
        <input id="f-sell" type="number" name="selling_price" step="0.01" min="0"
               value="<?= htmlspecialchars($_POST['selling_price'] ?? '0') ?>">
        <span class="form-hint" id="marginHint"></span>
      </div>
    </div>

    <br>
    <button type="submit" class="btn btn-primary">
      <span class="btn-text">&#43; Add Item</span><span class="spinner"></span>
    </button>
  </form>
</div>

<!-- =========================================================
     STOCK LIST
     ========================================================= -->
<div class="card">
  <div class="toolbar">
    <div class="toolbar-left">
      <h3 style="margin:0;">Stock Items (<?= $total ?>)</h3>
    </div>
    <div class="toolbar-right" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">

      <!-- Server-side search -->
      <form method="GET" action="" id="searchForm" style="display:flex;gap:6px;align-items:center;">
        <input type="hidden" name="cat"  value="<?= $filter ?>">
        <input type="hidden" name="page" value="1">
        <div class="search-wrap">
          <input type="text" id="stockSearch" name="search"
                 value="<?= htmlspecialchars($search) ?>"
                 placeholder="Search name / SKU&hellip;" style="width:200px;"
                 autocomplete="off">
          <button type="button" class="clear-search" title="Clear">&times;</button>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Go</button>
      </form>

      <!-- Category filter -->
      <form method="GET" action="" style="display:flex;gap:6px;align-items:center;">
        <?php if ($search !== ''): ?>
          <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <?php endif; ?>
        <select name="cat" onchange="this.form.submit()"
                style="padding:7px 10px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem;">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filter === (int)$c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>

      <!-- CSV export -->
      <a href="?export=csv&cat=<?= $filter ?>&search=<?= urlencode($search) ?>"
         class="btn btn-secondary btn-sm" title="Export to CSV">&#8681; CSV</a>
    </div>
  </div>

  <!-- Bulk action bar (shown when rows are checked) -->
  <div class="bulk-bar" id="bulkBar">
    <strong id="bulkCount">0</strong> item(s) selected
    <button class="btn btn-danger btn-sm" onclick="bulkDelete()">&#128465; Remove Selected</button>
    <button class="btn btn-secondary btn-sm" onclick="clearSelection()">Clear</button>
  </div>

  <?php if ($items->num_rows === 0): ?>
  <div class="empty-state">
    <div class="empty-icon">&#128230;</div>
    <p>No stock items found<?= $search !== '' ? " for \"" . htmlspecialchars($search) . "\"" : '' ?>.</p>
  </div>
  <?php else: ?>

  <div class="table-wrap">
    <table id="stockTable">
      <thead>
        <tr>
          <th class="col-check">
            <input type="checkbox" id="checkAll" title="Select all">
          </th>
          <th data-sort="name">Item</th>
          <th data-sort="sku">SKU</th>
          <th data-sort="cat">Category</th>
          <th data-sort="unit">Unit</th>
          <th data-sort="qty" class="desc">Qty</th>
          <th data-sort="cost">Cost (TZS)</th>
          <th data-sort="sell">Sell (TZS)</th>
          <th data-sort="margin">Margin</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php
      // Collect rows for JS (edit modal data) while rendering
      $jsRows = [];
      while ($row = $items->fetch_assoc()):
          $low    = (float)$row['quantity'] <= (float)$row['low_stock_threshold'];
          $sell   = (float)$row['selling_price'];
          $cost   = (float)$row['cost_price'];
          $margin = $sell > 0 ? (int)round((($sell - $cost) / $sell) * 100) : 0;
          $mc     = $margin >= 30 ? '#10b981' : ($margin >= 10 ? '#f59e0b' : '#ef4444');

          $jsRows[] = [
              'id'        => (int)$row['id'],
              'name'      => $row['name'],
              'sku'       => $row['sku'] ?? '',
              'cat_id'    => (int)$row['category_id'],
              'unit'      => $row['unit'],
              'threshold' => (float)$row['low_stock_threshold'],
              'cost'      => $cost,
              'sell'      => $sell,
              'qty'       => (float)$row['quantity'],
          ];
      ?>
      <tr data-id="<?= (int)$row['id'] ?>">
        <td class="col-check">
          <input type="checkbox" class="row-check" value="<?= (int)$row['id'] ?>">
        </td>
        <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
        <td><code><?= htmlspecialchars($row['sku'] ?? '—') ?></code></td>
        <td>
          <span class="badge badge-<?= htmlspecialchars($row['cat_type']) ?>">
            <?= htmlspecialchars($row['cat']) ?>
          </span>
        </td>
        <td><?= htmlspecialchars($row['unit']) ?></td>
        <td><strong><?= htmlspecialchars((string)$row['quantity']) ?></strong></td>
        <td><?= number_format($cost, 0) ?></td>
        <td><?= number_format($sell, 0) ?></td>
        <td><span style="color:<?= $mc ?>;font-weight:600;"><?= $margin ?>%</span></td>
        <td>
          <?= $low
              ? '<span class="badge badge-low">LOW</span>'
              : '<span class="badge badge-ok">OK</span>' ?>
        </td>
        <td style="white-space:nowrap;">
          <!-- Adjust -->
          <button class="btn btn-secondary btn-xs"
                  onclick="openAdjust(<?= (int)$row['id'] ?>,
                                      <?= json_encode($row['name']) ?>,
                                      <?= (float)$row['quantity'] ?>)">
            &#8645; Adjust
          </button>
          <!-- Edit -->
          <button class="btn btn-secondary btn-xs"
                  onclick="openEdit(<?= (int)$row['id'] ?>)">
            &#9998; Edit
          </button>
          <!-- History — outside any form -->
          <a href="history.php?id=<?= (int)$row['id'] ?>"
             class="btn btn-secondary btn-xs">&#128203; History</a>
          <!-- Delete -->
          <button class="btn btn-danger btn-xs"
                  data-confirm-title="Remove Item"
                  data-confirm-msg="Remove <?= htmlspecialchars($row['name'], ENT_QUOTES) ?> from stock?"
                  data-item-id="<?= (int)$row['id'] ?>"
                  data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>"
                  onclick="confirmDelete(this)">&#128465;</button>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page - 1 ?>&cat=<?= $filter ?>&search=<?= urlencode($search) ?>">&#8592;</a>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?page=<?= $i ?>&cat=<?= $filter ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
      <a href="?page=<?= $page + 1 ?>&cat=<?= $filter ?>&search=<?= urlencode($search) ?>">&#8594;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div><!-- /.card -->


<!-- =========================================================
     ADJUST MODAL
     ========================================================= -->
<div class="modal-backdrop" id="adjustModal" role="dialog" aria-modal="true"
     aria-labelledby="adjustTitle">
  <div class="modal-card">
    <div class="modal-title">
      <span id="adjustTitle">Adjust Stock</span>
      <button class="modal-close" onclick="closeModal('adjustModal')"
              aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="">
      <?= csrfField() ?>
      <input type="hidden" name="action"  value="adjust">
      <input type="hidden" name="item_id" id="adj-item-id">

      <p style="margin:0 0 1rem;font-size:.9rem;color:var(--muted);">
        Current qty: <span class="current-qty" id="adj-current-qty">—</span>
      </p>

      <div class="form-grid" style="grid-template-columns:1fr 1fr;">
        <div class="form-group">
          <label for="adj-type">Movement</label>
          <select id="adj-type" name="movement_type">
            <option value="in">Stock In (+)</option>
            <option value="out">Stock Out (−)</option>
            <option value="adjustment">Set Absolute</option>
          </select>
        </div>
        <div class="form-group">
          <label for="adj-qty">Quantity</label>
          <input id="adj-qty" type="number" name="quantity"
                 step="0.01" min="0.01" placeholder="e.g. 24" required>
        </div>
      </div>

      <div class="form-group">
        <label for="adj-note">Note <span class="form-hint">(optional)</span></label>
        <input id="adj-note" type="text" name="note" maxlength="200"
               placeholder="Delivery, wastage, correction…">
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary"
                onclick="closeModal('adjustModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Adjustment</button>
      </div>
    </form>
  </div>
</div>


<!-- =========================================================
     EDIT MODAL
     ========================================================= -->
<div class="modal-backdrop" id="editModal" role="dialog" aria-modal="true"
     aria-labelledby="editTitle">
  <div class="modal-card">
    <div class="modal-title">
      <span id="editTitle">Edit Stock Item</span>
      <button class="modal-close" onclick="closeModal('editModal')"
              aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="">
      <?= csrfField() ?>
      <input type="hidden" name="action"  value="edit">
      <input type="hidden" name="item_id" id="edit-item-id">

      <div class="form-grid" style="grid-template-columns:1fr 1fr;">
        <div class="form-group">
          <label for="edit-name">Item Name</label>
          <input id="edit-name" type="text" name="name"
                 required maxlength="150">
        </div>
        <div class="form-group">
          <label for="edit-sku">SKU <span class="form-hint">(optional)</span></label>
          <input id="edit-sku" type="text" name="sku" maxlength="80">
        </div>
        <div class="form-group">
          <label for="edit-cat">Category</label>
          <select id="edit-cat" name="category_id">
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="edit-unit">Unit</label>
          <select id="edit-unit" name="unit">
            <?php foreach (['pcs','bottles','crates','kg','ltr','packets','boxes'] as $u): ?>
            <option value="<?= $u ?>"><?= $u ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="edit-threshold">Low Stock Alert At</label>
          <input id="edit-threshold" type="number" name="low_stock_threshold"
                 step="0.01" min="0">
        </div>
        <div class="form-group"><!-- spacer --></div>
        <div class="form-group">
          <label for="edit-cost">Cost Price (TZS)</label>
          <input id="edit-cost" type="number" name="cost_price"
                 step="0.01" min="0">
        </div>
        <div class="form-group">
          <label for="edit-sell">Selling Price (TZS)</label>
          <input id="edit-sell" type="number" name="selling_price"
                 step="0.01" min="0">
          <span class="form-hint" id="editMarginHint"></span>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary"
                onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>


<!-- =========================================================
     BULK DELETE FORM (hidden, submitted via JS)
     ========================================================= -->
<form id="bulkForm" method="POST" action="" style="display:none;">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="bulk_delete">
  <div id="bulkIds"></div>
</form>


<script>
  // ── Real-time search (debounced) ──────────────────────────────────────────────
(function () {
    var input = document.getElementById('stockSearch');
    var form  = document.getElementById('searchForm');
    if (!input || !form) return;

    var timer;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            form.submit();
        }, 400); // 400 ms after user stops typing
    });
}());
// ── Row data from PHP ─────────────────────────────────────────────────────────
var STOCK_ROWS = <?= json_encode($jsRows ?? [], JSON_HEX_TAG) ?>;

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
// Close on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === m) closeModal(m.id);
    });
});
// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach(function(m) {
            closeModal(m.id);
        });
    }
});

// ── Adjust modal ──────────────────────────────────────────────────────────────
function openAdjust(id, name, qty) {
    document.getElementById('adj-item-id').value    = id;
    document.getElementById('adj-current-qty').textContent = qty;
    document.getElementById('adjustTitle').textContent = 'Adjust — ' + name;
    document.getElementById('adj-qty').value  = '';
    document.getElementById('adj-note').value = '';
    document.getElementById('adj-type').value = 'in';
    openModal('adjustModal');
    setTimeout(function() { document.getElementById('adj-qty').focus(); }, 60);
}

// ── Edit modal ────────────────────────────────────────────────────────────────
function openEdit(id) {
    var r = STOCK_ROWS.find(function(x) { return x.id === id; });
    if (!r) return;

    document.getElementById('edit-item-id').value   = r.id;
    document.getElementById('edit-name').value      = r.name;
    document.getElementById('edit-sku').value       = r.sku;
    document.getElementById('edit-threshold').value = r.threshold;
    document.getElementById('edit-cost').value      = r.cost;
    document.getElementById('edit-sell').value      = r.sell;

    var catSel = document.getElementById('edit-cat');
    for (var i = 0; i < catSel.options.length; i++) {
        catSel.options[i].selected = parseInt(catSel.options[i].value) === r.cat_id;
    }
    var unitSel = document.getElementById('edit-unit');
    for (var i = 0; i < unitSel.options.length; i++) {
        unitSel.options[i].selected = unitSel.options[i].value === r.unit;
    }

    updateEditMargin();
    openModal('editModal');
    setTimeout(function() { document.getElementById('edit-name').focus(); }, 60);
}

// Live margin hint in edit modal
function updateEditMargin() {
    var c = parseFloat(document.getElementById('edit-cost').value) || 0;
    var s = parseFloat(document.getElementById('edit-sell').value) || 0;
    var hint = document.getElementById('editMarginHint');
    if (s > 0) {
        var m   = Math.round(((s - c) / s) * 100);
        var col = m >= 30 ? '#10b981' : (m >= 10 ? '#f59e0b' : '#ef4444');
        hint.style.color = col;
        hint.textContent = 'Margin: ' + m + '%' + (s < c ? '  ⚠ Below cost!' : '');
    } else { hint.textContent = ''; }
}
document.getElementById('edit-cost').addEventListener('input', updateEditMargin);
document.getElementById('edit-sell').addEventListener('input', updateEditMargin);

// ── Add-form margin hint ──────────────────────────────────────────────────────
(function () {
    var costEl = document.getElementById('f-cost');
    var sellEl = document.getElementById('f-sell');
    var hint   = document.getElementById('marginHint');
    if (!costEl || !sellEl || !hint) return;
    function update() {
        var c = parseFloat(costEl.value) || 0;
        var s = parseFloat(sellEl.value) || 0;
        if (s > 0) {
            var m   = Math.round(((s - c) / s) * 100);
            var col = m >= 30 ? '#10b981' : (m >= 10 ? '#f59e0b' : '#ef4444');
            hint.style.color = col;
            hint.textContent = 'Margin: ' + m + '%' + (s < c ? '  ⚠ Below cost!' : '');
        } else { hint.textContent = ''; }
    }
    costEl.addEventListener('input', update);
    sellEl.addEventListener('input', update);
}());

// ── Delete confirmation ───────────────────────────────────────────────────────
function confirmDelete(btn) {
    var title  = btn.getAttribute('data-confirm-title');
    var msg    = btn.getAttribute('data-confirm-msg');
    var itemId = btn.getAttribute('data-item-id');
    var csrf   = btn.getAttribute('data-csrf');
    showConfirm(title, msg, function () {
        var f = document.createElement('form');
        f.method = 'POST';
        [['csrf_token', csrf], ['action', 'delete'], ['item_id', itemId]].forEach(function (pair) {
            var inp = document.createElement('input');
            inp.name = pair[0]; inp.value = pair[1];
            f.appendChild(inp);
        });
        document.body.appendChild(f);
        f.submit();
    });
}

// ── Bulk select ───────────────────────────────────────────────────────────────
var bulkBar   = document.getElementById('bulkBar');
var bulkCount = document.getElementById('bulkCount');

document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(function(c) {
        c.checked = this.checked;
    }, this);
    updateBulkBar();
});
document.querySelectorAll('.row-check').forEach(function(c) {
    c.addEventListener('change', updateBulkBar);
});
function updateBulkBar() {
    var checked = document.querySelectorAll('.row-check:checked');
    bulkCount.textContent = checked.length;
    bulkBar.classList.toggle('visible', checked.length > 0);
}
function clearSelection() {
    document.querySelectorAll('.row-check, #checkAll').forEach(function(c) { c.checked = false; });
    updateBulkBar();
}
function bulkDelete() {
    var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function(c) { return c.value; });
    if (!ids.length) return;
    showConfirm('Remove Items', 'Remove ' + ids.length + ' selected item(s)?', function() {
        var container = document.getElementById('bulkIds');
        container.innerHTML = '';
        ids.forEach(function(id) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
            container.appendChild(inp);
        });
        document.getElementById('bulkForm').submit();
    });
}

// ── Search clear button ───────────────────────────────────────────────────────
document.querySelector('.clear-search').addEventListener('click', function() {
    var inp = document.getElementById('stockSearch');
    inp.value = '';
    document.getElementById('searchForm').submit();
});

// ── Client-side column sort (current page only) ───────────────────────────────
(function () {
    var table = document.getElementById('stockTable');
    if (!table) return;
    var tbody = table.querySelector('tbody');

    table.querySelectorAll('th[data-sort]').forEach(function(th) {
        th.addEventListener('click', function() {
            var col   = th.getAttribute('data-sort');
            var asc   = !th.classList.contains('asc');
            table.querySelectorAll('th[data-sort]').forEach(function(h) {
                h.classList.remove('asc','desc');
            });
            th.classList.add(asc ? 'asc' : 'desc');

            var colIndex = { name:1, sku:2, cat:3, unit:4, qty:5, cost:6, sell:7, margin:8 };
            var idx = colIndex[col];

            var rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort(function(a, b) {
                var av = a.cells[idx] ? a.cells[idx].textContent.trim() : '';
                var bv = b.cells[idx] ? b.cells[idx].textContent.trim() : '';
                var an = parseFloat(av.replace(/[^0-9.-]/g,''));
                var bn = parseFloat(bv.replace(/[^0-9.-]/g,''));
                if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
                return asc ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            rows.forEach(function(r) { tbody.appendChild(r); });
        });
    });
}());
</script>
</div><!-- /.stk-page -->

<?php require_once '../../includes/footer.php'; ?>