<?php
define('APPDIR', 'barpos');
if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: /barpos/modules/dashboard/');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/auth.php';

$error = '';
$next  = htmlspecialchars($_GET['next'] ?? '/barpos/modules/dashboard/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF for login (token seeded before form render)
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username=? AND is_active=1 LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['user_name']     = $user['name'];
            $_SESSION['user_role']     = $user['role'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['last_activity'] = time();
            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

            $conn->query("UPDATE users SET last_login=NOW() WHERE id=" . $user['id']);
            auditLog($conn, 'login', 'User logged in');

            $next = $_POST['next'] ?? '/barpos/modules/dashboard/';
            header('Location: ' . $next);
            exit;
        } else {
            $error = 'Invalid username or password.';
            auditLog($conn, 'login_failed', "Failed attempt for username: $username");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — Bar POS</title>
  <link rel="stylesheet" href="/barpos/assets/css/style.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg,#f0f2f5); }
    .login-box { background:#fff; border-radius:12px; padding:40px; width:100%; max-width:400px; box-shadow:0 4px 24px rgba(0,0,0,.1); }
    .login-logo { text-align:center; margin-bottom:28px; }
    .login-logo h1 { font-size:2rem; color:#1a1a2e; }
    .login-logo p  { color:#6b7280; font-size:.9rem; margin-top:4px; }
    .form-group { margin-bottom:16px; }
    .form-group label { display:block; font-size:.85rem; font-weight:600; margin-bottom:5px; }
    .form-group input { width:100%; padding:10px 14px; border:1.5px solid #e5e7eb; border-radius:7px; font-size:.95rem; transition:.15s; }
    .form-group input:focus { outline:none; border-color:#4f46e5; box-shadow:0 0 0 3px rgba(79,70,229,.12); }
    .btn-block { width:100%; padding:11px; font-size:1rem; }
    .error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:7px; padding:10px 14px; margin-bottom:16px; font-size:.875rem; }
    .role-hint { text-align:center; margin-top:20px; font-size:.8rem; color:#9ca3af; }
  </style>
</head>
<body>
<div class="login-box">
  <div class="login-logo">
    <h1>&#127866; Bar POS</h1>
    <p>Sign in to your account</p>
  </div>

  <?php if ($error): ?>
  <div class="error">&#9888; <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="error" style="background:#d1fae5;color:#065f46;border-color:#6ee7b7;"><?= htmlspecialchars($f['msg']) ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="next" value="<?= $next ?>">
    <div class="form-group">
      <label>Username</label>
      <input type="text" name="username" autofocus autocomplete="username"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Sign In &#8594;</button>
  </form>

  <div class="role-hint">
    Default login: <strong>admin</strong> / <strong>admin123</strong>
  </div>
</div>
</body>
</html>
