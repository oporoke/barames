<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
requireRole('admin','manager');

$id    = (int)($_GET['id']??0);
$items = $conn->query("SELECT poi.*, si.name AS item_name, si.unit, si.quantity AS current_qty FROM purchase_order_items poi JOIN stock_items si ON poi.stock_item_id=si.id WHERE poi.order_id=$id");
if (!$items || $items->num_rows === 0) { echo '<p>No items found.</p>'; exit; }
?>
<form method="POST" action="index.php">
  <?= csrfField() ?>
  <input type="hidden" name="receive_id" value="<?= $id ?>">
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th>Ordered</th><th>Received</th><th>Remaining</th><th>Current Stock</th><th>Qty to Receive</th></tr></thead>
    <tbody>
    <?php while($row=$items->fetch_assoc()):
      $remaining = $row['quantity_ordered'] - $row['quantity_received'];
    ?>
    <tr>
      <td><?= htmlspecialchars($row['item_name']) ?></td>
      <td><?= $row['quantity_ordered'] ?> <?= $row['unit'] ?></td>
      <td><?= $row['quantity_received'] ?> <?= $row['unit'] ?></td>
      <td><?= $remaining ?> <?= $row['unit'] ?></td>
      <td><?= $row['current_qty'] ?> <?= $row['unit'] ?></td>
      <td><input type="number" name="receive_qty[<?= $row['id'] ?>]" step="0.01" min="0" max="<?= $remaining ?>" value="<?= $remaining ?>" style="width:80px;padding:5px;border-radius:4px;border:1px solid #ccc;"></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div>
  <br>
  <button type="submit" class="btn btn-success">&#10003; Confirm Receipt &amp; Update Stock</button>
</form>
