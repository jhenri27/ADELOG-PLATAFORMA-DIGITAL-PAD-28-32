<?php
/**
 * API: Helpdesk de Incidencias de Campaña y Soporte
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
    if ($action === 'list') {
        // Listar todas las incidencias
        $res = $conn->query("SELECT * FROM helpdesk_incidencias ORDER BY id DESC");
        $incidencias = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $incidencias[] = $row;
            }
        }
        echo json_encode(["exito" => true, "incidencias" => $incidencias]);
        exit;
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($action === 'create') {
        $reportado_por = trim($input['reportado_por'] ?? $_SESSION['nombre']);
        $rol_reportante = trim($input['rol_reportante'] ?? $_SESSION['role']);
        $tipo = trim($input['tipo_incidencia'] ?? 'Soporte Técnico');
        $descripcion = trim($input['descripcion'] ?? '');
        
        if (empty($descripcion)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "La descripción de la incidencia es requerida."]);
            exit;
        }
        
        // Evitar roles no contemplados
        $rolesPermitidos = ['Coordinador', 'Digitador', 'Administrador'];
        if (!in_array($rol_reportante, $rolesPermitidos)) {
            $rol_reportante = 'Digitador';
        }
        
        $reportadoEsc = $conn->real_escape_string($reportado_por);
        $rolEsc = $conn->real_escape_string($rol_reportante);
        $tipoEsc = $conn->real_escape_string($tipo);
        $descEsc = $conn->real_escape_string($descripcion);
        
        $sql = "INSERT INTO helpdesk_incidencias (reportado_por, rol_reportante, tipo_incidencia, descripcion) 
                VALUES ('$reportadoEsc', '$rolEsc', '$tipoEsc', '$descEsc')";
                
        if ($conn->query($sql)) {
            $newTicketId = $conn->insert_id;
            
            // Auditoría
            $userId = intval($_SESSION['usuario_id']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Nueva incidencia registrada (#$newTicketId) por $reportado_por ($rol_reportante). Tipo: $tipo. Desc: $descripcion";
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'CREATE_TICKET_HELPDESK', ?, ?)");
            $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
            
            echo json_encode(["exito" => true, "mensaje" => "Incidencia registrada exitosamente.", "id" => $newTicketId]);
            exit;
        }
        
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error al guardar incidencia: " . $conn->error]);
        exit;
    }
    
    if ($action === 'update_status') {
        $ticketId = intval($input['id'] ?? 0);
        $nuevoEstado = trim($input['estado'] ?? 'En Proceso');
        
        if ($ticketId <= 0 || !in_array($nuevoEstado, ['Pendiente', 'En Proceso', 'Resuelto'])) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "ID o estado inválido."]);
            exit;
        }
        
        $estadoEsc = $conn->real_escape_string($nuevoEstado);
        $fechaResolucionSql = ($nuevoEstado === 'Resuelto') ? ", fecha_resolucion = NOW()" : ", fecha_resolucion = NULL";
        
        $sql = "UPDATE helpdesk_incidencias SET estado = '$estadoEsc' $fechaResolucionSql WHERE id = $ticketId";
        
        if ($conn->query($sql)) {
            // Auditoría
            $userId = intval($_SESSION['usuario_id']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Incidencia ID: $ticketId actualizada a estado: $nuevoEstado por Usuario ID: $userId";
            
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'UPDATE_TICKET_HELPDESK', ?, ?)");
            $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
            
            echo json_encode(["exito" => true, "mensaje" => "Estado de la incidencia actualizado correctamente."]);
            exit;
        }
        
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error al actualizar estado del ticket."]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
?>
