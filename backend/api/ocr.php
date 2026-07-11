<?php
/**
 * API: Procesamiento OCR de Cédula Dominicana
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ValidadorDocumentos.php';

// Permitir acceso tanto a usuarios logueados como a auto-inscripción pública sin sesión obligatoria
$db = Database::getInstance();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
    exit;
}

try {
    if (empty($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("No se recibió ninguna imagen válida.");
    }
    
    $file = $_FILES['imagen'];
    
    // Validar formato
    $allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        throw new Exception("Formato de imagen no permitido. Use PNG, JPG o WEBP.");
    }
    
    // Codificar imagen en Base64
    $base64 = base64_encode(file_get_contents($file['tmp_name']));
    
    // Consumir Google Vision API
    $apiKey = GOOGLE_VISION_API_KEY;
    $url = "https://vision.googleapis.com/v1/images:annotate?key=" . $apiKey;
    
    $payload = json_encode([
        "requests" => [
            [
                "image" => [
                    "content" => $base64
                ],
                "features" => [
                    [
                        "type" => "TEXT_DETECTION"
                    ]
                ]
            ]
        ]
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error al conectar con Google Vision API: " . $err);
    }
    curl_close($ch);
    
    $resData = json_decode($response, true);
    
    // Verificar errores de la API
    if (isset($resData['error']['message'])) {
        throw new Exception("Error de Google Vision API: " . $resData['error']['message']);
    }
    if (isset($resData['responses'][0]['error']['message'])) {
        throw new Exception("Error al procesar la imagen: " . $resData['responses'][0]['error']['message']);
    }
    
    $fullText = $resData['responses'][0]['fullTextAnnotation']['text'] ?? '';
    
    if (empty($fullText)) {
        throw new Exception("No se pudo detectar texto en la imagen. Intente con una foto más nítida.");
    }
    
    // Guardar para auditoría / depuración local
    @file_put_contents(__DIR__ . '/../logs/ocr_last_raw.txt', $fullText);
    
    // ─── PARSEO DE DATOS DE LA CÉDULA ───
    $resultado = [
        "cedula" => "",
        "nombres" => "",
        "apellidos" => "",
        "nacionalidad" => "DOMINICANA",
        "colegio_electoral" => "",
        "recinto_ubicacion" => "",
        "direccion" => "",
        "sector" => "",
        "municipio" => ""
    ];
    
    $lines = explode("\n", $fullText);
    $linesClean = array_values(array_filter(array_map('trim', $lines)));
    
    // 1. EXTRAER CÉDULA: formato 000-0000000-0 o 11 dígitos juntos
    if (preg_match('/\b(\d{3})-?(\d{7})-?(\d{1})\b/', $fullText, $matches)) {
        $resultado['cedula'] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }
    
    // 2. DETECTAR SI ES REVERSO POR ZONA MRZ (Machine Readable Zone)
    $mrzLine3 = null;
    $mrzLine1 = null;
    for ($i = 0; $i < count($linesClean); $i++) {
        $l = $linesClean[$i];
        if (str_starts_with($l, 'IDDOM')) {
            $mrzLine1 = $l;
        }
        // La línea 3 suele contener los apellidos y nombres con formato <
        if (preg_match('/^[A-Z<]+<<[A-Z<]+$/', $l)) {
            $mrzLine3 = $l;
        }
    }
    
    if ($mrzLine3) {
        // Parsear nombres y apellidos desde el bloque MRZ
        // Ejemplo: HENRIQUEZ<MARTE<<JOSE<ANDERSON
        $parts = explode('<<', $mrzLine3);
        if (count($parts) === 2) {
            $resultado['apellidos'] = trim(str_replace('<', ' ', $parts[0]));
            $resultado['nombres'] = trim(str_replace('<', ' ', $parts[1]));
        }
        
        if ($mrzLine1 && empty($resultado['cedula'])) {
            // Extraer cédula de la línea 1 del MRZ (últimos 11 dígitos)
            // Ejemplo: IDDOMCV0113616<87<00114236425<
            $cleanLine = preg_replace('/[^0-9]/', '', $mrzLine1);
            if (strlen($cleanLine) >= 11) {
                $rawCed = substr($cleanLine, -11);
                $resultado['cedula'] = substr($rawCed, 0, 3) . '-' . substr($rawCed, 3, 7) . '-' . substr($rawCed, 10, 1);
            }
        }
    }
    
    // 3. PARSEO HEURÍSTICO POR ETIQUETAS (FRONTAL)
    for ($i = 0; $i < count($linesClean); $i++) {
        $line = $linesClean[$i];
        
        // Nombres
        if (preg_match('/^Nombre(s)?$/i', $line) && isset($linesClean[$i+1]) && empty($resultado['nombres'])) {
            $val = preg_replace('/[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s]/', '', $linesClean[$i+1]);
            $resultado['nombres'] = trim($val);
        }
        // Apellidos
        if (preg_match('/^Apellido(s)?$/i', $line) && isset($linesClean[$i+1]) && empty($resultado['apellidos'])) {
            // A veces el apellido puede ocupar dos líneas (ej: HENRIQUEZ y abajo MARTE)
            $val = preg_replace('/[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s]/', '', $linesClean[$i+1]);
            $resultado['apellidos'] = trim($val);
            if (isset($linesClean[$i+2]) && !preg_match('/(Nacionalidad|Fecha|Estado|Sexo)/i', $linesClean[$i+2]) && strlen($linesClean[$i+2]) > 2) {
                $val2 = preg_replace('/[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s]/', '', $linesClean[$i+2]);
                $resultado['apellidos'] .= ' ' . trim($val2);
            }
        }
        // Nacionalidad
        if (preg_match('/^Nacionalidad$/i', $line) && isset($linesClean[$i+1])) {
            $resultado['nacionalidad'] = trim(preg_replace('/[^a-zA-Z\s]/', '', $linesClean[$i+1]));
        }
    }
    
    // 4. PARSEO HEURÍSTICO POR ETIQUETAS (REVERSO)
    for ($i = 0; $i < count($linesClean); $i++) {
        $line = $linesClean[$i];
        
        // Colegio electoral
        if (preg_match('/Colegio\s+electoral/i', $line)) {
            if (preg_match('/\b(\d{4}[A-Z]?)\b/', $line, $colMatches)) {
                $resultado['colegio_electoral'] = $colMatches[1];
            } elseif (isset($linesClean[$i+1]) && preg_match('/\b(\d{4}[A-Z]?)\b/', $linesClean[$i+1], $colMatches)) {
                $resultado['colegio_electoral'] = $colMatches[1];
            }
        }
        
        // Recinto electoral / Ubicación del colegio
        if (preg_match('/Ubicación\s+del\s+colegio|Recinto\s+electoral/i', $line)) {
            $recintoLines = [];
            for ($j = $i + 1; $j < $i + 4; $j++) {
                if (isset($linesClean[$j])) {
                    if (preg_match('/Dirección|Direccion|Sector|Municipio|Colegio/i', $linesClean[$j])) {
                        break;
                    }
                    $recintoLines[] = $linesClean[$j];
                }
            }
            $resultado['recinto_ubicacion'] = implode(', ', $recintoLines);
        }
        
        // Dirección de residencia
        if (preg_match('/Dirección\s+de\s+residencia|Direccion\s+de\s+residencia/i', $line)) {
            $dirLines = [];
            for ($j = $i + 1; $j < $i + 3; $j++) {
                if (isset($linesClean[$j])) {
                    if (preg_match('/Sector|Municipio|IDDOM/i', $linesClean[$j])) {
                        break;
                    }
                    $dirLines[] = $linesClean[$j];
                }
            }
            $resultado['direccion'] = implode(' ', $dirLines);
        }
        
        // Sector
        if (preg_match('/^Sector$/i', $line) && isset($linesClean[$i+1])) {
            $resultado['sector'] = trim($linesClean[$i+1]);
        }
        
        // Municipio
        if (preg_match('/^Municipio$/i', $line) && isset($linesClean[$i+1])) {
            $resultado['municipio'] = trim($linesClean[$i+1]);
        }
    }
    
    // Normalizar si quedan campos vacíos pero se capturó algún texto parcial
    if (empty($resultado['colegio_electoral'])) {
        // Buscar código de colegio de 4 dígitos (comúnmente 1xxx, 2xxx, etc.)
        if (preg_match('/\b(1\d{3}|2\d{3}|0\d{3})\b/', $fullText, $m)) {
            $resultado['colegio_electoral'] = $m[1];
        }
    }
    
    // Validar cédula detectada
    $resultado['cedula_valida'] = false;
    if (!empty($resultado['cedula'])) {
        $resultado['cedula_valida'] = ValidadorDocumentos::validarCedula($resultado['cedula']);
    }
    
    // Loguear en auditoría
    $userId = isset($_SESSION['usuario_id']) ? intval($_SESSION['usuario_id']) : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $detalles = "Ejecutado OCR de Cédula. Cédula extraída: " . $resultado['cedula'] . " (Válida: " . ($resultado['cedula_valida'] ? 'SÍ' : 'NO') . ")";
    
    if ($userId !== null) {
        $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'OCR_SCAN', ?, ?)");
        $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
        $stmtAudit->execute();
        $stmtAudit->close();
    } else {
        $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (NULL, 'OCR_SCAN_PUBLIC', ?, ?)");
        $stmtAudit->bind_param("ss", $detalles, $ip);
        $stmtAudit->execute();
        $stmtAudit->close();
    }
    
    echo json_encode([
        "exito" => true,
        "datos" => $resultado
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "exito" => false,
        "mensaje" => $e->getMessage()
    ]);
}
?>
