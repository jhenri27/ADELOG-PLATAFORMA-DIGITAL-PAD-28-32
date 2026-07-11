<?php
/**
 * Migración para estructurar Colegios, Regiones y Zonas
 */

require_once __DIR__ . '/../backend/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // 1. Modificar tabla inscritos
    $checkInscritos = $conn->query("SHOW COLUMNS FROM inscritos LIKE 'region'");
    if ($checkInscritos && $checkInscritos->num_rows === 0) {
        $conn->query("ALTER TABLE inscritos ADD COLUMN region VARCHAR(100) NULL AFTER municipio");
        echo "✓ Columna 'region' agregada a tabla 'inscritos'.<br>\n";
    }
    
    $checkZona = $conn->query("SHOW COLUMNS FROM inscritos LIKE 'zona'");
    if ($checkZona && $checkZona->num_rows === 0) {
        $conn->query("ALTER TABLE inscritos ADD COLUMN zona VARCHAR(100) NULL AFTER region");
        echo "✓ Columna 'zona' agregada a tabla 'inscritos'.<br>\n";
    }
    
    // 2. Crear tabla colegios_estructural
    $sqlCreate = "CREATE TABLE IF NOT EXISTS colegios_estructural (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colegio VARCHAR(20) NOT NULL UNIQUE,
        recinto VARCHAR(255) NOT NULL,
        region VARCHAR(100) NOT NULL,
        zona VARCHAR(100) NOT NULL,
        votos_prm INT DEFAULT 0,
        votos_diputada INT DEFAULT 0
    )";
    
    if ($conn->query($sqlCreate)) {
        echo "✓ Tabla 'colegios_estructural' configurada con éxito.<br>\n";
    } else {
        throw new Exception("Error al crear tabla colegios_estructural: " . $conn->error);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "<br>\n";
}
?>
