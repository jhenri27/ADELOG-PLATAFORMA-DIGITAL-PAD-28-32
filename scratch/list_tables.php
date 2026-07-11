<?php
require_once __DIR__ . '/../backend/db.php';
$db = Database::getInstance();
$conn = $db->getConnection();

$res = $conn->query("SHOW TABLES");
echo "=== TABLAS EN LA BASE DE DATOS ===\n";
while ($row = $res->fetch_row()) {
    $table = $row[0];
    echo "- $table\n";
    
    // List columns
    $resCol = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($col = $resCol->fetch_assoc()) {
        echo "  * {$col['Field']} ({$col['Type']})\n";
    }
    echo "\n";
}
?>
