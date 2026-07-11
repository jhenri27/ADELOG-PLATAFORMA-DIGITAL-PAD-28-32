<?php
/**
 * PHP Loader for Colegios Electorales Mapping
 */

require_once __DIR__ . '/../backend/db.php';

try {
    $jsonPath = __DIR__ . '/colegios_mapping.json';
    if (!file_exists($jsonPath)) {
        throw new Exception("El archivo JSON no existe: $jsonPath");
    }
    
    $data = json_decode(file_get_contents($jsonPath), true);
    if (empty($data)) {
        throw new Exception("El archivo JSON está vacío o es inválido.");
    }
    
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    echo "=== IMPORTANDO COLEGIOS A MYSQL ===\n";
    
    $stmt = $conn->prepare("INSERT INTO colegios_estructural (colegio, recinto, region, zona, votos_prm, votos_diputada) 
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                recinto = VALUES(recinto),
                                region = VALUES(region),
                                zona = VALUES(zona),
                                votos_prm = VALUES(votos_prm),
                                votos_diputada = VALUES(votos_diputada)");
                                
    $inserted = 0;
    foreach ($data as $item) {
        $colegio = $item['colegio'];
        $recinto = $item['recinto'];
        $region = $item['region'];
        $zona = $item['zona'];
        $votos_prm = intval($item['votos_prm']);
        $v_dip = intval($item['votos_diputada']);
        
        $stmt->bind_param("ssssii", $colegio, $recinto, $region, $zona, $votos_prm, $v_dip);
        if ($stmt->execute()) {
            $inserted++;
        }
    }
    $stmt->close();
    
    echo "=== COMPLETADO. Total: $inserted colegios cargados/actualizados ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
