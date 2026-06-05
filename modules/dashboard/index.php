<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
requireLogin();

$today = getTodayDate();
$totalSalesToday    = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM sales WHERE sale_date='$today'")->fetch_assoc()['t'];
$totalExpensesToday = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM expenses WHERE expense_date='$today'")->fetch_assoc()['t'];
$netToday           = $totalSalesToday - $totalExpensesToday;
$drinksSales  = $conn->query("SELECT COALESCE(SUM(s.amount),0) AS t FROM sales s JOIN categories c ON s.category_id=c.id WHERE c.type='drinks' AND s.sale_date='$today'")->fetch_assoc()['t'];
$kitchenSales = $conn->query("SELECT COALESCE(SUM(s.amount),0) AS t FROM sales s JOIN categories c ON s.category_id=c.id WHERE c.type='kitchen' AND s.sale_date='$today'")->fetch_assoc()['t'];
$lowStock = $conn->query("SELECT s.name, s.quantity, s.low_stock_threshold, c.name AS cat FROM stock_items s JOIN categories c ON s.category_id=c.id WHERE s.quantity <= s.low_stock_threshold AND s.is_active=1 ORDER BY s.quantity ASC LIMIT 8");
require_once '../../includes/header.php';
?>
<h1 class="page-title">Dashboard &mdash; <?= date('d M Y') ?></h1>
<?php showFlash(); ?>
<div class="stat-grid">
    <div class="stat-card green"><div class="label">Total Sales Today</div><div class="value"><?= formatMoney($totalSalesToday) ?></div></div>
    <div class="stat-card red"><div class="label">Total Expenses Today</div><div class="value"><?= formatMoney($totalExpensesToday) ?></div></div>
    <div class="stat-card"><div class="label">Net Revenue Today</div><div class="value" style="color:<?= $netToday >= 0 ? '#10b981' : '#ef4444' ?>"><?= formatMoney($netToday) ?></div></div>
    <div class="stat-card blue"><div class="label">Drinks Sales</div><div class="value"><?= formatMoney($drinksSales) ?></div></div>
    <div class="stat-card blue"><div class="label">Kitchen Sales</div><div class="value"><?= formatMoney($kitchenSales) ?></div></div>
</div>
<?php if ($lowStock->num_rows > 0): ?>
<div class="card">
    <h3>&#9888; Low Stock Alerts</h3>
    <table>
        <thead><tr><th>Item</th><th>Category</th><th>Qty</th><th>Threshold</th></tr></thead>
        <tbody>
        <?php while ($row = $lowStock->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><span class="badge badge-<?= $row['cat'] === 'Drinks' ? 'drinks' : 'kitchen' ?>"><?= $row['cat'] ?></span></td>
                <td><span class="badge badge-low"><?= $row['quantity'] ?></span></td>
                <td><?= $row['low_stock_threshold'] ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once '../../includes/footer.php'; ?>

