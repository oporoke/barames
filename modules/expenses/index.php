<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/validate.php';
requireLogin();

$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $conn->query("DELETE FROM expenses WHERE id=$id");
        auditLog($conn, 'expense_delete', "Deleted expense $id");
        $_SESSION['flash'] = ['msg' => 'Expense deleted.', 'type' => 'success'];
        header("Location: ".$_SERVER['PHP_SELF']."?date=".($_POST['current_date']??date('Y-m-d'))); exit;
    }

    if (isset($_POST['edit_id'])) {
        $id   = (int)$_POST['edit_id'];
        $cat  = (int)$_POST['category_id'];
        $desc = trim($_POST['description']??'');
        $amt  = (float)($_POST['amount']??0);
        $date = trim($_POST['expense_date']??'');
        $errs = validateExpense($_POST);
        if (empty($errs)) {
            $stmt = $conn->prepare("UPDATE expenses SET category_id=?,description=?,amount=?,expense_date=? WHERE id=?");
            $stmt->bind_param("isdsi",$cat,$desc,$amt,$date,$id);
            $stmt->execute(); $stmt->close();
            auditLog($conn,'expense_edit',"Edited expense $id");
            $_SESSION['flash']=['msg'=>'&#10003; Expense updated.','type'=>'success'];
        } else {
            $_SESSION['flash']=['msg'=>implode(' | ',$errs),'type'=>'error'];
        }
        header("Location: ".$_SERVER['PHP_SELF']."?date=$date"); exit;
    }

    $cat  = (int)($_POST['category_id']??0);
    $desc = trim($_POST['description']??'');
    $amt  = (float)($_POST['amount']??0);
    $date = trim($_POST['expense_date']??date('Y-m-d'));

    $formErrors = validateExpense($_POST);

    if (empty($formErrors)) {
        if (isDuplicateExpense($conn,$cat,$desc,$amt,$date) && !isset($_POST['force_submit'])) {
            $formErrors[] = 'DUPLICATE_WARNING';
        } else {
            $stmt = $conn->prepare("INSERT INTO expenses (category_id,description,amount,expense_date) VALUES (?,?,?,?)");
            $stmt->bind_param("isds",$cat,$desc,$amt,$date);
            $stmt->execute(); $stmt->close();
            auditLog($conn,'expense_add',"Expense TZS $amt: $desc");
            $_SESSION['flash']=['msg'=>'&#10003; Expense of TZS '.number_format($amt,2).' recorded.','type'=>'success'];
            header("Location: ".$_SERVER['PHP_SELF']."?date=$date"); exit;
        }
    }
}

$filter  = isset($_GET['date'])?sanitize($conn,$_GET['date']):date('Y-m-d');
$editRow = isset($_GET['edit'])?$conn->query("SELECT * FROM expenses WHERE id=".(int)$_GET['edit'])->fetch_assoc():null;
$perPage = 20; $page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*$perPage;
$total   = $conn->query("SELECT COUNT(*) AS c FROM expenses WHERE expense_date='$filter'")->fetch_assoc()['c'];
$pages   = max(1,ceil($total/$perPage));
$expenses= $conn->query("SELECT e.*,c.name AS cat,c.type AS cat_type FROM expenses e JOIN categories c ON e.category_id=c.id WHERE e.expense_date='$filter' ORDER BY e.id DESC LIMIT $perPage OFFSET $offset");
$totals  = $conn->query("SELECT c.type,SUM(e.amount) AS total FROM expenses e JOIN categories c ON e.category_id=c.id WHERE e.expense_date='$filter' GROUP BY c.type")->fetch_all(MYSQLI_ASSOC);
$byType  = array_column($totals,'total','type');
$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$isDuplicate = in_array('DUPLICATE_WARNING',$formErrors);
$formErrors  = array_filter($formErrors,fn($e)=>$e!=='DUPLICATE_WARNING');

require_once '../../includes/header.php';
?>
<h1 class="page-title">&#129534; Expenses Tracker</h1>

<?php if(!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= $f['msg'] ?></div>
<?php endif; ?>

<?php if($isDuplicate): ?>
<div class="alert alert-warning">
  &#9888; <strong>Duplicate detected</strong> — same expense was recorded in the last 60 seconds.
  <form method="POST" action="" style="display:inline;margin-left:12px;">
    <?= csrfField() ?>
    <?php foreach($_POST as $k=>$v): if($k==='csrf_token') continue; ?>
    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="force_submit" value="1">
    <button type="submit" class="btn btn-warning btn-sm">Submit Anyway</button>
  </form>
</div>
<?php endif; ?>

<?php if($editRow): ?>
<div class="card" style="border:2px solid var(--warning);">
  <h3 style="color:var(--warning);">&#9998; Editing Expense #<?= $editRow['id'] ?></h3>
  <form method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="edit_id" value="<?= $editRow['id'] ?>">
    <div class="form-grid">
      <div class="form-group"><label>Department</label>
        <select name="category_id">
          <?php foreach($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $c['id']==$editRow['category_id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Description</label><input type="text" name="description" value="<?= htmlspecialchars($editRow['description']) ?>" required></div>
      <div class="form-group"><label>Amount (TZS)</label><input type="number" name="amount" step="0.01" min="0.01" value="<?= $editRow['amount'] ?>" required></div>
      <div class="form-group"><label>Date</label><input type="date" name="expense_date" value="<?= $editRow['expense_date'] ?>" max="<?= date('Y-m-d') ?>" required></div>
    </div>
    <br><div style="display:flex;gap:8px;">
      <button type="submit" class="btn btn-warning"><span class="btn-text">&#10003; Update</span><span class="spinner"></span></button>
      <a href="<?= $_SERVER['PHP_SELF'] ?>?date=<?= $filter ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Record an Expense</h3>
  <?= renderErrors(array_values($formErrors)) ?>
  <form method="POST" action="">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group"><label>Department</label>
        <select name="category_id">
          <?php foreach($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= (isset($_POST['category_id'])&&$_POST['category_id']==$c['id'])?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Description</label>
        <input type="text" name="description" maxlength="255" required placeholder="e.g. Gas refill, Charcoal..." value="<?= htmlspecialchars($_POST['description']??'') ?>">
      </div>
      <div class="form-group"><label>Amount (TZS)</label>
        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00" value="<?= htmlspecialchars($_POST['amount']??'') ?>">
      </div>
      <div class="form-group"><label>Date</label>
        <input type="date" name="expense_date" value="<?= htmlspecialchars($_POST['expense_date']??date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required>
      </div>
    </div>
    <br>
    <button type="submit" class="btn btn-danger"><span class="btn-text">&#10003; Save Expense</span><span class="spinner"></span></button>
  </form>
</div>

<div class="stat-grid">
  <div class="stat-card blue"><div class="label">Drinks</div><div class="value">TZS <?= number_format($byType['drinks']??0,0) ?></div></div>
  <div class="stat-card red"><div class="label">Kitchen</div><div class="value">TZS <?= number_format($byType['kitchen']??0,0) ?></div></div>
  <div class="stat-card"><div class="label">Total</div><div class="value">TZS <?= number_format(array_sum($byType),0) ?></div></div>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-left"><h3 style="margin:0;">Expenses — <?= htmlspecialchars($filter) ?> (<?= $total ?>)</h3></div>
    <div class="toolbar-right">
      <div class="search-bar"><span class="search-icon">&#128269;</span><input type="text" id="expSearch" placeholder="Search..." style="width:180px;"></div>
      <form method="GET" action="" style="display:flex;gap:6px;">
        <input type="date" name="date" value="<?= htmlspecialchars($filter) ?>" style="padding:7px 10px;border-radius:6px;border:1.5px solid var(--border);font-size:.85rem;">
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
      </form>
    </div>
  </div>
  <?php if($expenses->num_rows===0): ?>
  <div class="empty-state"><div class="empty-icon">&#129534;</div><p>No expenses for this date.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table id="expTable">
    <thead><tr><th>Dept</th><th>Description</th><th>Amount (TZS)</th><th>Time</th><th>Actions</th></tr></thead>
    <tbody>
    <?php while($row=$expenses->fetch_assoc()): ?>
    <tr>
      <td><span class="badge badge-<?= $row['cat_type'] ?>"><?= htmlspecialchars($row['cat']) ?></span></td>
      <td><?= htmlspecialchars($row['description']) ?></td>
      <td><strong><?= number_format($row['amount'],0) ?></strong></td>
      <td><?= date('H:i',strtotime($row['created_at'])) ?></td>
      <td><div style="display:flex;gap:4px;">
        <a href="?date=<?= $filter ?>&edit=<?= $row['id'] ?>" class="btn btn-warning btn-xs">Edit</a>
        <form method="POST" action="" style="display:inline;">
          <?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
          <input type="hidden" name="current_date" value="<?= $filter ?>">
          <button type="button" class="btn btn-danger btn-xs"
            onclick="showConfirm('Delete Expense','Delete TZS <?= number_format($row['amount'],0) ?> — <?= addslashes(htmlspecialchars($row['description'])) ?>?',function(){
              document.querySelectorAll('input[name=delete_id][value=\'<?= $row['id'] ?>\']').forEach(function(i){i.closest('form').submit();});
            })">Del</button>
        </form>
      </div></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div>
  <?php if($pages>1): ?><div class="pagination">
    <?php if($page>1): ?><a href="?date=<?= $filter ?>&page=<?= $page-1 ?>">&#8592;</a><?php endif; ?>
    <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><<?= $i===$page?'span class="current"':'a href="?date='.$filter.'&page='.$i.'"' ?>><?= $i ?></<?= $i===$page?'span':'a' ?>><?php endfor; ?>
    <?php if($page<$pages): ?><a href="?date=<?= $filter ?>&page=<?= $page+1 ?>">&#8594;</a><?php endif; ?>
  </div><?php endif; ?>
  <?php endif; ?>
</div>
<script>initSearch('expSearch','expTable');</script>
<?php require_once '../../includes/footer.php'; ?>
