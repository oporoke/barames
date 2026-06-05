<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/validate.php';
requireRole('admin','manager');

$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();

    $name      = trim($_POST['name'] ?? '');
    $cat       = (int)($_POST['category_id'] ?? 0);
    $unit      = trim($_POST['unit'] ?? 'pcs');
    $qty       = $_POST['quantity'] ?? 0;
    $threshold = $_POST['low_stock_threshold'] ?? 5;
    $cost      = $_POST['cost_price'] ?? 0;
    $sell      = $_POST['selling_price'] ?? 0;

    if ($_POST['action'] === 'add') {
        $formErrors = validateStock($conn, $_POST);
        if (empty($formErrors)) {
            $stmt = $conn->prepare("INSERT INTO stock_items (category_id,name,sku,unit,quantity,low_stock_threshold,cost_price,selling_price) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("issdddd", $cat, $name, $unit, $qty, $threshold, $cost, $sell);
            $stmt->execute(); $stmt->close();
            auditLog($conn, 'stock_add', "Added: $name");
            $_SESSION['flash'] = ['msg' => "&#10003; '$name' added to stock.", 'type' => 'success'];
            header("Location: " . $_SERVER['PHP_SELF']); exit;
        }

    } elseif ($_POST['action'] === 'adjust') {
        $adjErrors = validateStockAdjust($conn, $_POST);
        if (empty($adjErrors)) {
            $id   = (int)$_POST['item_id'];
            $type = $_POST['movement_type'];
            $note = trim($_POST['note'] ?? '');
            $today = date('Y-m-d');
            if ($type === 'in')       $conn->query("UPDATE stock_items SET quantity=quantity+$qty WHERE id=$id");
            elseif ($type === 'out')  $conn->query("UPDATE stock_items SET quantity=GREATEST(0, quantity-$qty) WHERE id=$id");
            else                      $conn->query("UPDATE stock_items SET quantity=$qty WHERE id=$id");
            $stmt = $conn->prepare("INSERT INTO stock_movements (stock_item_id,movement_type,quantity,note,moved_at) VALUES (?,?,?,?,?)");
            $stmt->bind_param("isdss", $id, $type, $qty, $note, $today);
            $stmt->execute(); $stmt->close();
            auditLog($conn, 'stock_adjust', "Item $id: $type $qty");
            $_SESSION['flash'] = ['msg' => '&#10003; Stock adjusted.', 'type' => 'success'];
        } else {
            $_SESSION['flash'] = ['msg' => implode(' ', $adjErrors), 'type' => 'error'];
        }
        header("Location: " . $_SERVER['PHP_SELF']); exit;

    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['item_id'];
        $conn->query("UPDATE stock_items SET is_active=0 WHERE id=$id");
        auditLog($conn, 'stock_delete', "Removed item $id");
        $_SESSION['flash'] = ['msg' => 'Item removed from stock.', 'type' => 'success'];
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$filter  = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$where   = "s.is_active=1" . ($filter ? " AND s.category_id=$filter" : "");
$total   = $conn->query("SELECT COUNT(*) AS c FROM stock_items s WHERE $where")->fetch_assoc()['c'];
$pages   = max(1, ceil($total / $perPage));
$items   = $conn->query("SELECT s.*, c.name AS cat, c.type AS cat_type FROM stock_items s JOIN categories c ON s.category_id=c.id WHERE $where ORDER BY c.name, s.name LIMIT $perPage OFFSET $offset");
$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
require_once '../../includes/header.php';
?>
<h1 class="page-title">&#128230; Stock Management</h1>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= $f['msg'] ?></div>
<?php endif; ?>

<div class="card">
  <h3>Add New Stock Item</h3>
  <?= renderErrors($formErrors) ?>
  <form method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-grid">
      <div class="form-group">
        <label>Item Name</label>
        <input type="text" name="name" required maxlength="150"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
               placeholder="e.g. Tusker Lager, Rice 25kg..."
               class="<?= in_array('Item name is required.', $formErrors) ? 'error':'' ?>">
      </div>
      <div class="form-group">
        <label>Category</label>
        <select name="category_id">
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (isset($_POST['category_id']) && $_POST['category_id']==$c['id'])?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Unit</label>
        <select name="unit">
          <?php foreach (['pcs','bottles','crates','kg','ltr','packets','boxes'] as $u): ?>
          <option value="<?= $u ?>" <?= (isset($_POST['unit']) && $_POST['unit']===$u)?'selected':'' ?>><?= $u ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Opening Qty</label>
        <input type="number" name="quantity" step="0.01" min="0" value="<?= htmlspecialchars($_POST['quantity'] ?? '0') ?>">
      </div>
      <div class="form-group">
        <label>Low Stock Alert At</label>
        <input type="number" name="low_stock_threshold" step="0.01" min="0" value="<?= htmlspecialchars($_POST['low_stock_threshold'] ?? '5') ?>">
      </div>
      <div class="form-group">
        <label>Cost Price (TZS)</label>
        <input type="number" name="cost_price" step="0.01" min="0" value="<?= htmlspecialchars($_POST['cost_price'] ?? '0') ?>">
      </div>
      <div class="form-group">
        <label>Selling Price (TZS)</label>
        <input type="number" name="selling_price" step="0.01" min="0" value="<?= htmlspecialchars($_POST['selling_price'] ?? '0') ?>">
        <span class="form-hint" id="marginHint"></span>
      </div>
    </div>
    <br>
    <button type="submit" class="btn btn-primary">
      <span class="btn-text">&#43; Add Item</span><span class="spinner"></span>
    </button>
  </form>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-left">
      <h3 style="margin:0;">Stock Items (<?= $total ?>)</h3>
    </div>
    <div class="toolbar-right">
      <div class="search-bar">
        <span class="search-icon">&#128269;</span>
        <input type="text" id="stockSearch" placeholder="Search items..." style="width:200px;">
      </div>
      <form method="GET" action="" style="display:flex;gap:6px;align-items:center;">
        <select name="cat" onchange="this.form.submit()" style="padding:7px 10px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem;">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <?php if ($items->num_rows === 0): ?>
  <div class="empty-state"><div class="empty-icon">&#128230;</div><p>No stock items found.</p></div>
  <?php else: ?>
  <div class="table-wrap">
  <table id="stockTable">
    <thead>
      <tr><th>Item</th><th>Category</th><th>Unit</th><th>Qty</th><th>Cost</th><th>Sell</th><th>Margin</th><th>Status</th><th>Adjust</th></tr>
    </thead>
    <tbody>
    <?php while ($row = $items->fetch_assoc()):
      $low    = $row['quantity'] <= $row['low_stock_threshold'];
      $margin = $row['selling_price'] > 0
                ? round((($row['selling_price'] - $row['cost_price']) / $row['selling_price']) * 100, 0)
                : 0;
      $mc = $margin >= 30 ? '#10b981' : ($margin >= 10 ? '#f59e0b' : '#ef4444');
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
      <td><span class="badge badge-<?= $row['cat_type'] ?>"><?= htmlspecialchars($row['cat']) ?></span></td>
      <td><?= htmlspecialchars($row['unit']) ?></td>
      <td><strong><?= $row['quantity'] ?></strong></td>
      <td><?= number_format($row['cost_price'], 0) ?></td>
      <td><?= number_format($row['selling_price'], 0) ?></td>
      <td><span style="color:<?= $mc ?>;font-weight:600;"><?= $margin ?>%</span></td>
      <td><?= $low ? '<span class="badge badge-low">LOW</span>' : '<span class="badge badge-ok">OK</span>' ?></td>
      <td>
        <form method="POST" action="" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="adjust">
          <input type="hidden" name="item_id" value="<?= $row['id'] ?>">
          <select name="movement_type" style="padding:5px 8px;border-radius:5px;border:1.5px solid var(--border);font-size:.8rem;">
            <option value="in">In</option>
            <option value="out">Out</option>
            <option value="adjustment">Set</option>
          </select>
          <input type="number" name="quantity" step="0.01" min="0.01" placeholder="Qty" required
                 style="width:65px;padding:5px 8px;border-radius:5px;border:1.5px solid var(--border);font-size:.8rem;">
          <button type="submit" class="btn btn-secondary btn-sm">Save</button>
          <a href="history.php?id=<?= $row['id'] ?>" class="btn btn-secondary btn-xs">History</a>
          <button type="button" class="btn btn-danger btn-xs"
            onclick="showConfirm('Remove Item','Remove <?= addslashes(htmlspecialchars($row['name'])) ?> from stock?', function(){
              var f=document.createElement('form'); f.method='POST';
              var csrf=document.createElement('input'); csrf.name='csrf_token'; csrf.value='<?= csrfToken() ?>'; f.appendChild(csrf);
              var a=document.createElement('input'); a.name='action'; a.value='delete'; f.appendChild(a);
              var i=document.createElement('input'); i.name='item_id'; i.value='<?= $row['id'] ?>'; f.appendChild(i);
              document.body.appendChild(f); f.submit();
            })">&#128465;</button>
        </form>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&cat=<?= $filter ?>">&#8592;</a><?php endif; ?>
    <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
      <<?= $i===$page?'span class="current"':'a href="?page='.$i.'&cat='.$filter.'"' ?>><?= $i ?></<?= $i===$page?'span':'a' ?>>
    <?php endfor; ?>
    <?php if ($page<$pages): ?><a href="?page=<?= $page+1 ?>&cat=<?= $filter ?>">&#8594;</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
initSearch('stockSearch','stockTable');
// Live margin hint
var costEl = document.querySelector('[name=cost_price]');
var sellEl = document.querySelector('[name=selling_price]');
var hint   = document.getElementById('marginHint');
function updateMargin(){
  var c = parseFloat(costEl.value)||0;
  var s = parseFloat(sellEl.value)||0;
  if (s > 0) {
    var m = Math.round(((s-c)/s)*100);
    var col = m>=30?'#10b981':m>=10?'#f59e0b':'#ef4444';
    hint.style.color = col;
    hint.textContent = 'Margin: ' + m + '%' + (s < c ? ' âš  Below cost!' : '');
  } else { hint.textContent = ''; }
}
if(costEl && sellEl){ costEl.addEventListener('input',updateMargin); sellEl.addEventListener('input',updateMargin); }
</script>
<?php require_once '../../includes/footer.php'; ?>
POST['sku'] ?? '');
        $stmt->bind_param("isssdddd", $cat, $name, $sku, $unit, $qty, $threshold, $cost, $sell);
            $stmt->execute(); $stmt->close();
            auditLog($conn, 'stock_add', "Added: $name");
            $_SESSION['flash'] = ['msg' => "&#10003; '$name' added to stock.", 'type' => 'success'];
            header("Location: " . $_SERVER['PHP_SELF']); exit;
        }

    } elseif ($_POST['action'] === 'adjust') {
        $adjErrors = validateStockAdjust($conn, $_POST);
        if (empty($adjErrors)) {
            $id   = (int)$_POST['item_id'];
            $type = $_POST['movement_type'];
            $note = trim($_POST['note'] ?? '');
            $today = date('Y-m-d');
            if ($type === 'in')       $conn->query("UPDATE stock_items SET quantity=quantity+$qty WHERE id=$id");
            elseif ($type === 'out')  $conn->query("UPDATE stock_items SET quantity=GREATEST(0, quantity-$qty) WHERE id=$id");
            else                      $conn->query("UPDATE stock_items SET quantity=$qty WHERE id=$id");
            $stmt = $conn->prepare("INSERT INTO stock_movements (stock_item_id,movement_type,quantity,note,moved_at) VALUES (?,?,?,?,?)");
            $stmt->bind_param("isdss", $id, $type, $qty, $note, $today);
            $stmt->execute(); $stmt->close();
            auditLog($conn, 'stock_adjust', "Item $id: $type $qty");
            $_SESSION['flash'] = ['msg' => '&#10003; Stock adjusted.', 'type' => 'success'];
        } else {
            $_SESSION['flash'] = ['msg' => implode(' ', $adjErrors), 'type' => 'error'];
        }
        header("Location: " . $_SERVER['PHP_SELF']); exit;

    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['item_id'];
        $conn->query("UPDATE stock_items SET is_active=0 WHERE id=$id");
        auditLog($conn, 'stock_delete', "Removed item $id");
        $_SESSION['flash'] = ['msg' => 'Item removed from stock.', 'type' => 'success'];
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }
}

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$filter  = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$where   = "s.is_active=1" . ($filter ? " AND s.category_id=$filter" : "");
$total   = $conn->query("SELECT COUNT(*) AS c FROM stock_items s WHERE $where")->fetch_assoc()['c'];
$pages   = max(1, ceil($total / $perPage));
$items   = $conn->query("SELECT s.*, c.name AS cat, c.type AS cat_type FROM stock_items s JOIN categories c ON s.category_id=c.id WHERE $where ORDER BY c.name, s.name LIMIT $perPage OFFSET $offset");
$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
require_once '../../includes/header.php';
?>
<h1 class="page-title">&#128230; Stock Management</h1>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= $f['msg'] ?></div>
<?php endif; ?>

<div class="card">
  <h3>Add New Stock Item</h3>
  <?= renderErrors($formErrors) ?>
  <form method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="form-grid">
      <div class="form-group">
        <label>Item Name</label>
        <input type="text" name="name" required maxlength="150"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
               placeholder="e.g. Tusker Lager, Rice 25kg..."
               class="<?= in_array('Item name is required.', $formErrors) ? 'error':'' ?>">
      </div>
      <div class="form-group">
        <label>Category</label>
        <select name="category_id">
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (isset($_POST['category_id']) && $_POST['category_id']==$c['id'])?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Unit</label>
        <select name="unit">
          <?php foreach (['pcs','bottles','crates','kg','ltr','packets','boxes'] as $u): ?>
          <option value="<?= $u ?>" <?= (isset($_POST['unit']) && $_POST['unit']===$u)?'selected':'' ?>><?= $u ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Opening Qty</label>
        <input type="number" name="quantity" step="0.01" min="0" value="<?= htmlspecialchars($_POST['quantity'] ?? '0') ?>">
      </div>
      <div class="form-group">
        <label>Low Stock Alert At</label>
        <input type="number" name="low_stock_threshold" step="0.01" min="0" value="<?= htmlspecialchars($_POST['low_stock_threshold'] ?? '5') ?>">
      </div>
      <div class="form-group">
        <label>Cost Price (TZS)</label>
        <input type="number" name="cost_price" step="0.01" min="0" value="<?= htmlspecialchars($_POST['cost_price'] ?? '0') ?>">
      </div>
      <div class="form-group">
        <label>Selling Price (TZS)</label>
        <input type="number" name="selling_price" step="0.01" min="0" value="<?= htmlspecialchars($_POST['selling_price'] ?? '0') ?>">
        <span class="form-hint" id="marginHint"></span>
      </div>
    </div>
    <br>
    <button type="submit" class="btn btn-primary">
      <span class="btn-text">&#43; Add Item</span><span class="spinner"></span>
    </button>
  </form>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-left">
      <h3 style="margin:0;">Stock Items (<?= $total ?>)</h3>
    </div>
    <div class="toolbar-right">
      <div class="search-bar">
        <span class="search-icon">&#128269;</span>
        <input type="text" id="stockSearch" placeholder="Search items..." style="width:200px;">
      </div>
      <form method="GET" action="" style="display:flex;gap:6px;align-items:center;">
        <select name="cat" onchange="this.form.submit()" style="padding:7px 10px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem;">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <?php if ($items->num_rows === 0): ?>
  <div class="empty-state"><div class="empty-icon">&#128230;</div><p>No stock items found.</p></div>
  <?php else: ?>
  <div class="table-wrap">
  <table id="stockTable">
    <thead>
      <tr><th>Item</th><th>Category</th><th>Unit</th><th>Qty</th><th>Cost</th><th>Sell</th><th>Margin</th><th>Status</th><th>Adjust</th></tr>
    </thead>
    <tbody>
    <?php while ($row = $items->fetch_assoc()):
      $low    = $row['quantity'] <= $row['low_stock_threshold'];
      $margin = $row['selling_price'] > 0
                ? round((($row['selling_price'] - $row['cost_price']) / $row['selling_price']) * 100, 0)
                : 0;
      $mc = $margin >= 30 ? '#10b981' : ($margin >= 10 ? '#f59e0b' : '#ef4444');
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
      <td><span class="badge badge-<?= $row['cat_type'] ?>"><?= htmlspecialchars($row['cat']) ?></span></td>
      <td><?= htmlspecialchars($row['unit']) ?></td>
      <td><strong><?= $row['quantity'] ?></strong></td>
      <td><?= number_format($row['cost_price'], 0) ?></td>
      <td><?= number_format($row['selling_price'], 0) ?></td>
      <td><span style="color:<?= $mc ?>;font-weight:600;"><?= $margin ?>%</span></td>
      <td><?= $low ? '<span class="badge badge-low">LOW</span>' : '<span class="badge badge-ok">OK</span>' ?></td>
      <td>
        <form method="POST" action="" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="adjust">
          <input type="hidden" name="item_id" value="<?= $row['id'] ?>">
          <select name="movement_type" style="padding:5px 8px;border-radius:5px;border:1.5px solid var(--border);font-size:.8rem;">
            <option value="in">In</option>
            <option value="out">Out</option>
            <option value="adjustment">Set</option>
          </select>
          <input type="number" name="quantity" step="0.01" min="0.01" placeholder="Qty" required
                 style="width:65px;padding:5px 8px;border-radius:5px;border:1.5px solid var(--border);font-size:.8rem;">
          <button type="submit" class="btn btn-secondary btn-sm">Save</button>
          <a href="history.php?id=<?= $row['id'] ?>" class="btn btn-secondary btn-xs">History</a>
          <button type="button" class="btn btn-danger btn-xs"
            onclick="showConfirm('Remove Item','Remove <?= addslashes(htmlspecialchars($row['name'])) ?> from stock?', function(){
              var f=document.createElement('form'); f.method='POST';
              var csrf=document.createElement('input'); csrf.name='csrf_token'; csrf.value='<?= csrfToken() ?>'; f.appendChild(csrf);
              var a=document.createElement('input'); a.name='action'; a.value='delete'; f.appendChild(a);
              var i=document.createElement('input'); i.name='item_id'; i.value='<?= $row['id'] ?>'; f.appendChild(i);
              document.body.appendChild(f); f.submit();
            })">&#128465;</button>
        </form>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&cat=<?= $filter ?>">&#8592;</a><?php endif; ?>
    <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
      <<?= $i===$page?'span class="current"':'a href="?page='.$i.'&cat='.$filter.'"' ?>><?= $i ?></<?= $i===$page?'span':'a' ?>>
    <?php endfor; ?>
    <?php if ($page<$pages): ?><a href="?page=<?= $page+1 ?>&cat=<?= $filter ?>">&#8594;</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
initSearch('stockSearch','stockTable');
// Live margin hint
var costEl = document.querySelector('[name=cost_price]');
var sellEl = document.querySelector('[name=selling_price]');
var hint   = document.getElementById('marginHint');
function updateMargin(){
  var c = parseFloat(costEl.value)||0;
  var s = parseFloat(sellEl.value)||0;
  if (s > 0) {
    var m = Math.round(((s-c)/s)*100);
    var col = m>=30?'#10b981':m>=10?'#f59e0b':'#ef4444';
    hint.style.color = col;
    hint.textContent = 'Margin: ' + m + '%' + (s < c ? ' âš  Below cost!' : '');
  } else { hint.textContent = ''; }
}
if(costEl && sellEl){ costEl.addEventListener('input',updateMargin); sellEl.addEventListener('input',updateMargin); }
</script>
<?php require_once '../../includes/footer.php'; ?>

