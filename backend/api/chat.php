<?php
/**
 * API: Chat en Vivo con Coordinadores (WhatsApp Externo)
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';

// Validar inicio de sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión."]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$db = Database::getInstance();
$conn = $db->getConnection();

if ($method === 'GET') {
    if ($action === 'list_chats') {
        // Listar conversaciones únicas ordenadas por el último mensaje recibido
        $sql = "SELECT c1.coordinador_telefono, c1.coordinador_nombre, c1.mensaje, c1.fecha_envio, c1.direccion,
                (SELECT COUNT(*) FROM chat_mensajes c2 WHERE c2.coordinador_telefono = c1.coordinador_telefono AND c2.leido = 0 AND c2.direccion = 'entrante') as unread_count
                FROM chat_mensajes c1
                INNER JOIN (
                    SELECT coordinador_telefono, MAX(id) as max_id
                    FROM chat_mensajes
                    GROUP BY coordinador_telefono
                ) max_table ON c1.id = max_table.max_id
                ORDER BY c1.fecha_envio DESC";
                
        $res = $conn->query($sql);
        $chats = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $chats[] = [
                    "telefono" => $row['coordinador_telefono'],
                    "nombre" => $row['coordinador_nombre'],
                    "ultimo_mensaje" => $row['mensaje'],
                    "fecha" => $row['fecha_envio'],
                    "direccion" => $row['direccion'],
                    "sin_leer" => intval($row['unread_count'])
                ];
            }
        }
        echo json_encode(["exito" => true, "chats" => $chats]);
        exit;
    }
    
    if ($action === 'get_messages') {
        $telefono = trim($_GET['telefono'] ?? '');
        if (empty($telefono)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "El número de teléfono es requerido."]);
            exit;
        }
        
        $telEsc = $conn->real_escape_string($telefono);
        
        // Marcar mensajes como leídos
        $conn->query("UPDATE chat_mensajes SET leido = 1 WHERE coordinador_telefono = '$telEsc' AND direccion = 'entrante'");
        
        // Obtener historial completo de la conversación
        $res = $conn->query("SELECT id, direccion, mensaje, leido, fecha_envio, usuario_id 
                             FROM chat_mensajes 
                             WHERE coordinador_telefono = '$telEsc' 
                             ORDER BY id ASC");
        $mensajes = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $mensajes[] = [
                    "id" => intval($row['id']),
                    "direccion" => $row['direccion'],
                    "mensaje" => $row['mensaje'],
                    "leido" => intval($row['leido']),
                    "fecha" => $row['fecha_envio'],
                    "usuario_id" => $row['usuario_id'] ? intval($row['usuario_id']) : null
                ];
            }
        }
        echo json_encode(["exito" => true, "mensajes" => $mensajes]);
        exit;
    }
}

if ($method === 'POST') {
    if ($action === 'send') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $telefono = trim($input['telefono'] ?? '');
        $nombre = trim($input['nombre'] ?? 'Coordinador');
        $mensaje = trim($input['mensaje'] ?? '');
        
        if (empty($telefono) || empty($mensaje)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Teléfono y mensaje son requeridos."]);
            exit;
        }
        
        $telEsc = $conn->real_escape_string($telefono);
        $nomEsc = $conn->real_escape_string($nombre);
        $msgEsc = $conn->real_escape_string($mensaje);
        $userId = intval($_SESSION['usuario_id']);
        
        // Guardar mensaje saliente en la base de datos
        $sql = "INSERT INTO chat_mensajes (coordinador_telefono, coordinador_nombre, direccion, mensaje, usuario_id, leido) 
                VALUES ('$telEsc', '$nomEsc', 'saliente', '$msgEsc', $userId, 1)";
                
        if ($conn->query($sql)) {
            // SIMULACIÓN DE LLAMADO A LA API DE WHATSAPP BUSINESS
            // Escribir en log para auditoría
            $logFile = __DIR__ . '/../logs/whatsapp_api_calls.log';
            if (!is_dir(dirname($logFile))) {
                mkdir(dirname($logFile), 0777, true);
            }
            $date = date('Y-m-d H:i:s');
            $apiLogLine = "[{$date}] [API CALL SEND] Para: {$telefono} | Mensaje: {$mensaje} | Estado: 200 OK\n";
            file_put_contents($logFile, $apiLogLine, FILE_APPEND);
            
            // Auditoría
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Envió WhatsApp de respuesta a Coordinador: $nombre ($telefono). Mensaje: $mensaje";
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'SEND_CHAT_WHATSAPP', ?, ?)");
            $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
            
            echo json_encode(["exito" => true, "mensaje" => "Mensaje enviado exitosamente."]);
            exit;
        }
        
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error al registrar mensaje: " . $conn->error]);
        exit;
    }
    
    if ($action === 'clear_chat') {
        if (isset($_SESSION['role']) && ($_SESSION['role'] === 'Gerente' || $_SESSION['role'] === 'Digitador')) {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "Acceso denegado. Rol sin privilegios de eliminación."]);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $telefono = trim($input['telefono'] ?? '');
        
        if (empty($telefono)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "El número de teléfono es requerido."]);
            exit;
        }
        
        $telEsc = $conn->real_escape_string($telefono);
        
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM chat_mensajes WHERE coordinador_telefono = '$telEsc'");
            $conn->query("DELETE FROM bot_sesiones WHERE telefono = '$telEsc'");
            $conn->commit();
            
            // Log de auditoría
            $userId = intval($_SESSION['usuario_id']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Vació el historial de chat e interactividad del bot para el teléfono: $telefono";
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'CLEAR_CHAT_HISTORY', ?, ?)");
            $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
            
            echo json_encode(["exito" => true, "mensaje" => "Historial de chat limpiado exitosamente."]);
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(["exito" => false, "mensaje" => "Error al limpiar chat: " . $e->getMessage()]);
            exit;
        }
    }
}

http_response_code(405);
echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
?>
