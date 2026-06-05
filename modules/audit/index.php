<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireRole('admin');

$perPage = 30;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$search  = isset($_GET['q']) ? sanitize($conn, $_GET['q']) : '';
$user    = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$from    = isset($_GET['from']) ? sanitize($conn, $_GET['from']) : date('Y-m-01');
$to      = isset($_GET['to'])   ? sanitize($conn, $_GET['to'])   : date('Y-m-d');

$where  = "al.created_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'";
if ($search) $where .= " AND (al.action LIKE '%$search%' OR al.detail LIKE '%$search%')";
if ($user)   $where .= " AND al.user_id=$user";

$total  = $conn->query("SELECT COUNT(*) AS c FROM audit_log al WHERE $where")->fetch_assoc()['c'];
$pages  = max(1, ceil($total / $perPage));
$logs   = $conn->query("SELECT al.*, u.name AS user_name, u.username FROM audit_log al LEFT JOIN users u ON al.user_id=u.id WHERE $where ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset");
$users  = $conn->query("SELECT id, name, username FROM users ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$actionColors = [
    'login'            => '#10b981', 'logout'           => '#6b7280',
    'login_failed'     => '#ef4444', 'sale_add'         => '#3b82f6',
    'sale_delete'      => '#ef4444', 'sale_edit'        => '#f59e0b',
    'expense_add'      => '#8b5cf6', 'expense_delete'   => '#ef4444',
    'expense_edit'     => '#f59e0b', 'stock_add'        => '#10b981',
    'stock_adjust'     => '#3b82f6', 'stock_delete'     => '#ef4444',
    'user_created'     => '#10b981', 'user_updated'     => '#f59e0b',
    'user_deactivated' => '#ef4444', 'settings_update'  => '#4f46e5',
    'backup_downloaded'=> '#8b5cf6', 'po_create'        => '#3b82f6',
    'po_receive'       => '#10b981',
];

require_once '../../includes/header.php';
?>
<h1 class="page-title">&#128203; Audit Log</h1>

<div class="card">
  <form method="GET" action="" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div class="form-group" style="min-width:160px;">
      <label>Search</label>
      <input type="text" name="q" placeholder="action or detail..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="form-group">
      <label>User</label>
      <select name="user">
        <option value="0">All Users</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $user===$u['id']?'selected':'' ?>><?= htmlspecialchars($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>From</label><input type="date" name="from" value="<?= $from ?>"></div>
    <div class="form-group"><label>To</label><input type="date" name="to" value="<?= $to ?>"></div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="?" class="btn btn-secondary btn-sm">Reset</a>
  </form>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-left"><h3 style="margin:0;">Log Entries (<?= number_format($total) ?>)</h3></div>
  </div>
  <?php if ($logs->num_rows === 0): ?>
  <div class="empty-state"><div class="empty-icon">&#128203;</div><p>No log entries found.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date &amp; Time</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead>
    <tbody>
    <?php while ($row = $logs->fetch_assoc()):
      $ac = $actionColors[$row['action']] ?? '#6b7280';
    ?>
    <tr>
      <td style="white-space:nowrap;"><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
      <td>
        <?php if ($row['user_name']): ?>
        <strong><?= htmlspecialchars($row['user_name']) ?></strong>
        <small style="color:var(--muted);display:block;"><?= htmlspecialchars($row['username']) ?></small>
        <?php else: ?>
        <span style="color:var(--muted);">System</span>
        <?php endif; ?>
      </td>
      <td><span class="badge" style="background:<?= $ac ?>22;color:<?= $ac ?>"><?= htmlspecialchars($row['action']) ?></span></td>
      <td style="color:var(--muted);font-size:.85rem;"><?= htmlspecialchars($row['detail'] ?? '—') ?></td>
      <td style="font-size:.8rem;color:var(--muted);"><?= htmlspecialchars($row['ip'] ?? '—') ?></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?><div class="pagination">
    <?php if($page>1): ?><a href="?q=<?= urlencode($search) ?>&user=<?= $user ?>&from=<?= $from ?>&to=<?= $to ?>&page=<?= $page-1 ?>">&#8592;</a><?php endif; ?>
    <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
      <<?= $i===$page?'span class="current"':'a href="?q='.urlencode($search).'&user='.$user.'&from='.$from.'&to='.$to.'&page='.$i.'"' ?>><?= $i ?></<?= $i===$page?'span':'a' ?>>
    <?php endfor; ?>
    <?php if($page<$pages): ?><a href="?q=<?= urlencode($search) ?>&user=<?= $user ?>&from=<?= $from ?>&to=<?= $to ?>&page=<?= $page+1 ?>">&#8594;</a><?php endif; ?>
  </div><?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
