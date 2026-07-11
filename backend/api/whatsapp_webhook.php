<?php
/**
 * Webhook de WhatsApp: Enrutador de Chat y Bot de Inscripción
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ValidadorDocumentos.php';
require_once __DIR__ . '/../Mailer.php';

// Aceptar solicitudes POST (simuladas o reales)
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(["exito" => false, "mensaje" => "Método no permitido. Se requiere POST."]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Soporte para múltiples formatos de payload (Twilio / Meta API o Personalizado)
$telefono = trim($input['From'] ?? $input['telefono'] ?? '');
$mensaje = trim($input['Body'] ?? $input['mensaje'] ?? '');
$nombre = trim($input['ProfileName'] ?? $input['nombre'] ?? 'Usuario WhatsApp');

// Limpiar teléfono (quitar prefijo whatsapp: o caracteres no numéricos)
$telefono = str_replace('whatsapp:', '', $telefono);
$telefono = preg_replace('/\D/', '', $telefono);

if (empty($telefono) || empty($mensaje)) {
    http_response_code(400);
    echo json_encode(["exito" => false, "mensaje" => "Teléfono y mensaje son requeridos."]);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$telEsc = $conn->real_escape_string($telefono);
$msgEsc = $conn->real_escape_string($mensaje);
$nomEsc = $conn->real_escape_string($nombre);

// Función para enviar respuesta de WhatsApp simulada
function responderWhatsApp($to, $text) {
    $logFile = __DIR__ . '/../logs/whatsapp_api_calls.log';
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }
    $date = date('Y-m-d H:i:s');
    $apiLogLine = "[{$date}] [API CALL SEND BOT] Para: {$to} | Mensaje: {$text} | Estado: 200 OK\n";
    file_put_contents($logFile, $apiLogLine, FILE_APPEND);
}

function esSectorCirc3($conn, $sectorNombre) {
    if (empty($sectorNombre)) return false;
    $secEsc = $conn->real_escape_string($sectorNombre);
    $res = $conn->query("SELECT id FROM sectores_circ3 WHERE nombre LIKE '%$secEsc%' OR '$secEsc' LIKE CONCAT('%', nombre, '%') LIMIT 1");
    return ($res && $res->num_rows > 0);
}

function intentarParsearBloqueCompleto($texto) {
    global $conn;
    // Reemplazar saltos de línea por espacios para procesar como línea única
    $textoClean = preg_replace('/[\r\n]+/', ' ', $texto);
    
    // 1. EXTRAER CÉDULA
    $cedula = '';
    if (preg_match('/(?:^|\D)(\d{3}-\d{7}-\d{1}|\d{11})(?:\D|$)/', $textoClean, $matches)) {
        $cedula = preg_replace('/\D/', '', $matches[0]);
    }
    if (empty($cedula)) {
        return null; // Si no hay cédula, no es un registro
    }
    $cedulaFormateada = substr($cedula, 0, 3) . '-' . substr($cedula, 3, 7) . '-' . substr($cedula, 10, 1);
    
    // Remover la cédula del texto para facilitar búsquedas subsecuentes
    $textoClean = str_replace($matches[0], ' ', $textoClean);
    
    // 2. EXTRAER TELÉFONO
    $telefono = '';
    if (preg_match('/(?:^|\D)((?:809|829|849)-?\d{3}-?\d{4})(?:\D|$)/', $textoClean, $matches)) {
        $telefono = preg_replace('/\D/', '', $matches[0]);
        $textoClean = str_replace($matches[0], ' ', $textoClean);
    }
    
    // 3. EXTRAER COLEGIO ELECTORAL (Priorizar etiquetas como "colegio electoral: 1469" o "colegio: 1469")
    $colegio = '9999';
    if (preg_match('/(?:COLEGIO ELECTORAL|COLEGIO)\s*[:=]?\s*(\d{3,4})/ui', $textoClean, $matches)) {
        $colegio = trim($matches[1]);
        $textoClean = str_replace($matches[0], ' ', $textoClean);
    } else if (preg_match('/(?:^|\D)(\d{3,4})(?:\D|$)/', $textoClean, $matches)) {
        $colegio = trim($matches[1]);
        $textoClean = str_replace($matches[0], ' ', $textoClean);
    }
    
    // 4. BÚSQUEDA DE ETIQUETAS EXPLÍCITAS (si existen en el texto original)
    $keywords = [
        'NOMBRE' => ['NOMBRE:', 'NOMBRES:'],
        'RECINTO' => ['LUGAR DE VOTACIÓN:', 'LUGAR DE VOTACION:', 'RECINTO:', 'UBICACION:', 'LUGAR:'],
        'COORDINADOR' => ['COORDINADOR:', 'INVITADO POR:']
    ];
    
    $extracted = [];
    foreach ($keywords as $key => $patterns) {
        foreach ($patterns as $pattern) {
            $regexPattern = '/' . preg_quote($pattern, '/') . '\s*([^:]+?)(?=(?:' . implode('|', array_map(function($p) { return preg_quote($p, '/'); }, array_merge(...array_values($keywords)))) . ')|$)/ui';
            if (preg_match($regexPattern, $texto, $matches)) {
                $val = trim($matches[1]);
                if (!empty($val)) {
                    $extracted[$key] = $val;
                    break;
                }
            }
        }
    }
    
    $nombre = isset($extracted['NOMBRE']) ? $extracted['NOMBRE'] : '';
    $recinto = isset($extracted['RECINTO']) ? $extracted['RECINTO'] : '';
    $coordinador = isset($extracted['COORDINADOR']) ? $extracted['COORDINADOR'] : '';
    
    // 5. PARSEAR TEXTO RESTANTE SIN ETIQUETAS EXPLÍCITAS (FLEXIBLE)
    if (empty($nombre) || empty($recinto) || empty($coordinador)) {
        // Remover palabras clave del texto restante para no confundir
        $cleanRemaining = preg_replace('/(NOMBRE|NOMBRES|CÉDULA|CEDULA|TELEFONO|TELÉFONO|RECINTO|LUGAR|COLEGIO|COORDINADOR):/ui', ' ', $textoClean);
        $cleanRemaining = preg_replace('/\s+/', ' ', $cleanRemaining);
        $cleanRemaining = trim($cleanRemaining);
        
        // Encontrar el recinto usando palabras clave
        $recintoKeywords = ['ESC\.', 'ESCUELA', 'LICEO', 'COLEGIO', 'CLUB', 'MULTIUSO', 'IGLESIA', 'CENTRO', 'POLIDEPORTIVO', 'PARQUE', 'AYUNTAMIENTO', 'AULA', 'ESCT\.', 'ESCT'];
        $foundKeyword = '';
        foreach ($recintoKeywords as $kw) {
            $kwPattern = str_replace('\.', '.', $kw);
            if (stripos($cleanRemaining, $kwPattern) !== false) {
                $foundKeyword = $kwPattern;
                break;
            }
        }
        
        if (!empty($foundKeyword)) {
            $pos = stripos($cleanRemaining, $foundKeyword);
            $nombrePart = substr($cleanRemaining, 0, $pos);
            $restPart = substr($cleanRemaining, $pos);
            
            if (empty($nombre)) $nombre = trim($nombrePart);
            
            $restTokens = explode(' ', preg_replace('/\s+/', ' ', trim($restPart)));
            if (count($restTokens) >= 3) {
                $coordinadorVal = implode(' ', array_slice($restTokens, -2));
                $recintoVal = implode(' ', array_slice($restTokens, 0, count($restTokens) - 2));
                if (empty($coordinador)) $coordinador = $coordinadorVal;
                if (empty($recinto)) $recinto = $recintoVal;
            } else {
                if (empty($recinto)) $recinto = implode(' ', $restTokens);
                if (empty($coordinador)) $coordinador = 'Campaña General';
            }
        } else {
            $tokens = explode(' ', preg_replace('/\s+/', ' ', trim($cleanRemaining)));
            if (count($tokens) >= 4) {
                if (empty($nombre)) $nombre = $tokens[0] . ' ' . $tokens[1];
                if (empty($coordinador)) $coordinador = $tokens[count($tokens) - 2] . ' ' . $tokens[count($tokens) - 1];
                if (count($tokens) > 4 && empty($recinto)) {
                    $recinto = implode(' ', array_slice($tokens, 2, count($tokens) - 2));
                }
            } else {
                if (empty($nombre)) $nombre = implode(' ', $tokens);
                if (empty($coordinador)) $coordinador = 'Campaña General';
            }
        }
    }
    
    $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
    $recinto = trim(preg_replace('/\s+/', ' ', $recinto));
    $coordinador = trim(preg_replace('/\s+/', ' ', $coordinador));
    
    // Si no pudimos extraer al menos el nombre y la cédula, descartamos
    if (empty($nombre) || empty($cedula)) {
        return null;
    }
    
    // Separar Nombres y Apellidos
    $partesNombre = explode(' ', $nombre);
    if (count($partesNombre) === 1) {
        $nombres = ucwords(strtolower($partesNombre[0]));
        $apellidos = 'N/A';
    } else if (count($partesNombre) === 2) {
        $nombres = ucwords(strtolower($partesNombre[0]));
        $apellidos = ucwords(strtolower($partesNombre[1]));
    } else if (count($partesNombre) === 3) {
        $nombres = ucwords(strtolower($partesNombre[0] . ' ' . $partesNombre[1]));
        $apellidos = ucwords(strtolower($partesNombre[2]));
    } else {
        $nombres = ucwords(strtolower($partesNombre[0] . ' ' . $partesNombre[1]));
        $apellidos = ucwords(strtolower(implode(' ', array_slice($partesNombre, 2))));
    }
    
    // Buscar en la base de datos de Colegios Electorales 2024 para traer Recinto, Región, Zona y Municipio
    $region = 'N/A';
    $zona = 'N/A';
    $municipio = 'Santo Domingo Este';
    
    $colEsc = $conn->real_escape_string($colegio);
    $resCol = $conn->query("SELECT recinto, region, zona FROM colegios_estructural WHERE colegio = '$colEsc' LIMIT 1");
    if ($resCol && $resCol->num_rows > 0) {
        $rowCol = $resCol->fetch_assoc();
        $recinto = $rowCol['recinto'];
        $region = $rowCol['region'];
        $zona = $rowCol['zona'];
        
        $regionUpper = strtoupper($region);
        if (str_contains($regionUpper, 'BOCA CHICA')) {
            $municipio = 'Boca Chica';
        } else if (str_contains($regionUpper, 'CALETA')) {
            $municipio = 'Boca Chica (La Caleta)';
        } else if (str_contains($regionUpper, 'GUERRA')) {
            $municipio = 'San Antonio de Guerra';
        } else if (str_contains($regionUpper, 'SAN LUIS')) {
            $municipio = 'San Luis';
        }
    }
    
    $sector = 'Sector General';
    
    // Extraer sector/municipio si se mencionan palabras clave de demarcaciones conocidas
    $resSectores = ['BOCA CHICA', 'LA CALETA', 'GUERRA', 'VALIENTE', 'CAMPO LINDO', 'TAMARINDO', 'HAINAMOSA', 'INVIVIENDA', 'SAN LUIS', 'EL BONITO', 'MENDOZA', 'INVIMOSA'];
    foreach ($resSectores as $rs) {
        if (stripos($texto, $rs) !== false) {
            $sector = ucwords(strtolower($rs));
            if (empty($region) || $region === 'N/A') {
                if (in_array($rs, ['BOCA CHICA', 'LA CALETA'])) {
                    $municipio = 'Boca Chica';
                } else if (in_array($rs, ['GUERRA', 'EL TORO', 'ESTORGA'])) {
                    $municipio = 'San Antonio de Guerra';
                } else {
                    $municipio = 'Santo Domingo Este';
                }
            }
            break;
        }
    }
    
    return [
        'cedula' => $cedulaFormateada,
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'colegio_electoral' => $colegio,
        'recinto_ubicacion' => empty($recinto) ? ('Recinto Electoral Colegio ' . $colegio . ', Santo Domingo Este') : $recinto,
        'coordinador' => empty($coordinador) ? 'Campaña General' : $coordinador,
        'sector' => $sector,
        'municipio' => $municipio,
        'region' => $region,
        'zona' => $zona,
        'direccion' => "Sector: " . $sector . ", Municipio: " . $municipio
    ];
}

// 1. REGISTRAR EL MENSAJE EN LA BITÁCORA DEL CHAT
$sqlInsertChat = "INSERT INTO chat_mensajes (coordinador_telefono, coordinador_nombre, direccion, mensaje, leido) 
                  VALUES ('$telEsc', '$nomEsc', 'entrante', '$msgEsc', 0)";
$conn->query($sqlInsertChat);

// 2. DETECTAR SI EL USUARIO QUIERE SALIR DEL BOT O ENTRAR AL CHAT HUMANO
if (strtolower($mensaje) === 'humano' || strtolower($mensaje) === 'chat' || strtolower($mensaje) === 'coordinador') {
    $conn->query("DELETE FROM bot_sesiones WHERE telefono = '$telEsc'");
    $reply = "¡Hola! He suspendido el bot de inscripción. Tu mensaje ha sido enviado a los digitadores de nuestro centro de acopio. En breve se comunicarán contigo por aquí. ¡Gracias!";
    responderWhatsApp($telefono, $reply);
    
    // Guardar respuesta del bot en la base de datos
    $replyEsc = $conn->real_escape_string($reply);
    $conn->query("INSERT INTO chat_mensajes (coordinador_telefono, coordinador_nombre, direccion, mensaje, leido) VALUES ('$telEsc', '$nomEsc', 'saliente', '$replyEsc', 1)");
    
    echo json_encode(["exito" => true, "mensaje" => "Transferido a chat humano."]);
    exit;
}

// Detectar si el mensaje contiene un bloque de padrón completo
$datosBloque = intentarParsearBloqueCompleto($mensaje);

if ($datosBloque !== null) {
    $cedulaClean = preg_replace('/\D/', '', $datosBloque['cedula']);
    if (!ValidadorDocumentos::validarCedula($cedulaClean)) {
        $reply = "Lo siento, la cédula importada " . $datosBloque['cedula'] . " es inválida según el algoritmo Luhn. Por favor, verifica los datos del padrón.";
        responderWhatsApp($telefono, $reply);
        $replyEsc = $conn->real_escape_string($reply);
        $conn->query("INSERT INTO chat_mensajes (coordinador_telefono, coordinador_nombre, direccion, mensaje, leido) VALUES ('$telEsc', '$nomEsc', 'saliente', '$replyEsc', 1)");
        echo json_encode(["exito" => true]);
        exit;
    }
    
    $cedEsc = $conn->real_escape_string($datosBloque['cedula']);
    $resDup = $conn->query("SELECT coordinador FROM inscritos WHERE cedula = '$cedEsc' LIMIT 1");
    if ($resDup && $resDup->num_rows > 0) {
        $dup = $resDup->fetch_assoc();
        $reply = "La cédula " . $datosBloque['cedula'] . " ya está registrada en la plataforma y fue suministrada por el coordinador \"" . $dup['coordinador'] . "\". No se permiten registros duplicados.";
        $conn->query("DELETE FROM bot_sesiones WHERE telefono = '$telEsc'");
        responderWhatsApp($telefono, $reply);
        $replyEsc = $conn->real_escape_string($reply);
        $conn->query("INSERT INTO chat_mensajes (coordinador_telefono, coordinador_nombre, direccion, mensaje, leido) VALUES ('$telEsc', '$nomEsc', 'saliente', '$replyEsc', 1)");
        echo json_encode(["exito" => true]);
        exit;
    }
    
    $tempData = [
        'cedula' => $datosBloque['cedula'],
        'nombres' => $datosBloque['nombres'],
        'apellidos' => $datosBloque['apellidos'],
        'colegio_electoral' => $datosBloque['colegio_electoral'],
        'recinto_ubicacion' => $datosBloque['recinto_ubicacion'],
        'sector' => $datosBloque['sector'],
        'municipio' => $datosBloque['municipio'],
        'region' => $datosBloque['region'],
        'zona' => $datosBloque['zona'],
        'direccion' => $datosBloque['direccion'],
        'coordinador' => $datosBloque['coordinador'],
        'telefono' => $telefono,
        'email' => $telefono . "@whatsapp.com"
    ];
    
    $jsonTemp = $conn->real_escape_string(json_encode($tempData));
    $conn->query("DELETE FROM bot_sesiones WHERE telefono = '$telEsc'");
    $conn->query("INSERT INTO bot_sesiones (telefono, paso, temp_data) VALUES ('$telEsc', 7, '$jsonTemp')");
    
    $isCirc3 = esSectorCirc3($conn, $tempData['sector']);
    $sectorStatus = $isCirc3 ? " (✓ Validado Circ. 3)" : " (⚠ Advertencia: Sector fuera de Circ. 3)";
    
    $reply = "¡Excelente! He detectado e importado todos los datos del padrón de forma automática:\n\n" .
             "=== CONFIRME SUS DATOS ===\n" .
             "• Cédula: " . $tempData['cedula'] . "\n" .
             "• Nombre: " . $tempData['nombres'] . " " . $tempData['apellidos'] . "\n" .
             "• Colegio: " . $tempData['colegio_electoral'] . "\n" .
             "• Recinto: " . $tempData['recinto_ubicacion'] . "\n" .
             "• Región: " . ($tempData['region'] ?? 'N/A') . "\n" .
             "• Zona: " . ($tempData['zona'] ?? 'N/A') . "\n" .
             "• Dirección: " . $tempData['sector'] . $sectorStatus . ", " . $tempData['municipio'] . "\n" .
             "• Coordinador: " . $tempData['coordinador'] . "\n\n" .
             "Escriba **'SI'** para confirmar su registro, o **'NO'** para cancelar.";
             
    responderWhatsApp($telefono, $reply);
    $replyEsc = $conn->real_escape_string($reply);
    $conn->query("INSERT INTO chat_mensajes (coordinador_telefono, coordinador_nombre, direccion, mensaje, leido) VALUES ('$telEsc', '$nomEsc', 'saliente', '$replyEsc', 1)");
    echo json_encode(["exito" => true]);
    exit;
}

// 3. CONSULTAR SESIÓN ACTIVA DEL BOT
$resSession = $conn->query("SELECT * FROM bot_sesiones WHERE telefono = '$telEsc' LIMIT 1");

if ($resSession && $resSession->num_rows > 0) {
    // Sesión activa: procesar estado
    $session = $resSession->fetch_assoc();
    $paso = intval($session['paso']);
    $tempData = json_decode($session['temp_data'] ?? '{}', true);
    
    $reply = "";
    $nextStep = $paso;
    
    switch ($paso) {
        case 1: // Esperando Cédula
            $cedulaClean = preg_replace('/\D/', '', $mensaje);
            
            // Validar algoritmo Luhn
            if (!ValidadorDocumentos::validarCedula($cedulaClean)) {
                $reply = "Lo siento, la cédula ingresada es inválida según el algoritmo electoral Luhn. Por favor, escribe los 11 dígitos de tu cédula correctamente.";
            } else {
                $cedFormateada = substr($cedulaClean, 0, 3) . '-' . substr($cedulaClean, 3, 7) . '-' . substr($cedulaClean, 10, 1);
                
                // Verificar duplicados
                $cedEsc = $conn->real_escape_string($cedFormateada);
                $resDup = $conn->query("SELECT coordinador FROM inscritos WHERE cedula = '$cedEsc' LIMIT 1");
                
                if ($resDup && $resDup->num_rows > 0) {
                    $dup = $resDup->fetch_assoc();
                    $reply = "La cédula $cedFormateada ya está registrada en la plataforma y fue suministrada por el coordinador \"" . $dup['coordinador'] . "\". No se permiten registros duplicados.";
                    // Cerrar sesión
                    $conn->query("DELETE FROM bot_sesiones WHERE telefono = '$telEsc'");
                } else {
                    $tempData['cedula'] = $cedFormateada;
                    $reply = "Cédula $cedFormateada verificada con éxito.\n\nPor favor, escribe tus **Nombres** (tal como aparecen en tu cédula).";
                    $nextStep = 2;
                }
            }
            break;
            
        case 2: // Esperando Nombres
            $tempData['nombres'] = ucwords(strtolower($mensaje));
            $reply = "Perfecto, ahora escribe tus **Apellidos**.";
            $nextStep = 3;
            break;
            
        case 3: // Esperando Apellidos
            $tempData['apellidos'] = ucwords(strtolower($mensaje));
            $reply = "Recibido. Por favor, escribe tu **Colegio Electoral** (número de 4 dígitos, por ejemplo: 1401).";
            $nextStep = 4;
            break;
            
        case 4: // Esperando Colegio Electoral
            $colegioClean = preg_replace('/\D/', '', $mensaje);
            if (strlen($colegioClean) < 2) {
                $reply = "Por favor, escribe un número de colegio electoral válido (ejemplo: 1401).";
            } else {
                $tempData['colegio_electoral'] = $colegioClean;
                
                // Buscar en la base de datos estructural de colegios
                $colEsc = $conn->real_escape_string($colegioClean);
                $resCol = $conn->query("SELECT recinto, region, zona FROM colegios_estructural WHERE colegio = '$colEsc' LIMIT 1");
                if ($resCol && $resCol->num_rows > 0) {
                    $rowCol = $resCol->fetch_assoc();
                    $tempData['recinto_ubicacion'] = $rowCol['recinto'];
                    $tempData['region'] = $rowCol['region'];
                    $tempData['zona'] = $rowCol['zona'];
                    
                    // Mapear municipio según región
                    $regionUpper = strtoupper($rowCol['region']);
                    $municipio = 'Santo Domingo Este';
                    if (str_contains($regionUpper, 'BOCA CHICA')) {
                        $municipio = 'Boca Chica';
                    } else if (str_contains($regionUpper, 'CALETA')) {
                        $municipio = 'Boca Chica (La Caleta)';
                    } else if (str_contains($regionUpper, 'GUERRA')) {
                        $municipio = 'San Antonio de Guerra';
                    } else if (str_contains($regionUpper, 'SAN LUIS')) {
                        $municipio = 'San Luis';
                    }
                    $tempData['municipio'] = $municipio;
                    
                    $reply = "He verificado tu Colegio Electoral *$colegioClean* en la base de datos 2024:\n" .
                             "• Recinto: *" . $rowCol['recinto'] . "*\n" .
                             "• Región: *" . $rowCol['region'] . "*\n" .
                             "• Zona: *" . $rowCol['zona'] . "*\n\n" .
                             "Por favor, ingresa tu **Sector** de residencia (ejemplo: Invivienda).";
                } else {
                    $tempData['recinto_ubicacion'] = "Recinto Electoral Colegio " . $colegioClean . ", Santo Domingo Este";
                    $tempData['region'] = 'N/A';
                    $tempData['zona'] = 'N/A';
                    $reply = "Colegio Electoral registrado. Por favor, ingresa tu **Sector y Municipio** de residencia (ejemplo: El Bonito, San Luis).";
                }
                $nextStep = 5;
            }
            break;
            
        case 5: // Esperando Dirección
            if (!empty($tempData['municipio'])) {
                $tempData['sector'] = trim($mensaje);
                $tempData['direccion'] = "Sector: " . trim($mensaje) . ", Municipio: " . $tempData['municipio'];
            } else {
                $parts = explode(',', $mensaje);
                $tempData['sector'] = trim($parts[0]);
                $tempData['municipio'] = isset($parts[1]) ? trim($parts[1]) : "Santo Domingo Este";
                $tempData['direccion'] = $mensaje;
            }
            
            $reply = "Dirección guardada.\n\nEscribe el nombre del **Coordinador** que te invitó a inscribirte (si no tienes uno, escribe 'Ninguno').";
            $nextStep = 6;
            break;
            
        case 6: // Esperando Coordinador e Email
            $tempData['coordinador'] = $mensaje === 'Ninguno' ? 'Campaña General' : $mensaje;
            $tempData['telefono'] = $telefono;
            $tempData['email'] = $telefono . "@whatsapp.com"; // dummy
            
            $isCirc3 = esSectorCirc3($conn, $tempData['sector']);
            $sectorStatus = $isCirc3 ? " (✓ Validado Circ. 3)" : " (⚠ Advertencia: Sector fuera de Circ. 3)";
            
            // Mostrar resumen para confirmación final
            $reply = "=== CONFIRME SUS DATOS ===\n" .
                     "• Cédula: " . $tempData['cedula'] . "\n" .
                     "• Nombre: " . $tempData['nombres'] . " " . $tempData['apellidos'] . "\n" .
                     "• Colegio: " . $tempData['colegio_electoral'] . "\n" .
                     "• Recinto: " . $tempData['recinto_ubicacion'] . "\n" .
                     "• Región: " . ($tempData['region'] ?? 'N/A') . "\n" .
                     "• Zona: " . ($tempData['zona'] ?? 'N/A') . "\n" .
                     "• Dirección: " . $tempData['sector'] . $sectorStatus . ", " . $tempData['municipio'] . "\n" .
                     "• Coordinador: " . $tempData['coordinador'] . "\n\n" .
                     "Escriba **'SI'** para confirmar su registro, o **'NO'** para cancelar.";
            $nextStep = 7;
            break;
            
        case 7: // Confirmación final
            if (strtoupper(trim($mensaje)) === 'SI') {
                // Proceder al registro definitivo en la base de datos
                $conn->begin_transaction();
                
                // Generar número de lista seguro
                $maxRes = $conn->query("SELECT MAX(numero_lista) AS max_num FROM inscritos FOR UPDATE");
                $maxRow = $maxRes->fetch_assoc();
                $numero_lista = intval($maxRow['max_num'] ?? 0) + 1;
                
                $cedula = $tempData['cedula'];
                $nombres = $tempData['nombres'];
                $apellidos = $tempData['apellidos'];
                $colegio = $tempData['colegio_electoral'];
                $recinto = $tempData['recinto_ubicacion'];
                $direccion = $tempData['direccion'];
                $sector = $tempData['sector'];
                $municipio = $tempData['municipio'];
                $region = isset($tempData['region']) ? $tempData['region'] : 'N/A';
                $zona = isset($tempData['zona']) ? $tempData['zona'] : 'N/A';
                $telefonoV = $tempData['telefono'];
                $emailV = $tempData['email'];
                $coord = $tempData['coordinador'];
                
                $cedEsc = $conn->real_escape_string($cedula);
                $nomEsc = $conn->real_escape_string($nombres);
                $apeEsc = $conn->real_escape_string($apellidos);
                $colEsc = $conn->real_escape_string($colegio);
                $recEsc = $conn->real_escape_string($recinto);
                $dirEsc = $conn->real_escape_string($direccion);
                $secEsc = $conn->real_escape_string($sector);
                $munEsc = $conn->real_escape_string($municipio);
                $regEsc = $conn->real_escape_string($region);
                $zonEsc = $conn->real_escape_string($zona);
                $telVEsc = $conn->real_escape_string($telefonoV);
                $emVEsc = $conn->real_escape_string($emailV);
                $cooEsc = $conn->real_escape_string($coord);
                
                $sqlInsert = "INSERT INTO inscritos (numero_lista, cedula, nombres, apellidos, colegio_electoral, recinto_ubicacion, direccion, sector, municipio, region, zona, telefono, email, coordinador, centro_acopio, canal_origen) 
                              VALUES ($numero_lista, '$cedEsc', '$nomEsc', '$apeEsc', '$colEsc', '$recEsc', '$dirEsc', '$secEsc', '$munEsc', '$regEsc', '$zonEsc', '$telVEsc', '$emVEsc', '$cooEsc', 'WhatsApp Bot', 'WhatsApp Bot')";
                              
                if ($conn->query($sqlInsert)) {
                    $newVoterId = $conn->insert_id;
                    $conn->commit();
                    
                    // Enviar confirmación al WhatsApp
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    
                    $uri = $_SERVER['REQUEST_URI'] ?? '';
                    $folder = "PLATAFORMA DIGITAL-PAD-28-32";
                    if (str_contains($uri, 'PLATAFORMA%20DIGITAL-PAD-28-32')) {
                        $folder = "PLATAFORMA%20DIGITAL-PAD-28-32";
                    } else if (str_contains($uri, 'PLATAFORMA_INTEGRADA')) {
                        $folder = "PLATAFORMA_INTEGRADA";
                    }
                    
                    $linkComprobante = $protocol . $host . "/" . $folder . "/comprobante.php?id=" . $newVoterId;
                    
                    $reply = "¡Felicidades! Registro completado con éxito.\n\n• Su número de lista oficial es: *$numero_lista*\n\n🔗 Descarga tu comprobante oficial aquí:\n" . $linkComprobante . "\n\nGracias por inscribirse y apoyar a la Diputada Pastora Altagracia. ¡Juntos ganamos!";
                    
                    // Registrar auditoría
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $detalles = "Votante inscrito vía WhatsApp Bot: $nombres $apellidos ($cedula). No. Lista: $numero_lista";
                    $conn->query("INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles, ip_address) VALUES (NULL, 'INSERT_VOTER', 'inscritos', $newVoterId, '$detalles', '$ip')");
                    
                    // Borrar sesión bot
                    $conn->query("DELETE FROM bot_sesiones WHERE telefono = '$telEsc'");
                } else {
                    $conn->rollback();
                    $reply = "Hubo un problema al guardar tu registro en el sistema. Por favor, escribe 'hola' para intentarlo nuevamente.";
                    $conn->query("DELETE FROM bot_sesiones WHERE telefono = '$telEsc'");
                }
            } else if (strtoupper(trim($mensaje)) === 'NO') {
                $reply = "Registro cancelado. Escribe 'hola' si deseas iniciar la inscripción nuevamente.";
                $conn->query("DELETE FROM bot_sesiones WHERE telefono = '$telEsc'");
            } else {
                // Detectar si el usuario está intentando corregir algún dato en la confirmación
                // Formato: Campo: Valor (ej. "Sector: INVIMOSA")
                $regexCorrect = '/^(NOMBRE|NOMBRES|CÉDULA|CEDULA|TELEFONO|TELÉFONO|RECINTO|LUGAR|COLEGIO|COORDINADOR|SECTOR|MUNICIPIO)\s*[:=]\s*(.+)$/ui';
                if (preg_match($regexCorrect, trim($mensaje), $correctMatches)) {
                    $campo = strtoupper(trim($correctMatches[1]));
                    $valor = trim($correctMatches[2]);
                    
                    switch ($campo) {
                        case 'NOMBRE':
                        case 'NOMBRES':
                            $partes = explode(' ', $valor);
                            if (count($partes) === 1) {
                                $tempData['nombres'] = ucwords(strtolower($partes[0]));
                                $tempData['apellidos'] = 'N/A';
                            } else if (count($partes) === 2) {
                                $tempData['nombres'] = ucwords(strtolower($partes[0]));
                                $tempData['apellidos'] = ucwords(strtolower($partes[1]));
                            } else if (count($partes) === 3) {
                                $tempData['nombres'] = ucwords(strtolower($partes[0] . ' ' . $partes[1]));
                                $tempData['apellidos'] = ucwords(strtolower($partes[2]));
                            } else {
                                $tempData['nombres'] = ucwords(strtolower($partes[0] . ' ' . $partes[1]));
                                $tempData['apellidos'] = ucwords(strtolower(implode(' ', array_slice($partes, 2))));
                            }
                            break;
                        case 'CÉDULA':
                        case 'CEDULA':
                            $cedClean = preg_replace('/\D/', '', $valor);
                            if (ValidadorDocumentos::validarCedula($cedClean)) {
                                $tempData['cedula'] = substr($cedClean, 0, 3) . '-' . substr($cedClean, 3, 7) . '-' . substr($cedClean, 10, 1);
                            } else {
                                responderWhatsApp($telefono, "⚠ La cédula ingresada no es válida. No se aplicó el cambio.");
                                exit;
                            }
                            break;
                        case 'TELEFONO':
                        case 'TELÉFONO':
                            $tempData['telefono'] = preg_replace('/\D/', '', $valor);
                            break;
                        case 'RECINTO':
                        case 'LUGAR':
                            $tempData['recinto_ubicacion'] = $valor;
                            break;
                        case 'COLEGIO':
                            $colClean = preg_replace('/\D/', '', $valor);
                            $tempData['colegio_electoral'] = $colClean;
                            
                            // Re-validar y traer recinto, region, zona automáticamente
                            $resCol = $conn->query("SELECT recinto, region, zona FROM colegios_estructural WHERE colegio = '$colClean' LIMIT 1");
                            if ($resCol && $resCol->num_rows > 0) {
                                $rowCol = $resCol->fetch_assoc();
                                $tempData['recinto_ubicacion'] = $rowCol['recinto'];
                                $tempData['region'] = $rowCol['region'];
                                $tempData['zona'] = $rowCol['zona'];
                                
                                $regionUpper = strtoupper($rowCol['region']);
                                if (str_contains($regionUpper, 'BOCA CHICA')) {
                                    $tempData['municipio'] = 'Boca Chica';
                                } else if (str_contains($regionUpper, 'CALETA')) {
                                    $tempData['municipio'] = 'Boca Chica (La Caleta)';
                                } else if (str_contains($regionUpper, 'GUERRA')) {
                                    $tempData['municipio'] = 'San Antonio de Guerra';
                                } else if (str_contains($regionUpper, 'SAN LUIS')) {
                                    $tempData['municipio'] = 'San Luis';
                                } else {
                                    $tempData['municipio'] = 'Santo Domingo Este';
                                }
                            }
                            break;
                        case 'COORDINADOR':
                            $tempData['coordinador'] = $valor;
                            break;
                        case 'SECTOR':
                            $tempData['sector'] = $valor;
                            $tempData['direccion'] = "Sector: " . $valor . ", Municipio: " . ($tempData['municipio'] ?? 'Santo Domingo Este');
                            break;
                        case 'MUNICIPIO':
                            $tempData['municipio'] = $valor;
                            $tempData['direccion'] = "Sector: " . ($tempData['sector'] ?? 'Sector General') . ", Municipio: " . $valor;
                            break;
                    }
                    
                    // Actualizar el estado de la sesión con los datos corregidos
                    $jsonTemp = $conn->real_escape_string(json_encode($tempData));
                    $conn->query("UPDATE bot_sesiones SET temp_data = '$jsonTemp' WHERE telefono = '$telEsc'");
                    
                    $isCirc3 = esSectorCirc3($conn, $tempData['sector']);
                    $sectorStatus = $isCirc3 ? " (✓ Validado Circ. 3)" : " (⚠ Advertencia: Sector fuera de Circ. 3)";
                    
                    $reply = "✅ ¡Dato corregido con éxito!\n\n" .
                             "=== CONFIRME SUS DATOS ACTUALIZADOS ===\n" .
                             "• Cédula: " . $tempData['cedula'] . "\n" .
                             "• Nombre: " . $tempData['nombres'] . " " . $tempData['apellidos'] . "\n" .
                             "• Colegio: " . $tempData['colegio_electoral'] . "\n" .
                             "• Recinto: " . $tempData['recinto_ubicacion'] . "\n" .
                             "• Región: " . ($tempData['region'] ?? 'N/A') . "\n" .
                             "• Zona: " . ($tempData['zona'] ?? 'N/A') . "\n" .
                             "• Dirección: " . $tempData['sector'] . $sectorStatus . ", " . $tempData['municipio'] . "\n" .
                             "• Coordinador: " . $tempData['coordinador'] . "\n\n" .
                             "¿Desea corregir algún otro dato? Envíe el formato 'Campo: Valor' (ej. *Sector: Invivienda*).\n\n" .
                             "Si todo está correcto, responda **'SI'** para finalizar la inscripción, o **'NO'** para cancelar.";
                    $nextStep = 7;
                } else {
                    $reply = "Lo siento, no comprendí tu respuesta. Por favor, confirma si tus datos son correctos escribiendo **'SI'** para registrarte o **'NO'** para cancelar.\n\nSi deseas corregir algún dato, envía el formato: *Campo: Valor* (ejemplo: *Sector: Invivienda*).";
                    $nextStep = 7;
                }
            }
            break;
    }
    
    // Actualizar la sesión
    if ($nextStep !== $paso && $nextStep < 8) {
        $jsonTemp = $conn->real_escape_string(json_encode($tempData));
        $conn->query("UPDATE bot_sesiones SET paso = $nextStep, temp_data = '$jsonTemp' WHERE telefono = '$telEsc'");
    }
    
    // Responder e insertar log
    responderWhatsApp($telefono, $reply);
    $replyEsc = $conn->real_escape_string($reply);
    $conn->query("INSERT INTO chat_mensajes (coordinador_telefono, coordinador_nombre, direccion, mensaje, leido) VALUES ('$telEsc', '$nomEsc', 'saliente', '$replyEsc', 1)");
    
} else {
    // Nuevo usuario: Iniciar flujo del Bot
    // Verificar si el primer mensaje ya es una cédula válida
    $cedulaClean = preg_replace('/\D/', '', $mensaje);
    if (strlen($cedulaClean) === 11 && ValidadorDocumentos::validarCedula($cedulaClean)) {
        $cedFormateada = substr($cedulaClean, 0, 3) . '-' . substr($cedulaClean, 3, 7) . '-' . substr($cedulaClean, 10, 1);
        
        // Verificar duplicados
        $cedEsc = $conn->real_escape_string($cedFormateada);
        $resDup = $conn->query("SELECT coordinador FROM inscritos WHERE cedula = '$cedEsc' LIMIT 1");
        
        if ($resDup && $resDup->num_rows > 0) {
            $dup = $resDup->fetch_assoc();
            $reply = "¡Hola! Bienvenido. La cédula $cedFormateada ya está registrada en la plataforma y fue suministrada por el coordinador \"" . $dup['coordinador'] . "\". No se permiten registros duplicados.";
        } else {
            $tempData = ['cedula' => $cedFormateada];
            $jsonTemp = $conn->real_escape_string(json_encode($tempData));
            $conn->query("INSERT INTO bot_sesiones (telefono, paso, temp_data) VALUES ('$telEsc', 2, '$jsonTemp')");
            
            $reply = "¡Hola! Bienvenido a la plataforma electoral de la Diputada Pastora Altagracia.\n\nHe verificado tu Cédula $cedFormateada con éxito.\n\nPor favor, escribe tus **Nombres** (tal como aparecen en tu cédula).";
        }
    } else {
        $conn->query("INSERT INTO bot_sesiones (telefono, paso, temp_data) VALUES ('$telEsc', 1, '{}')");
        
        $reply = "¡Hola! Bienvenido a la plataforma PAD/28-32 de la Diputada Altagracia De Los Santos (Pastora Altagracia).\n\nSoy tu asistente virtual de inscripción. Por favor, escribe tu número de **Cédula** (11 dígitos, con o sin guiones).";
    }
    
    responderWhatsApp($telefono, $reply);
    $replyEsc = $conn->real_escape_string($reply);
    $conn->query("INSERT INTO chat_mensajes (coordinador_telefono, coordinador_nombre, direccion, mensaje, leido) VALUES ('$telEsc', '$nomEsc', 'saliente', '$replyEsc', 1)");
}

echo json_encode(["exito" => true]);
?>
