<?php
// Run this ONCE to create the admin user
// Visit: http://localhost/barpos/database/create_admin.php
// Then DELETE this file immediately after.

require_once '../includes/db.php';

$username = 'admin';
$password = 'admin123';
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$name     = 'Administrator';
$role     = 'admin';

$stmt = $conn->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE password=VALUES(password)");
$stmt->bind_param("ssss", $name, $username, $hash, $role);

if ($stmt->execute()) {
    echo "<h2 style='font-family:sans-serif;color:green'>Admin user created successfully.</h2>";
    echo "<p style='font-family:sans-serif'>Username: <strong>admin</strong><br>Password: <strong>admin123</strong></p>";
    echo "<p style='font-family:sans-serif;color:red'><strong>DELETE this file now:</strong> C:\\xampp\\htdocs\\barpos\\database\\create_admin.php</p>";
    echo "<p><a href='/barpos/login.php'>Go to Login</a></p>";
} else {
    echo "<p style='color:red'>Error: " . $conn->error . "</p>";
}
$stmt->close();
?>
