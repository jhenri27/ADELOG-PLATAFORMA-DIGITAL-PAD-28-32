<?php
require_once __DIR__ . '/db.php';
$db = Database::getInstance();
$conn = $db->getConnection();

$checkCol = $conn->query("SHOW COLUMNS FROM inscritos LIKE 'telefono_fijo'");
if ($checkCol && $checkCol->num_rows === 0) {
    $conn->query("ALTER TABLE inscritos ADD COLUMN telefono_fijo VARCHAR(30) NULL AFTER telefono");
    echo "✓ Columna telefono_fijo agregada a la tabla inscritos.\n";
} else {
    echo "✓ Columna telefono_fijo ya existe en inscritos.\n";
}
