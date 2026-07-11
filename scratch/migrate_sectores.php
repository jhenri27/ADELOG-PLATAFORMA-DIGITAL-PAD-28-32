<?php
/**
 * Migración para Crear y Poblar la Tabla de Sectores de la Circunscripción 3
 */

require_once __DIR__ . '/../backend/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Crear tabla
    $sqlCreate = "CREATE TABLE IF NOT EXISTS sectores_circ3 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL UNIQUE,
        municipio VARCHAR(100) NOT NULL
    )";
    
    if ($conn->query($sqlCreate)) {
        echo "Tabla 'sectores_circ3' creada exitosamente.<br>\n";
    } else {
        throw new Exception("Error al crear tabla: " . $conn->error);
    }
    
    // Sectores predefinidos de la Circunscripción 3
    $sectores = [
        // Santo Domingo Este
        ['El Tamarindo', 'Santo Domingo Este'],
        ['Hainamosa', 'Santo Domingo Este'],
        ['Invivienda', 'Santo Domingo Este'],
        ['Villa Carmen', 'Santo Domingo Este'],
        ['Los Pinos', 'Santo Domingo Este'],
        ['La Toronja', 'Santo Domingo Este'],
        ['El Cachón de la Rubia', 'Santo Domingo Este'],
        ['Cabirma del Este', 'Santo Domingo Este'],
        ['Lucerna', 'Santo Domingo Este'],
        ['Vista Hermosa', 'Santo Domingo Este'],
        ['Brisa Oriental', 'Santo Domingo Este'],
        ['San José de Mendoza', 'Santo Domingo Este'],
        ['Cancino Adentro', 'Santo Domingo Este'],
        ['Los Rosales', 'Santo Domingo Este'],
        ['Villa Tropicalia', 'Santo Domingo Este'],
        ['Las Acacias', 'Santo Domingo Este'],
        ['Mendoza', 'Santo Domingo Este'],
        ['Prado Oriental', 'Santo Domingo Este'],
        ['El Almirante', 'Santo Domingo Este'],
        ['Villa Liberación', 'Santo Domingo Este'],
        ['Autopista de San Isidro', 'Santo Domingo Este'],
        
        // San Luis
        ['San Luis', 'San Luis'],
        ['El Bonito', 'San Luis'],
        
        // La Caleta
        ['La Caleta', 'La Caleta'],
        ['Valiente', 'La Caleta'],
        ['Campo Lindo', 'La Caleta'],
        
        // Boca Chica
        ['Boca Chica', 'Boca Chica'],
        ['Andrés', 'Boca Chica'],
        ['Andrés Boca Chica', 'Boca Chica'],
        ['Monte Adentro', 'Boca Chica'],
        
        // Guerra
        ['Guerra', 'San Antonio de Guerra'],
        ['San Antonio de Guerra', 'San Antonio de Guerra'],
        ['El Toro', 'San Antonio de Guerra'],
        ['Estorga', 'San Antonio de Guerra'],
        ['Las Parras', 'San Antonio de Guerra']
    ];
    
    $inserted = 0;
    foreach ($sectores as $sec) {
        $nombre = $conn->real_escape_string($sec[0]);
        $municipio = $conn->real_escape_string($sec[1]);
        
        // Evitar duplicados al re-ejecutar
        $sqlInsert = "INSERT IGNORE INTO sectores_circ3 (nombre, municipio) VALUES ('$nombre', '$municipio')";
        if ($conn->query($sqlInsert)) {
            if ($conn->affected_rows > 0) {
                $inserted++;
            }
        }
    }
    
    echo "Se insertaron $inserted nuevos sectores de la Circunscripción 3 en la base de datos.<br>\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "<br>\n";
}
?>
