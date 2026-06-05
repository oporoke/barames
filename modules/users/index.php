<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if ($id !== (int)$_SESSION['user_id']) {
            $conn->query("UPDATE users SET is_active=0 WHERE id=$id");
            auditLog($conn, 'user_deactivated', "User ID $id deactivated");
            $_SESSION['flash'] = ['msg' => 'User deactivated.', 'type' => 'success'];
        } else {
            $_SESSION['flash'] = ['msg' => 'You cannot deactivate yourself.', 'type' => 'error'];
        }
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    if (isset($_POST['edit_id'])) {
        $id   = (int)$_POST['edit_id'];
        $name = sanitize($conn, $_POST['name']);
        $role = sanitize($conn, $_POST['role']);
        $stmt = $conn->prepare("UPDATE users SET name=?, role=? WHERE id=?");
        $stmt->bind_param("ssi", $name, $role, $id);
        $stmt->execute(); $stmt->close();
        if (!empty($_POST['new_password'])) {
            $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT, ['cost'=>12]);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param("si", $hash, $id);
            $stmt->execute(); $stmt->close();
        }
        auditLog($conn, 'user_updated', "User ID $id updated");
        $_SESSION['flash'] = ['msg' => 'User updated.', 'type' => 'success'];
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    // Add user
    $name  = sanitize($conn, $_POST['name'] ?? '');
    $uname = sanitize($conn, $_POST['username'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $role  = sanitize($conn, $_POST['role'] ?? 'cashier');

    if ($name && $uname && strlen($pass) >= 6) {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
        $stmt = $conn->prepare("INSERT INTO users (name, username, password, role) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $name, $uname, $hash, $role);
        if ($stmt->execute()) {
            auditLog($conn, 'user_created', "New user: $uname ($role)");
            $_SESSION['flash'] = ['msg' => "User '$uname' created.", 'type' => 'success'];
        } else {
            $_SESSION['flash'] = ['msg' => 'Username already exists.', 'type' => 'error'];
        }
        $stmt->close();
    } else {
        $_SESSION['flash'] = ['msg' => 'All fields required. Password min 6 characters.', 'type' => 'error'];
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

$users   = $conn->query("SELECT * FROM users ORDER BY role, name");
$editRow = isset($_GET['edit']) ? $conn->query("SELECT * FROM users WHERE id=" . (int)$_GET['edit'])->fetch_assoc() : null;
require_once '../../includes/header.php';
?>
<h1 class="page-title">&#128100; User Management</h1>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= htmlspecialchars($f['msg']) ?></div>
<?php endif; ?>

<?php if ($editRow): ?>
<div class="card" style="border:2px solid var(--warning);">
  <h3 style="color:var(--warning);">&#9998; Edit User: <?= htmlspecialchars($editRow['username']) ?></h3>
  <form method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="edit_id" value="<?= $editRow['id'] ?>">
    <div class="form-grid">
      <div class="form-group"><label>Full Name</label><input name="name" value="<?= htmlspecialchars($editRow['name']) ?>" required></div>
      <div class="form-group">
        <label>Role</label>
        <select name="role">
          <?php foreach (['admin','manager','cashier'] as $r): ?>
          <option value="<?= $r ?>" <?= $editRow['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>New Password <span style="color:var(--muted);font-weight:400;">(leave blank to keep)</span></label><input type="password" name="new_password" placeholder="Min 6 characters"></div>
    </div>
    <br>
    <div style="display:flex;gap:8px;">
      <button type="submit" class="btn btn-warning">Update User</button>
      <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Add New User</h3>
  <form method="POST" action="">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group"><label>Full Name</label><input name="name" required placeholder="e.g. John Doe"></div>
      <div class="form-group"><label>Username</label><input name="username" required placeholder="e.g. john_cashier"></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" required placeholder="Min 6 characters"></div>
      <div class="form-group">
        <label>Role</label>
        <select name="role">
          <option value="cashier">&#128179; Cashier — Sales & Expenses only</option>
          <option value="manager">&#128203; Manager — + Stock & Reports</option>
          <option value="admin">&#9733; Admin — Full Access</option>
        </select>
      </div>
    </div>
    <br>
    <button type="submit" class="btn btn-primary">&#43; Add User</button>
  </form>
</div>

<div class="card">
  <h3>All Users (<?= $users->num_rows ?>)</h3>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php while ($row = $users->fetch_assoc()):
      $roleColors = ['admin'=>'#4f46e5','manager'=>'#f59e0b','cashier'=>'#10b981'];
      $roleColor  = $roleColors[$row['role']] ?? '#888';
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
      <td><?= htmlspecialchars($row['username']) ?></td>
      <td><span class="badge" style="background:<?= $roleColor ?>22;color:<?= $roleColor ?>"><?= ucfirst($row['role']) ?></span></td>
      <td><?= $row['last_login'] ? date('d M Y H:i', strtotime($row['last_login'])) : 'Never' ?></td>
      <td><?= $row['is_active'] ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-low">Inactive</span>' ?></td>
      <td>
        <div style="display:flex;gap:4px;">
          <a href="?edit=<?= $row['id'] ?>" class="btn btn-warning btn-xs">Edit</a>
          <?php if ($row['id'] !== (int)$_SESSION['user_id']): ?>
          <form method="POST" action="" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
            <button type="button" class="btn btn-danger btn-xs"
              onclick="showConfirm('Deactivate User','Deactivate <?= addslashes(htmlspecialchars($row['name'])) ?>?', function(){
                document.querySelectorAll('input[name=delete_id][value=\'<?= $row['id'] ?>\']').forEach(function(i){ i.closest('form').submit(); });
              })">Deactivate</button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
