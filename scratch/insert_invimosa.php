<?php
require_once __DIR__ . '/../backend/db.php';
$db = Database::getInstance();
$conn = $db->getConnection();
$conn->query("INSERT IGNORE INTO sectores_circ3 (nombre, municipio) VALUES ('INVIMOSA', 'Santo Domingo Este')");
echo "INVIMOSA insertado exitosamente.";
?>
