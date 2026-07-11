<?php
/**
 * API: Motor ETL de Importación de Padrones Históricos
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ValidadorDocumentos.php'; // class is required for checking
require_once __DIR__ . '/whatsapp_webhook.php'; // contains esSectorCirc3

// Validar inicio de sesión y rol de Administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['role'] !== 'Administrador') {
    http_response_code(403);
    echo json_encode(["exito" => false, "mensaje" => "Acceso denegado. Se requieren credenciales de Administrador."]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$db = Database::getInstance();
$conn = $db->getConnection();

$uploadDir = __DIR__ . '/../../scratch/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if ($method === 'GET') {
    if ($action === 'history') {
        // Obtener historial de cargas
        $res = $conn->query("SELECT h.*, u.nombre as usuario_nombre 
                             FROM historial_etl h 
                             LEFT JOIN usuarios u ON h.usuario_id = u.id 
                             ORDER BY h.id DESC");
        $history = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $history[] = $row;
            }
        }
        echo json_encode(["exito" => true, "historial" => $history]);
        exit;
    }
}

if ($method === 'POST') {
    if ($action === 'upload') {
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "No se recibió ningún archivo."]);
            exit;
        }
        
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['xlsx', 'csv', 'txt'])) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Formato no permitido. Solo se aceptan archivos Excel (.xlsx) y CSV (.csv)."]);
            exit;
        }
        
        $tempName = uniqid('etl_') . '.' . $ext;
        $filepath = $uploadDir . $tempName;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $_SESSION['etl_filepath'] = $filepath;
            $_SESSION['etl_filename'] = $file['name'];
            
            // Ejecutar lector de Python
            $fileEsc = escapeshellarg($filepath);
            $pythonCmd = 'python "' . __DIR__ . '/../etl_reader.py" --file=' . $fileEsc . ' 2>&1';
            $output = shell_exec($pythonCmd);
            
            $res = json_decode($output, true);
            if (empty($res)) {
                http_response_code(500);
                echo json_encode(["exito" => false, "mensaje" => "Error al ejecutar el analizador de archivos en Python.", "raw_output" => $output]);
                exit;
            }
            
            echo json_encode($res);
            exit;
        } else {
            http_response_code(500);
            echo json_encode(["exito" => false, "mensaje" => "Error al mover el archivo subido al directorio temporal."]);
            exit;
        }
    }
    
    if ($action === 'execute') {
        $filepath = $_SESSION['etl_filepath'] ?? '';
        $filename = $_SESSION['etl_filename'] ?? 'archivo_desconocido';
        
        if (empty($filepath) || !file_exists($filepath)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "No hay ningún archivo cargado temporalmente para procesar."]);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $mapping = $input['mapping'] ?? [];
        
        if (empty($mapping)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "El mapeo de columnas es requerido."]);
            exit;
        }
        
        $mappingJson = json_encode($mapping);
        $mappingBase64 = base64_encode($mappingJson);
        
        // Ejecutar motor de procesamiento Python
        $fileEsc = escapeshellarg($filepath);
        $mappingEsc = escapeshellarg($mappingBase64);
        $pythonCmd = 'python "' . __DIR__ . '/../etl_executor.py" --file=' . $fileEsc . ' --mapping=' . $mappingEsc . ' 2>&1';
        $output = shell_exec($pythonCmd);
        
        $res = json_decode($output, true);
        if (empty($res) || !$res['exito']) {
            http_response_code(500);
            echo json_encode([
                "exito" => false, 
                "mensaje" => $res['mensaje'] ?? "Error al procesar el archivo con Python.",
                "raw_output" => $output
            ]);
            exit;
        }
        
        $datos = $res['datos'];
        $cargados = 0;
        $omitidos = 0;
        $errores_detalles = [];
        
        // Iniciar transacción de inserción masiva
        $conn->begin_transaction();
        
        // Obtener último número de lista
        $maxRes = $conn->query("SELECT MAX(numero_lista) AS max_num FROM inscritos FOR UPDATE");
        $maxRow = $maxRes->fetch_assoc();
        $numero_lista = intval($maxRow['max_num'] ?? 0);
        
        foreach ($datos as $row) {
            $cedula = $row['cedula_formateada'];
            $cedulaClean = $row['cedula_clean'];
            
            // 1. Validar cédula (Luhn)
            if (!$row['luhn_valido']) {
                $omitidos++;
                $errores_detalles[] = "Línea Cédula Inválida: {$row['cedula']}";
                continue;
            }
            
            // 2. Comprobar duplicado en la base de datos
            $cedEsc = $conn->real_escape_string($cedula);
            $dupRes = $conn->query("SELECT id FROM inscritos WHERE cedula = '$cedEsc' LIMIT 1");
            if ($dupRes && $dupRes->num_rows > 0) {
                $omitidos++;
                $errores_detalles[] = "Cédula Duplicada omitida: $cedula";
                continue;
            }
            
            // 3. Obtener nombres y apellidos
            $nombres = $row['nombres'];
            $apellidos = $row['apellidos'];
            if (empty($nombres)) {
                $omitidos++;
                $errores_detalles[] = "Línea sin nombre omitida.";
                continue;
            }
            
            // 4. Mapear colegio, recinto, region, zona
            $colegio = $row['colegio_electoral'];
            $recinto = $row['recinto_ubicacion'];
            $sector = $row['sector'];
            $municipio = $row['municipio'];
            
            $region = 'N/A';
            $zona = 'N/A';
            
            // Realizar lookup en padrón 2024 para complementar
            if (!empty($colegio)) {
                $colEsc = $conn->real_escape_string($colegio);
                $resCol = $conn->query("SELECT recinto, region, zona FROM colegios_estructural WHERE colegio = '$colEsc' LIMIT 1");
                if ($resCol && $resCol->num_rows > 0) {
                    $rowCol = $resCol->fetch_assoc();
                    if (empty($recinto)) $recinto = $rowCol['recinto'];
                    $region = $rowCol['region'];
                    $zona = $rowCol['zona'];
                    
                    // Mapear municipio si viene vacío
                    if (empty($municipio)) {
                        $regionUpper = strtoupper($region);
                        if (str_contains($regionUpper, 'BOCA CHICA')) $municipio = 'Boca Chica';
                        else if (str_contains($regionUpper, 'CALETA')) $municipio = 'Boca Chica (La Caleta)';
                        else if (str_contains($regionUpper, 'GUERRA')) $municipio = 'San Antonio de Guerra';
                        else if (str_contains($regionUpper, 'SAN LUIS')) $municipio = 'San Luis';
                        else $municipio = 'Santo Domingo Este';
                    }
                }
            }
            
            if (empty($recinto)) $recinto = "Recinto Electoral Colegio $colegio, Santo Domingo Este";
            if (empty($sector)) $sector = "Sector General";
            if (empty($municipio)) $municipio = "Santo Domingo Este";
            
            $direccion = "Sector: $sector, Municipio: $municipio";
            $telefono = !empty($row['telefono_clean']) ? $row['telefono_clean'] : '8090000000';
            $email = $telefono . "@plataforma.com";
            
            $coordinador = !empty($row['coordinador']) ? $row['coordinador'] : 'Campaña General';
            
            // Incrementar contador de lista
            $numero_lista++;
            
            // Escapar e insertar
            $nomEsc = $conn->real_escape_string($nombres);
            $apeEsc = $conn->real_escape_string($apellidos);
            $colEsc = $conn->real_escape_string($colegio);
            $recEsc = $conn->real_escape_string($recinto);
            $dirEsc = $conn->real_escape_string($direccion);
            $secEsc = $conn->real_escape_string($sector);
            $munEsc = $conn->real_escape_string($municipio);
            $regEsc = $conn->real_escape_string($region);
            $zonEsc = $conn->real_escape_string($zona);
            $telEsc = $conn->real_escape_string($telefono);
            $emEsc = $conn->real_escape_string($email);
            $cooEsc = $conn->real_escape_string($coordinador);
            
            $sql = "INSERT INTO inscritos (numero_lista, cedula, nombres, apellidos, colegio_electoral, recinto_ubicacion, direccion, sector, municipio, region, zona, telefono, email, coordinador, centro_acopio, canal_origen, periodo) 
                    VALUES ($numero_lista, '$cedEsc', '$nomEsc', '$apeEsc', '$colEsc', '$recEsc', '$dirEsc', '$secEsc', '$munEsc', '$regEsc', '$zonEsc', '$telEsc', '$emEsc', '$cooEsc', 'Excel ETL', 'Manual', '2028')";
            
            if ($conn->query($sql)) {
                $cargados++;
            } else {
                $numero_lista--; // Revertir conteo
                $omitidos++;
                $errores_detalles[] = "Error SQL en fila: " . $conn->error;
            }
        }
        
        $conn->commit();
        
        // Registrar en historial_etl
        $errores_str = implode("\n", array_slice($errores_detalles, 0, 100)); // Limitar a los primeros 100 errores para no saturar campo
        if (count($errores_detalles) > 100) {
            $errores_str .= "\n... y " . (count($errores_detalles) - 100) . " errores más.";
        }
        
        $fileEsc = $conn->real_escape_string($filename);
        $errEsc = $conn->real_escape_string($errores_str);
        $userId = intval($_SESSION['usuario_id']);
        
        $conn->query("INSERT INTO historial_etl (nombre_archivo, registros_cargados, registros_omitidos, detalles_errores, usuario_id) 
                      VALUES ('$fileEsc', $cargados, $omitidos, '$errEsc', $userId)");
        
        // Registrar auditoría general
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detallesAudit = "Carga masiva ETL ejecutada desde archivo '$filename'. Cargados: $cargados, Omitidos: $omitidos.";
        $conn->query("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) 
                      VALUES ($userId, 'ETL_IMPORT', '$detallesAudit', '$ip')");
                      
        // Limpiar archivo temporal
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        unset($_SESSION['etl_filepath']);
        unset($_SESSION['etl_filename']);
        
        echo json_encode([
            "exito" => true,
            "mensaje" => "Importación finalizada con éxito.",
            "cargados" => $cargados,
            "omitidos" => $omitidos,
            "errores" => count($errores_detalles)
        ]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
?>
