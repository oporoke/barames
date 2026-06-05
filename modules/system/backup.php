<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
requireRole('admin');

$filename = 'barpos_backup_' . date('Y-m-d_His') . '.sql';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) $tables[] = $row[0];

echo "-- ============================================================\n";
echo "-- Bar POS System - Database Backup\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- ============================================================\n\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    // Structure
    $res    = $conn->query("SHOW CREATE TABLE `$table`");
    $row    = $res->fetch_row();
    echo "-- Table: $table\n";
    echo "DROP TABLE IF EXISTS `$table`;\n";
    echo $row[1] . ";\n\n";

    // Data
    $rows = $conn->query("SELECT * FROM `$table`");
    if (!$rows || $rows->num_rows === 0) continue;

    $fields   = [];
    $fieldInfo= $rows->fetch_fields();
    foreach ($fieldInfo as $fi) $fields[] = '`' . $fi->name . '`';
    $fieldList = implode(', ', $fields);

    $inserts = [];
    while ($row = $rows->fetch_row()) {
        $vals = array_map(function($v) use ($conn) {
            if ($v === null) return 'NULL';
            return "'" . $conn->real_escape_string($v) . "'";
        }, $row);
        $inserts[] = '(' . implode(', ', $vals) . ')';
        // Flush in batches of 100
        if (count($inserts) >= 100) {
            echo "INSERT INTO `$table` ($fieldList) VALUES\n" . implode(",\n", $inserts) . ";\n";
            $inserts = [];
        }
    }
    if (!empty($inserts)) {
        echo "INSERT INTO `$table` ($fieldList) VALUES\n" . implode(",\n", $inserts) . ";\n";
    }
    echo "\n";
}

echo "SET FOREIGN_KEY_CHECKS=1;\n";
echo "-- End of backup\n";

auditLog($conn, 'backup_downloaded', 'Database backup downloaded by ' . ($_SESSION['user_name'] ?? 'unknown'));
exit;
