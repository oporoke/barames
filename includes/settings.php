<?php
function getSetting($conn, $key, $default = '') {
    $key    = $conn->real_escape_string($key);
    $result = $conn->query("SELECT setting_value FROM settings WHERE setting_key='$key' LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        return $row['setting_value'];
    }
    return $default;
}

function getAllSettings($conn) {
    $result   = $conn->query("SELECT * FROM settings ORDER BY setting_group, setting_key");
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function saveSetting($conn, $key, $value) {
    $key   = $conn->real_escape_string($key);
    $value = $conn->real_escape_string($value);
    $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key','$value')
                  ON DUPLICATE KEY UPDATE setting_value='$value'");
}
