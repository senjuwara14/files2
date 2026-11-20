<?php
require 'app.php';
require_role(['Admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { go('admin_dashboard.php'); }
csrf_check();

// Builds a restorable .sql file with plain PHP (no mysqldump needed).
$tables = [];
foreach ($conn->query("SHOW TABLES") as $r) { $tables[] = array_values($r)[0]; }

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="don_felix_backup_' . date('Y-m-d_His') . '.sql"');
echo "-- Don Felix Sizzling House backup\n-- Created " . date('Y-m-d H:i:s') . " (Philippine time)\n";
echo "-- Restore: phpMyAdmin > select the database > Import > choose this file.\n\n";
echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $t) {
    $create = $conn->query("SHOW CREATE TABLE `$t`")->fetch_assoc();
    echo "DROP TABLE IF EXISTS `$t`;\n" . $create['Create Table'] . ";\n\n";
    $res = $conn->query("SELECT * FROM `$t`");
    foreach ($res as $row) {
        $vals = [];
        foreach ($row as $v) { $vals[] = $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'"; }
        echo "INSERT INTO `$t` VALUES (" . implode(', ', $vals) . ");\n";
    }
    echo "\n";
}
echo "SET FOREIGN_KEY_CHECKS=1;\n";
exit();
