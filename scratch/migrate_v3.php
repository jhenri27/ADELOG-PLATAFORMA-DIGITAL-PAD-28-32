<?php
require_once __DIR__ . '/../backend/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    echo "Ejecutando parche de migración FASE III...\n";
    
    // 1. Alterar tabla usuarios para añadir email si no existe
    $checkUser = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'email'");
    if ($checkUser && $checkUser->num_rows > 0) {
        echo "[INFO] La columna 'email' ya existe en usuarios.\n";
    } else {
        $conn->query("ALTER TABLE usuarios ADD COLUMN email VARCHAR(100) NULL DEFAULT NULL");
        echo "[OK] Columna 'email' añadida con éxito a usuarios.\n";
    }
    
    // Poner un correo ficticio al administrador
    $conn->query("UPDATE usuarios SET email = 'coordinador@test.com' WHERE username = 'admin' OR role = 'Administrador'");
    
    echo "Parche completado.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
