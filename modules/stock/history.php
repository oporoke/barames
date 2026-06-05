<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireRole('admin','manager');

$itemId = (int)($_GET['id']??0);
if (!$itemId) { header("Location: index.php"); exit; }

$item = $conn->query("SELECT si.*,c.name AS cat FROM stock_items si JOIN categories c ON si.category_id=c.id WHERE si.id=$itemId")->fetch_assoc();
if (!$item) { header("Location: index.php"); exit; }

$perPage  = 30;
$page     = max(1,(int)($_GET['page']??1));
$offset   = ($page-1)*$perPage;
$total    = $conn->query("SELECT COUNT(*) AS c FROM stock_movements WHERE stock_item_id=$itemId")->fetch_assoc()['c'];
$pages    = max(1,ceil($total/$perPage));
$movements= $conn->query("SELECT * FROM stock_movements WHERE stock_item_id=$itemId ORDER BY moved_at DESC, id DESC LIMIT $perPage OFFSET $offset");

$typeIn  = $conn->query("SELECT COALESCE(SUM(quantity),0) AS t FROM stock_movements WHERE stock_item_id=$itemId AND movement_type='in'")->fetch_assoc()['t'];
$typeOut = $conn->query("SELECT COALESCE(SUM(quantity),0) AS t FROM stock_movements WHERE stock_item_id=$itemId AND movement_type='out'")->fetch_assoc()['t'];

require_once '../../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
  <h1 class="page-title" style="margin:0;">&#128202; Stock History: <?= htmlspecialchars($item['name']) ?></h1>
  <a href="index.php" class="btn btn-secondary btn-sm">&#8592; Back to Stock</a>
</div>

<div class="stat-grid">
  <div class="stat-card green"><div class="label">Total Received</div><div class="value"><?= $typeIn ?> <?= $item['unit'] ?></div></div>
  <div class="stat-card red"><div class="label">Total Used/Sold</div><div class="value"><?= $typeOut ?> <?= $item['unit'] ?></div></div>
  <div class="stat-card blue"><div class="label">Current Stock</div><div class="value"><?= $item['quantity'] ?> <?= $item['unit'] ?></div></div>
  <div class="stat-card"><div class="label">Total Movements</div><div class="value"><?= $total ?></div></div>
</div>

<div class="card">
  <h3>Movement Log</h3>
  <?php if($movements->num_rows===0): ?>
  <div class="empty-state"><div class="empty-icon">&#128202;</div><p>No movements recorded yet.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Note</th></tr></thead>
    <tbody>
    <?php while($row=$movements->fetch_assoc()):
      $typeColors=['in'=>'#10b981','out'=>'#ef4444','adjustment'=>'#f59e0b'];
      $tc = $typeColors[$row['movement_type']]??'#888';
    ?>
    <tr>
      <td><?= date('d M Y',strtotime($row['moved_at'])) ?></td>
      <td><span class="badge" style="background:<?= $tc ?>22;color:<?= $tc ?>"><?= ucfirst($row['movement_type']) ?></span></td>
      <td><strong><?= $row['quantity'] ?></strong> <?= $item['unit'] ?></td>
      <td><?= htmlspecialchars($row['note']??'—') ?></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div>
  <?php if($pages>1): ?><div class="pagination">
    <?php if($page>1): ?><a href="?id=<?= $itemId ?>&page=<?= $page-1 ?>">&#8592;</a><?php endif; ?>
    <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><<?= $i===$page?'span class="current"':'a href="?id='.$itemId.'&page='.$i.'"' ?>><?= $i ?></<?= $i===$page?'span':'a' ?>><?php endfor; ?>
    <?php if($page<$pages): ?><a href="?id=<?= $itemId ?>&page=<?= $page+1 ?>">&#8594;</a><?php endif; ?>
  </div><?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
