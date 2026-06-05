<?php
function formatMoney($amount) {
    return 'TZS ' . number_format((float)$amount, 2);
}

function getCategories($conn) {
    $result = $conn->query("SELECT * FROM categories ORDER BY name");
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getTodayDate() {
    return date('Y-m-d');
}

function sanitize($conn, $value) {
    return $conn->real_escape_string(trim($value));
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function showFlash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        echo "<div class='alert alert-{$f['type']}'>{$f['msg']}</div>";
        unset($_SESSION['flash']);
    }
}
