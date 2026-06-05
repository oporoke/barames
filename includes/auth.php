<?php
if (session_status() === PHP_SESSION_NONE) session_start();

define('SESSION_TIMEOUT', 3600); // 1 hour

function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        header('Location: /barpos/login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    // Session timeout check
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash'] = ['msg' => 'Session expired. Please log in again.', 'type' => 'error'];
        header('Location: /barpos/login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function requireRole(...$roles) {
    requireLogin();
    if (!in_array($_SESSION['user_role'] ?? '', $roles)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

function currentUser() {
    return [
        'id'       => $_SESSION['user_id']   ?? null,
        'name'     => $_SESSION['user_name'] ?? 'Guest',
        'role'     => $_SESSION['user_role'] ?? 'cashier',
        'username' => $_SESSION['username']  ?? '',
    ];
}

function isAdmin()   { return ($_SESSION['user_role'] ?? '') === 'admin';   }
function isManager() { return in_array($_SESSION['user_role'] ?? '', ['admin','manager']); }

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;color:red">Invalid request. Please go back and try again.</p>');
    }
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function auditLog($conn, $action, $detail = '') {
    $userId = $_SESSION['user_id'] ?? null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $action = $conn->real_escape_string($action);
    $detail = $conn->real_escape_string($detail);
    $ip     = $conn->real_escape_string($ip);
    if ($userId) {
        $conn->query("INSERT INTO audit_log (user_id, action, detail, ip) VALUES ($userId, '$action', '$detail', '$ip')");
    } else {
        $conn->query("INSERT INTO audit_log (user_id, action, detail, ip) VALUES (NULL, '$action', '$detail', '$ip')");
    }
}

// Role permissions map
function canAccess($feature) {
    $role = $_SESSION['user_role'] ?? 'cashier';
    $permissions = [
        'admin'   => ['dashboard','stock','sales','expenses','reports','users','audit','suppliers','purchase_orders'],
        'manager' => ['dashboard','stock','sales','expenses','reports','suppliers','purchase_orders'],
        'cashier' => ['dashboard','sales','expenses'],
    ];
    return in_array($feature, $permissions[$role] ?? []);
}


