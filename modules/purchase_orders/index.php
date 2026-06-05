<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireRole('admin','manager');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Receive stock from a PO
    if (isset($_POST['receive_id'])) {
        $orderId = (int)$_POST['receive_id'];
        $items   = $_POST['receive_qty'] ?? [];
        $allReceived = true;
        foreach ($items as $poiId => $qty) {
            $qty = (float)$qty;
            if ($qty <= 0) { $allReceived = false; continue; }
            $poi = $conn->query("SELECT * FROM purchase_order_items WHERE id=$poiId")->fetch_assoc();
            if (!$poi) continue;
            $remaining = $poi['quantity_ordered'] - $poi['quantity_received'];
            $actual    = min($qty, $remaining);
            if ($actual <= 0) continue;
            $conn->query("UPDATE purchase_order_items SET quantity_received=quantity_received+$actual WHERE id=$poiId");
            $conn->query("UPDATE stock_items SET quantity=quantity+$actual WHERE id=".(int)$poi['stock_item_id']);
            $today = date('Y-m-d');
            $note  = "PO #$orderId received";
            $conn->query("INSERT INTO stock_movements (stock_item_id,movement_type,quantity,note,moved_at) VALUES (".(int)$poi['stock_item_id'].",'in',$actual,'$note','$today')");
            if ($actual < $remaining) $allReceived = false;
        }
        $status = $allReceived ? 'received' : 'partial';
        $conn->query("UPDATE purchase_orders SET status='$status' WHERE id=$orderId");
        auditLog($conn,'po_receive',"PO $orderId marked $status");
        $_SESSION['flash'] = ['msg'=>"Stock received and inventory updated.",'type'=>'success'];
        header("Location: ".$_SERVER['PHP_SELF']); exit;
    }

    // Create new PO
    $supplierId = (int)($_POST['supplier_id']??0);
    $orderDate  = sanitize($conn,$_POST['order_date']??date('Y-m-d'));
    $notes      = sanitize($conn,$_POST['notes']??'');
    $itemIds    = $_POST['item_id']    ?? [];
    $qtys       = $_POST['qty']        ?? [];
    $costs      = $_POST['unit_cost']  ?? [];

    if ($supplierId && !empty($itemIds)) {
        $userId = $_SESSION['user_id'];
        $conn->query("INSERT INTO purchase_orders (supplier_id,order_date,notes,created_by) VALUES ($supplierId,'$orderDate','$notes',$userId)");
        $orderId = $conn->insert_id;
        $total   = 0;
        foreach ($itemIds as $i => $itemId) {
            $itemId = (int)$itemId;
            $qty    = (float)($qtys[$i]??0);
            $cost   = (float)($costs[$i]??0);
            if ($itemId && $qty > 0) {
                $stmt = $conn->prepare("INSERT INTO purchase_order_items (order_id,stock_item_id,quantity_ordered,unit_cost) VALUES (?,?,?,?)");
                $stmt->bind_param("iidd",$orderId,$itemId,$qty,$cost);
                $stmt->execute(); $stmt->close();
                $total += $qty * $cost;
            }
        }
        $conn->query("UPDATE purchase_orders SET total_amount=$total WHERE id=$orderId");
        auditLog($conn,'po_create',"PO $orderId created for supplier $supplierId");
        $_SESSION['flash'] = ['msg'=>"Purchase Order #$orderId created.",'type'=>'success'];
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

$orders    = $conn->query("SELECT po.*, s.name AS supplier FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.id ORDER BY po.order_date DESC, po.id DESC LIMIT 50");
$suppliers = $conn->query("SELECT * FROM suppliers WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$stockItems= $conn->query("SELECT si.*, c.name AS cat FROM stock_items si JOIN categories c ON si.category_id=c.id WHERE si.is_active=1 ORDER BY c.name, si.name")->fetch_all(MYSQLI_ASSOC);
require_once '../../includes/header.php';
?>
<h1 class="page-title">&#128230; Purchase Orders</h1>

<?php if(!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= htmlspecialchars($f['msg']) ?></div>
<?php endif; ?>

<div class="card">
  <h3>Create Purchase Order</h3>
  <form method="POST" action="" id="poForm">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group"><label>Supplier</label>
        <select name="supplier_id" required>
          <option value="">— Select Supplier —</option>
          <?php foreach($suppliers as $s): ?>
          <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Order Date</label>
        <input type="date" name="order_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group"><label>Notes <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
        <input type="text" name="notes" placeholder="e.g. Urgent restock">
      </div>
    </div>

    <div style="margin:16px 0 8px;font-weight:600;font-size:.9rem;">Order Items</div>
    <div id="poItems">
      <div class="po-item" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:end;">
        <div class="form-group" style="margin:0;"><label>Stock Item</label>
          <select name="item_id[]" required>
            <option value="">— Select Item —</option>
            <?php foreach($stockItems as $si): ?>
            <option value="<?= $si['id'] ?>">[<?= htmlspecialchars($si['cat']) ?>] <?= htmlspecialchars($si['name']) ?> (<?= $si['unit'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;"><label>Qty to Order</label><input type="number" name="qty[]" step="0.01" min="0.01" required placeholder="0"></div>
        <div class="form-group" style="margin:0;"><label>Unit Cost (TZS)</label><input type="number" name="unit_cost[]" step="0.01" min="0" placeholder="0.00"></div>
        <div style="padding-bottom:2px;"><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.po-item').remove()">&#8722;</button></div>
      </div>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="addPOItem()">&#43; Add Item</button>
    <br><br>
    <button type="submit" class="btn btn-primary"><span class="btn-text">&#10003; Create Purchase Order</span><span class="spinner"></span></button>
  </form>
</div>

<div class="card">
  <h3>Purchase Orders</h3>
  <?php if($orders->num_rows===0): ?>
  <div class="empty-state"><div class="empty-icon">&#128230;</div><p>No purchase orders yet.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>PO #</th><th>Supplier</th><th>Date</th><th>Total (TZS)</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php
    $statusColors=['pending'=>'#f59e0b','received'=>'#10b981','partial'=>'#3b82f6','cancelled'=>'#ef4444'];
    while($row=$orders->fetch_assoc()):
      $sc = $statusColors[$row['status']]??'#888';
    ?>
    <tr>
      <td><strong>#<?= str_pad($row['id'],4,'0',STR_PAD_LEFT) ?></strong></td>
      <td><?= htmlspecialchars($row['supplier']) ?></td>
      <td><?= date('d M Y',strtotime($row['order_date'])) ?></td>
      <td><?= number_format($row['total_amount'],0) ?></td>
      <td><span class="badge" style="background:<?= $sc ?>22;color:<?= $sc ?>"><?= ucfirst($row['status']) ?></span></td>
      <td>
        <?php if(in_array($row['status'],['pending','partial'])): ?>
        <button type="button" class="btn btn-success btn-xs" onclick="openReceive(<?= $row['id'] ?>)">Receive Stock</button>
        <?php endif; ?>
        <a href="view.php?id=<?= $row['id'] ?>" class="btn btn-secondary btn-xs">View</a>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<!-- Receive Stock Modal -->
<div class="modal-overlay" id="receiveModal">
  <div class="modal" style="max-width:600px;width:100%;">
    <h3>Receive Stock</h3>
    <div id="receiveContent"></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="document.getElementById('receiveModal').classList.remove('active')">Cancel</button>
    </div>
  </div>
</div>

<script>
var stockItems = <?= json_encode(array_map(function($s){ return ['id'=>$s['id'],'name'=>'['.$s['cat'].'] '.$s['name'].' ('.$s['unit'].')']; }, $stockItems)) ?>;

function addPOItem() {
  var opts = stockItems.map(function(s){ return '<option value="'+s.id+'">'+s.name+'</option>'; }).join('');
  var div = document.createElement('div');
  div.className = 'po-item';
  div.style = 'display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:end;';
  div.innerHTML = '<div class="form-group" style="margin:0;"><select name="item_id[]" required><option value="">— Select Item —</option>'+opts+'</select></div>'
    +'<div class="form-group" style="margin:0;"><input type="number" name="qty[]" step="0.01" min="0.01" required placeholder="Qty"></div>'
    +'<div class="form-group" style="margin:0;"><input type="number" name="unit_cost[]" step="0.01" min="0" placeholder="Cost"></div>'
    +'<div><button type="button" class="btn btn-danger btn-sm" onclick="this.closest(\'.po-item\').remove()">&#8722;</button></div>';
  document.getElementById('poItems').appendChild(div);
}

function openReceive(orderId) {
  fetch('get_po_items.php?id='+orderId)
    .then(function(r){ return r.text(); })
    .then(function(html){ 
      document.getElementById('receiveContent').innerHTML = html;
      document.getElementById('receiveModal').classList.add('active');
    });
}
</script>
<?php require_once '../../includes/footer.php'; ?>
