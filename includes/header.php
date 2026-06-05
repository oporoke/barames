<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
requireLogin();
$user = currentUser();
$roleColors = ['admin'=>'#4f46e5','manager'=>'#f59e0b','cashier'=>'#10b981'];
$roleColor  = $roleColors[$user['role']] ?? '#888';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bar POS System</title>
  <link rel="stylesheet" href="/<?= APPDIR ?>/assets/css/style.css">
</head>
<body>

<nav class="navbar">
  <div class="nav-brand">&#127866; Bar POS</div>

  <button class="nav-toggle" onclick="document.querySelector('.nav-links').classList.toggle('open')" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>

  <ul class="nav-links">
    <?php
    $cur = $_SERVER['REQUEST_URI'];
    $links = ['dashboard'=>'Dashboard','stock'=>'Stock','sales'=>'Sales','expenses'=>'Expenses','reports'=>'Reports','suppliers'=>'Suppliers','purchase_orders'=>'Purchase Orders'];
    if (isAdmin()) $links['users'] = 'Users';
    foreach ($links as $mod => $label):
      if (!canAccess($mod)) continue;
      $href   = "/barpos/modules/$mod/";
      $active = strpos($cur, "/modules/$mod/") !== false ? 'active' : '';
    ?>
    <li><a href="<?= $href ?>" class="<?= $active ?>"><?= $label ?></a></li>
    <?php endforeach; ?>
  </ul>

  <div class="nav-user">
    <span class="nav-user-name">
      <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $roleColor ?>;margin-right:5px;"></span>
      <?= htmlspecialchars($user['name']) ?>
      <small style="color:#9ca3af;margin-left:4px;">(<?= $user['role'] ?>)</small>
    </span>
    <a href="/barpos/logout.php" class="btn-logout"
       onclick="return confirm('Sign out?')">Sign out</a>
  </div>
</nav>

<!-- CONFIRM MODAL -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal">
    <h3 id="confirmTitle">Are you sure?</h3>
    <p id="confirmMessage">This action cannot be undone.</p>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeConfirm()">Cancel</button>
      <button class="btn btn-danger" id="confirmBtn">Confirm</button>
    </div>
  </div>
</div>

<div class="container">


