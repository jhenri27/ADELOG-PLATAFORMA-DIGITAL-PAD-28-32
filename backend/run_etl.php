<?php
/**
 * PHP Trigger for ETL Import
 * PAD/28-32 - Plataforma Electoral
 */

require_once __DIR__ . '/db.php';

echo "=== EJECUTANDO PARSER PYTHON ETL DE EXCEL ===\n";

// Ejecutar script Python
$pythonCmd = 'python "' . __DIR__ . '/etl_import.py" 2>&1';
$output = shell_exec($pythonCmd);
echo $output . "\n";

// Cargar archivo JSON
$jsonPath = __DIR__ . '/historical_data.json';
if (!file_exists($jsonPath)) {
    die("ERROR: El archivo JSON de salida no fue creado. Revise el log de Python.\n");
}

$jsonData = json_decode(file_get_contents($jsonPath), true);
if (empty($jsonData)) {
    die("ERROR: Datos JSON vacíos o inválidos.\n");
}

// Conectar a la base de datos
$db = Database::getInstance();
$conn = $db->getConnection();

echo "=== IMPORTANDO DATOS A MYSQL (resultados_historicos) ===\n";

// Limpiar registros antiguos para evitar duplicados
$conn->query("TRUNCATE TABLE resultados_historicos");

// Sentencia preparada para inserción limpia
$stmt = $conn->prepare("INSERT INTO resultados_historicos (region, votos_prm, votos_diputada, porciento) VALUES (?, ?, ?, ?)");

$inserted = 0;
foreach ($jsonData as $item) {
    $region = $item['region'];
    $votos_prm = $item['votos_prm'];
    $votos_dip = $item['votos_diputada'];
    $pct = $item['porciento'];
    
    $stmt->bind_param("siid", $region, $votos_prm, $votos_dip, $pct);
    if ($stmt->execute()) {
        echo "✓ Importado: $region | Votos PRM: $votos_prm | Votos Diputada: $votos_dip | %: " . ($pct * 100) . "%\n";
        $inserted++;
    } else {
        echo "✗ Error al importar $region: " . $stmt->error . "\n";
    }
}
$stmt->close();

echo "=== PROCESO ETL COMPLETADO. Total: $inserted regiones importadas ===\n";
?>
