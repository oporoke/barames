<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/validate.php';
requireRole('admin','manager');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $conn->query("UPDATE suppliers SET is_active=0 WHERE id=$id");
        auditLog($conn,'supplier_delete',"Supplier $id deactivated");
        $_SESSION['flash'] = ['msg'=>'Supplier removed.','type'=>'success'];
        header("Location: ".$_SERVER['PHP_SELF']); exit;
    }

    if (isset($_POST['edit_id'])) {
        $id      = (int)$_POST['edit_id'];
        $name    = sanitize($conn, $_POST['name']??'');
        $contact = sanitize($conn, $_POST['contact_person']??'');
        $phone   = sanitize($conn, $_POST['phone']??'');
        $email   = sanitize($conn, $_POST['email']??'');
        $address = sanitize($conn, $_POST['address']??'');
        $stmt = $conn->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=? WHERE id=?");
        $stmt->bind_param("sssssi",$name,$contact,$phone,$email,$address,$id);
        $stmt->execute(); $stmt->close();
        $_SESSION['flash'] = ['msg'=>'Supplier updated.','type'=>'success'];
        header("Location: ".$_SERVER['PHP_SELF']); exit;
    }

    $name    = sanitize($conn, $_POST['name']??'');
    $contact = sanitize($conn, $_POST['contact_person']??'');
    $phone   = sanitize($conn, $_POST['phone']??'');
    $email   = sanitize($conn, $_POST['email']??'');
    $address = sanitize($conn, $_POST['address']??'');

    if ($name !== '') {
        $stmt = $conn->prepare("INSERT INTO suppliers (name,contact_person,phone,email,address) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss",$name,$contact,$phone,$email,$address);
        $stmt->execute(); $stmt->close();
        auditLog($conn,'supplier_add',"Added supplier: $name");
        $_SESSION['flash'] = ['msg'=>"Supplier '$name' added.",'type'=>'success'];
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

$editRow   = isset($_GET['edit']) ? $conn->query("SELECT * FROM suppliers WHERE id=".(int)$_GET['edit'])->fetch_assoc() : null;
$suppliers = $conn->query("SELECT s.*, COUNT(si.id) AS item_count FROM suppliers s LEFT JOIN stock_items si ON si.supplier_id=s.id AND si.is_active=1 WHERE s.is_active=1 GROUP BY s.id ORDER BY s.name");
require_once '../../includes/header.php';
?>
<h1 class="page-title">&#128666; Suppliers</h1>

<?php if(!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= htmlspecialchars($f['msg']) ?></div>
<?php endif; ?>

<?php if($editRow): ?>
<div class="card" style="border:2px solid var(--warning);">
  <h3 style="color:var(--warning);">&#9998; Edit Supplier</h3>
  <form method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="edit_id" value="<?= $editRow['id'] ?>">
    <div class="form-grid">
      <div class="form-group"><label>Company Name</label><input name="name" value="<?= htmlspecialchars($editRow['name']) ?>" required></div>
      <div class="form-group"><label>Contact Person</label><input name="contact_person" value="<?= htmlspecialchars($editRow['contact_person']??'') ?>"></div>
      <div class="form-group"><label>Phone</label><input name="phone" value="<?= htmlspecialchars($editRow['phone']??'') ?>"></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($editRow['email']??'') ?>"></div>
      <div class="form-group"><label>Address</label><input name="address" value="<?= htmlspecialchars($editRow['address']??'') ?>"></div>
    </div>
    <br><div style="display:flex;gap:8px;">
      <button type="submit" class="btn btn-warning">Update</button>
      <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Add Supplier</h3>
  <form method="POST" action="">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group"><label>Company Name</label><input name="name" required placeholder="e.g. Serengeti Breweries"></div>
      <div class="form-group"><label>Contact Person</label><input name="contact_person" placeholder="e.g. John Mwangi"></div>
      <div class="form-group"><label>Phone</label><input name="phone" placeholder="+255 7xx xxx xxx"></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="supplier@email.com"></div>
      <div class="form-group"><label>Address</label><input name="address" placeholder="City, Region"></div>
    </div>
    <br><button type="submit" class="btn btn-primary">&#43; Add Supplier</button>
  </form>
</div>

<div class="card">
  <h3>All Suppliers (<?= $suppliers->num_rows ?>)</h3>
  <?php if($suppliers->num_rows===0): ?>
  <div class="empty-state"><div class="empty-icon">&#128666;</div><p>No suppliers yet.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th>Stock Items</th><th>Actions</th></tr></thead>
    <tbody>
    <?php while($row=$suppliers->fetch_assoc()): ?>
    <tr>
      <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
      <td><?= htmlspecialchars($row['contact_person']??'—') ?></td>
      <td><?= htmlspecialchars($row['phone']??'—') ?></td>
      <td><?= htmlspecialchars($row['email']??'—') ?></td>
      <td><span class="badge badge-ok"><?= $row['item_count'] ?> items</span></td>
      <td><div style="display:flex;gap:4px;">
        <a href="?edit=<?= $row['id'] ?>" class="btn btn-warning btn-xs">Edit</a>
        <form method="POST" style="display:inline;"><?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
          <button type="button" class="btn btn-danger btn-xs"
            onclick="showConfirm('Remove Supplier','Remove <?= addslashes(htmlspecialchars($row['name'])) ?>?',function(){
              document.querySelectorAll('input[name=delete_id][value=\'<?= $row['id'] ?>\']').forEach(function(i){i.closest('form').submit();});
            })">Remove</button>
        </form>
      </div></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
