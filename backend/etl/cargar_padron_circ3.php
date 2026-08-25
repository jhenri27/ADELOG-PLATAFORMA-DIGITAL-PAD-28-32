<?php
/**
 * Motor ETL de Ingesta Masiva del Padrón de Consulta - Circunscripción 3
 * PAD/28-32 - Plataforma Electoral
 */

require_once __DIR__ . '/../config.php';

$csvFile = __DIR__ . '/../../scratch/circ3_clean.csv';

if (!file_exists($csvFile)) {
    die(json_encode(["exito" => false, "mensaje" => "Archivo CSV no encontrado: $csvFile"]));
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    die(json_encode(["exito" => false, "mensaje" => "Error de conexión DB: " . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die(json_encode(["exito" => false, "mensaje" => "Error al abrir el archivo CSV"]));
}

// Omitir cabecera
$header = fgetcsv($handle);

$startTime = microtime(true);
$totalProcesados = 0;
$totalInsertados = 0;
$totalOmitidos = 0;
$batchSize = 1000;

$stmt = $conn->prepare("
    INSERT INTO padron_consulta_circ3 
    (cedula, nombres, apellido1, apellido2, sexo, edad, fecha_nacimiento, telefono1, telefono2, telefono3, circunscripcion, municipio, sector, recinto, zona, cedula_valida)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
    nombres = VALUES(nombres),
    apellido1 = VALUES(apellido1),
    apellido2 = VALUES(apellido2),
    sexo = VALUES(sexo),
    edad = VALUES(edad),
    telefono1 = VALUES(telefono1),
    telefono2 = VALUES(telefono2),
    telefono3 = VALUES(telefono3),
    sector = VALUES(sector),
    recinto = VALUES(recinto),
    zona = VALUES(zona)
");

$conn->begin_transaction();

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 16) continue;
    
    $totalProcesados++;
    
    $cedula      = trim($row[0]);
    $nombres     = trim($row[1]);
    $apellido1   = trim($row[2]);
    $apellido2   = trim($row[3]);
    $sexo        = trim($row[4]);
    $edad        = (int)($row[5] ?? 0);
    $fnac_raw    = trim($row[6]);
    $fecha_nac   = (!empty($fnac_raw) && $fnac_raw !== 'None') ? date('Y-m-d', strtotime($fnac_raw)) : null;
    $telefono1   = trim($row[7]);
    $telefono2   = trim($row[8]);
    $telefono3   = trim($row[9]);
    $circ        = trim($row[10]);
    $municipio   = trim($row[11]);
    $sector      = trim($row[12]);
    $recinto     = trim($row[13]);
    $zona        = trim($row[14]);
    $ced_valida  = (int)($row[15] ?? 1);
    
    $stmt->bind_param(
        "sssssisssssssssi",
        $cedula, $nombres, $apellido1, $apellido2, $sexo, $edad,
        $fecha_nac, $telefono1, $telefono2, $telefono3,
        $circ, $municipio, $sector, $recinto, $zona, $ced_valida
    );
    
    if ($stmt->execute()) {
        $totalInsertados++;
    } else {
        $totalOmitidos++;
    }
    
    if ($totalProcesados % $batchSize === 0) {
        $conn->commit();
        $conn->begin_transaction();
    }
}

$conn->commit();
fclose($handle);
$stmt->close();

$duration = round(microtime(true) - $startTime, 2);

// Registrar log en historial_etl
$detalles = "Carga de Padrón Circunscripción 3 completada en {$duration}s. Procesados: $totalProcesados, Insertados/Actualizados: $totalInsertados, Omitidos: $totalOmitidos.";
$stmtEtl = $conn->prepare("INSERT INTO historial_etl (nombre_archivo, registros_cargados, registros_omitidos, detalles_errores, usuario_id, fecha_ejecucion) VALUES ('PadronStogoEstePremil.xlsx', ?, ?, ?, 1, NOW())");
$stmtEtl->bind_param("iis", $totalInsertados, $totalOmitidos, $detalles);
$stmtEtl->execute();
$stmtEtl->close();

echo json_encode([
    "exito" => true,
    "mensaje" => "Ingesta masiva de Padrón Circunscripción 3 completada con éxito.",
    "duracion_segundos" => $duration,
    "procesados" => $totalProcesados,
    "insertados" => $totalInsertados,
    "omitidos" => $totalOmitidos
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
