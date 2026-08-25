<?php
/**
 * REST API Endpoint: Consulta y Validación del Padrón de la Circunscripción 3
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? 'buscar';

if ($action === 'buscar') {
    $cedulaInput = trim($_GET['cedula'] ?? $_POST['cedula'] ?? '');
    
    if (empty($cedulaInput)) {
        http_response_code(400);
        echo json_encode(["exito" => false, "mensaje" => "La Cédula es requerida para la consulta."]);
        exit;
    }
    
    // Normalizar Cédula a formato XXX-XXXXXXX-X y limpia de guiones
    $cedLimpia = preg_replace('/\D/', '', $cedulaInput);
    if (strlen($cedLimpia) === 11) {
        $cedFmt = sprintf("%s-%s-%s", substr($cedLimpia, 0, 3), substr($cedLimpia, 3, 7), substr($cedLimpia, 10, 1));
    } else {
        $cedFmt = $cedulaInput;
    }
    
    // 1. Buscar en Padrón Máster Circunscripción 3
    $cedEsc = $conn->real_escape_string($cedFmt);
    $cedLimpEsc = $conn->real_escape_string($cedLimpia);
    
    $resMaster = $conn->query("
        SELECT id, cedula, nombres, apellido1, apellido2, sexo, edad, fecha_nacimiento, 
               telefono1, telefono2, telefono3, circunscripcion, municipio, sector, recinto, zona, cedula_valida 
        FROM padron_consulta_circ3 
        WHERE cedula = '$cedEsc' OR REPLACE(cedula, '-', '') = '$cedLimpEsc' 
        LIMIT 1
    ");
    
    if ($resMaster && $resMaster->num_rows > 0) {
        $votante = $resMaster->fetch_assoc();
        $votante['apellidos'] = trim($votante['apellido1'] . ' ' . $votante['apellido2']);
        $votante['nombre_completo'] = trim($votante['nombres'] . ' ' . $votante['apellidos']);
        
        // 2. Verificar si ya está en la tabla de 'inscritos' (Simpatizantes Registrados)
        $resInscrito = $conn->query("
            SELECT i.id, i.coordinador, i.fecha_registro, i.registrado_por, u.nombre as nombre_coordinador 
            FROM inscritos i 
            LEFT JOIN usuarios u ON i.registrado_por = u.id 
            WHERE i.cedula = '$cedEsc' OR REPLACE(i.cedula, '-', '') = '$cedLimpEsc' 
            LIMIT 1
        ");
        
        $estaInscrito = false;
        $detallesInscrito = null;
        
        if ($resInscrito && $resInscrito->num_rows > 0) {
            $estaInscrito = true;
            $rowIns = $resInscrito->fetch_assoc();
            $detallesInscrito = [
                "inscrito_id" => (int)$rowIns['id'],
                "coordinador" => $rowIns['coordinador'],
                "registrado_por" => $rowIns['nombre_coordinador'] ?? $rowIns['coordinador'],
                "fecha_registro" => $rowIns['fecha_registro']
            ];
        }
        
        echo json_encode([
            "exito" => true,
            "encontrado" => true,
            "mensaje" => "Votante verificado en el Padrón Máster de Circunscripción 3.",
            "votante" => $votante,
            "esta_inscrito" => $estaInscrito,
            "detalles_inscripcion" => $detallesInscrito
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    } else {
        // No encontrado en Circunscripción 3
        echo json_encode([
            "exito" => true,
            "encontrado" => false,
            "mensaje" => "La cédula provista no figura en el Padrón Máster de la Circunscripción 3."
        ]);
        exit;
    }
}

if ($action === 'stats') {
    $resTotalMaster = $conn->query("SELECT COUNT(*) as total FROM padron_consulta_circ3")->fetch_assoc();
    $resTotalInscritos = $conn->query("SELECT COUNT(*) as total FROM inscritos")->fetch_assoc();
    
    $resSectores = $conn->query("SELECT sector, COUNT(*) as cantidad FROM padron_consulta_circ3 GROUP BY sector ORDER BY cantidad DESC LIMIT 10");
    $sectores = [];
    while ($r = $resSectores->fetch_assoc()) {
        $sectores[] = $r;
    }
    
    $resRecintos = $conn->query("SELECT recinto, COUNT(*) as cantidad FROM padron_consulta_circ3 GROUP BY recinto ORDER BY cantidad DESC LIMIT 10");
    $recintos = [];
    while ($r = $resRecintos->fetch_assoc()) {
        $recintos[] = $r;
    }
    
    echo json_encode([
        "exito" => true,
        "total_master_circ3" => (int)($resTotalMaster['total'] ?? 0),
        "total_inscritos_activos" => (int)($resTotalInscritos['total'] ?? 0),
        "top_sectores" => $sectores,
        "top_recintos" => $recintos
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

http_response_code(400);
echo json_encode(["exito" => false, "mensaje" => "Acción no reconocida."]);
