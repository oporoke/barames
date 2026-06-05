<?php
/**
 * Stock Management
 * Roles: admin, manager
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

    // ── ADD ─────────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $formErrors = validateStock($conn, $_POST);

        if (empty($formErrors)) {
            $cat       = (int)($_POST['category_id']      ?? 0);
            $name      = trim($_POST['name']               ?? '');
            $sku       = trim($_POST['sku']                ?? '');
            $unit      = trim($_POST['unit']               ?? 'pcs');
            $qty       = (float)($_POST['quantity']        ?? 0);
            $threshold = (float)($_POST['low_stock_threshold'] ?? 5);
            $cost      = (float)($_POST['cost_price']      ?? 0);
            $sell      = (float)($_POST['selling_price']   ?? 0);

            $stmt = $conn->prepare(
                "INSERT INTO stock_items
                    (category_id, name, sku, unit, quantity, low_stock_threshold, cost_price, selling_price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            // 8 placeholders → 8 type chars → 8 variables  ✓
            $stmt->bind_param("isssdddd", $cat, $name, $sku, $unit, $qty, $threshold, $cost, $sell);
            $stmt->execute();
            $stmt->close();

            auditLog($conn, 'stock_add', "Added: $name");
            $_SESSION['flash'] = ['msg' => "&#10003; '$name' added to stock.", 'type' => 'success'];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

    // ── ADJUST ──────────────────────────────────────────────────────────────
    } elseif ($action === 'adjust') {
        $adjErrors = validateStockAdjust($conn, $_POST);

        if (empty($adjErrors)) {
            $id   = (int)$_POST['item_id'];
            $type = $_POST['movement_type'];          // validated upstream
            $qty  = (float)$_POST['quantity'];
            $note = trim($_POST['note'] ?? '');
            $today = date('Y-m-d');

            // Parameterised quantity updates — no raw vars in SQL
            if ($type === 'in') {
                $stmt = $conn->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE id = ?");
                $stmt->bind_param("di", $qty, $id);
            } elseif ($type === 'out') {
                $stmt = $conn->prepare("UPDATE stock_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
                $stmt->bind_param("di", $qty, $id);
            } else {
                // 'adjustment' → set absolute value
                $stmt = $conn->prepare("UPDATE stock_items SET quantity = ? WHERE id = ?");
                $stmt->bind_param("di", $qty, $id);
            }
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

    // ── DELETE (soft) ────────────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $id = (int)$_POST['item_id'];
        $stmt = $conn->prepare("UPDATE stock_items SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        auditLog($conn, 'stock_delete', "Removed item $id");
        $_SESSION['flash'] = ['msg' => 'Item removed from stock.', 'type' => 'success'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Read — paginated stock list
// ---------------------------------------------------------------------------

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$filter  = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

// Safe: $filter is cast to int above
$where = "s.is_active = 1" . ($filter ? " AND s.category_id = $filter" : "");

$total = (int)$conn->query("SELECT COUNT(*) AS c FROM stock_items s WHERE $where")
                    ->fetch_assoc()['c'];
$pages = max(1, (int)ceil($total / $perPage));

$items = $conn->query(
    "SELECT s.*, c.name AS cat, c.type AS cat_type
       FROM stock_items s
       JOIN categories  c ON s.category_id = c.id
      WHERE $where
      ORDER BY c.name, s.name
      LIMIT $perPage OFFSET $offset"
);

$categories = $conn->query("SELECT * FROM categories ORDER BY name")
                   ->fetch_all(MYSQLI_ASSOC);

require_once '../../includes/header.php';
?>

<h1 class="page-title">&#128230; Stock Management</h1>

<?php if (!empty($_SESSION['flash'])):
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
?>
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
          <?php foreach (['pcs', 'bottles', 'crates', 'kg', 'ltr', 'packets', 'boxes'] as $u): ?>
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
        <input id="f-threshold" type="number" name="low_stock_threshold" step="0.01" min="0"
               value="<?= htmlspecialchars($_POST['low_stock_threshold'] ?? '5') ?>">
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

    </div><!-- /.form-grid -->

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
    <div class="toolbar-right">
      <div class="search-bar">
        <span class="search-icon">&#128269;</span>
        <input type="text" id="stockSearch" placeholder="Search items&hellip;" style="width:200px;">
      </div>
      <form method="GET" action="" style="display:flex;gap:6px;align-items:center;">
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
    </div>
  </div>

  <?php if ($items->num_rows === 0): ?>
  <div class="empty-state">
    <div class="empty-icon">&#128230;</div>
    <p>No stock items found.</p>
  </div>
  <?php else: ?>

  <div class="table-wrap">
    <table id="stockTable">
      <thead>
        <tr>
          <th>Item</th>
          <th>SKU</th>
          <th>Category</th>
          <th>Unit</th>
          <th>Qty</th>
          <th>Cost (TZS)</th>
          <th>Sell (TZS)</th>
          <th>Margin</th>
          <th>Status</th>
          <th>Adjust</th>
        </tr>
      </thead>
      <tbody>
      <?php while ($row = $items->fetch_assoc()):
          $low    = (float)$row['quantity'] <= (float)$row['low_stock_threshold'];
          $sell   = (float)$row['selling_price'];
          $cost   = (float)$row['cost_price'];
          $margin = $sell > 0 ? (int)round((($sell - $cost) / $sell) * 100) : 0;
          $mc     = $margin >= 30 ? '#10b981' : ($margin >= 10 ? '#f59e0b' : '#ef4444');

          // Safe item name for inline JS — escape HTML first, then JS-encode
          $jsName = json_encode(htmlspecialchars($row['name'], ENT_QUOTES));
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
        <td><code><?= htmlspecialchars($row['sku'] ?? '—') ?></code></td>
        <td><span class="badge badge-<?= htmlspecialchars($row['cat_type']) ?>">
          <?= htmlspecialchars($row['cat']) ?>
        </span></td>
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
        <td>
          <form method="POST" action=""
                style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="adjust">
            <input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>">

            <select name="movement_type"
                    style="padding:5px 8px;border-radius:5px;border:1.5px solid var(--border);font-size:.8rem;">
              <option value="in">In</option>
              <option value="out">Out</option>
              <option value="adjustment">Set</option>
            </select>

            <input type="number" name="quantity" step="0.01" min="0.01"
                   placeholder="Qty" required
                   style="width:65px;padding:5px 8px;border-radius:5px;border:1.5px solid var(--border);font-size:.8rem;">

            <input type="text" name="note" placeholder="Note (opt.)"
                   style="width:90px;padding:5px 8px;border-radius:5px;border:1.5px solid var(--border);font-size:.8rem;">

            <button type="submit" class="btn btn-secondary btn-sm">Save</button>

            <a href="history.php?id=<?= (int)$row['id'] ?>"
               class="btn btn-secondary btn-xs">History</a>

            <button type="button" class="btn btn-danger btn-xs"
              data-confirm-title="Remove Item"
              data-confirm-msg="Remove <?= htmlspecialchars($row['name'], ENT_QUOTES) ?> from stock?"
              data-item-id="<?= (int)$row['id'] ?>"
              data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>"
              onclick="confirmDelete(this)">&#128465;</button>
          </form>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page - 1 ?>&cat=<?= $filter ?>">&#8592;</a>
    <?php endif; ?>

    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?page=<?= $i ?>&cat=<?= $filter ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $pages): ?>
      <a href="?page=<?= $page + 1 ?>&cat=<?= $filter ?>">&#8594;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<script>
// ── Search ───────────────────────────────────────────────────────────────────
initSearch('stockSearch', 'stockTable');

// ── Live margin hint (Add form) ───────────────────────────────────────────────
(function () {
    var costEl = document.getElementById('f-cost');
    var sellEl = document.getElementById('f-sell');
    var hint   = document.getElementById('marginHint');
    if (!costEl || !sellEl || !hint) return;

    function updateMargin() {
        var c = parseFloat(costEl.value) || 0;
        var s = parseFloat(sellEl.value) || 0;
        if (s > 0) {
            var m   = Math.round(((s - c) / s) * 100);
            var col = m >= 30 ? '#10b981' : (m >= 10 ? '#f59e0b' : '#ef4444');
            hint.style.color  = col;
            hint.textContent  = 'Margin: ' + m + '%' + (s < c ? '  \u26a0 Below cost!' : '');
        } else {
            hint.textContent = '';
        }
    }

    costEl.addEventListener('input', updateMargin);
    sellEl.addEventListener('input', updateMargin);
}());

// ── Delete confirmation — no inline JS on each row ───────────────────────────
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
            inp.name  = pair[0];
            inp.value = pair[1];
            f.appendChild(inp);
        });

        document.body.appendChild(f);
        f.submit();
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>