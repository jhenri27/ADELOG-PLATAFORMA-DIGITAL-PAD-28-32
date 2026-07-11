<?php
/**
 * API: Campañas con Enlaces y Códigos QR
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
        // Listar campañas QR
        $res = $conn->query("SELECT * FROM campanas_qr ORDER BY id DESC");
        $campanas = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $codigo = $row['codigo_campana'];
                // Formar enlace digital de la campaña
                $enlace = "http://localhost/PLATAFORMA%20DIGITAL-PAD-28-32/frontend/index.html?c=" . urlencode($codigo);
                // Código QR dinámico usando api.qrserver.com (API pública estable y activa)
                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($enlace);
                
                $row['enlace_digital'] = $enlace;
                $row['qr_image_url'] = $qrUrl;
                $campanas[] = $row;
            }
        }
        echo json_encode(["exito" => true, "campanas" => $campanas]);
        exit;
    }
}

if ($method === 'POST') {
    if ($action === 'create') {
        // Permitir a Administrador, Gerente y Digitador crear campañas QR (restringir a Coordinador)
        if ($_SESSION['role'] === 'Coordinador') {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "Los Coordinadores no tienen permiso para crear campañas QR."]);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $nombre = trim($input['nombre_campana'] ?? '');
        $coordinador = trim($input['coordinador'] ?? '');
        
        if (empty($nombre) || empty($coordinador)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "El nombre de campaña y el coordinador son requeridos."]);
            exit;
        }
        
        // Generar un código único
        $codigo = substr(md5(uniqid($nombre, true)), 0, 8);
        
        $nombreEsc = $conn->real_escape_string($nombre);
        $coordinadorEsc = $conn->real_escape_string($coordinador);
        $codigoEsc = $conn->real_escape_string($codigo);
        
        $sql = "INSERT INTO campanas_qr (codigo_campana, nombre_campana, coordinador) VALUES ('$codigoEsc', '$nombreEsc', '$coordinadorEsc')";
        
        if ($conn->query($sql)) {
            // Auditoría
            $adminId = intval($_SESSION['usuario_id']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Campaña QR creada: $nombre (Código: $codigo). Coordinador: $coordinador por Admin ID: $adminId";
            
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'CREATE_QR_CAMPAIGN', ?, ?)");
            $stmtAudit->bind_param("iss", $adminId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
            
            echo json_encode([
                "exito" => true,
                "mensaje" => "Campaña QR creada exitosamente.",
                "codigo" => $codigo
            ]);
            exit;
        }
        
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error al guardar campaña en base de datos: " . $conn->error]);
        exit;
    }
    
    if ($action === 'toggle_status') {
        // Solo administradores pueden activar/inactivar
        if ($_SESSION['role'] !== 'Administrador') {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "Acceso denegado. Se requiere rol de Administrador."]);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        
        if ($id > 0) {
            // Obtener estado actual
            $q = $conn->query("SELECT activo, nombre_campana FROM campanas_qr WHERE id = $id LIMIT 1");
            if ($q && $q->num_rows > 0) {
                $cRow = $q->fetch_assoc();
                $newActivo = intval($cRow['activo']) === 1 ? 0 : 1;
                
                if ($conn->query("UPDATE campanas_qr SET activo = $newActivo WHERE id = $id")) {
                    // Auditoría
                    $adminId = intval($_SESSION['usuario_id']);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $actionText = $newActivo === 1 ? 'ACTIVATED_QR_CAMPAIGN' : 'DEACTIVATED_QR_CAMPAIGN';
                    $detalles = "Campaña QR (ID: $id, Nombre: {$cRow['nombre_campana']}) cambiada a activo=$newActivo por Admin ID: $adminId";
                    $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, ?, ?, ?)");
                    $stmtAudit->bind_param("isss", $adminId, $actionText, $detalles, $ip);
                    $stmtAudit->execute();
                    $stmtAudit->close();
                    
                    echo json_encode(["exito" => true, "mensaje" => "Estado de campaña actualizado.", "activo" => $newActivo]);
                    exit;
                }
            }
        }
        
        http_response_code(400);
        echo json_encode(["exito" => false, "mensaje" => "Datos inválidos."]);
        exit;
    }

    if ($action === 'delete') {
        // Solo administradores pueden eliminar
        if ($_SESSION['role'] !== 'Administrador') {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "Acceso denegado. Se requiere rol de Administrador."]);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        
        if ($id > 0) {
            $q = $conn->query("SELECT nombre_campana FROM campanas_qr WHERE id = $id LIMIT 1");
            if ($q && $q->num_rows > 0) {
                $cRow = $q->fetch_assoc();
                if ($conn->query("DELETE FROM campanas_qr WHERE id = $id")) {
                    // Auditoría
                    $adminId = intval($_SESSION['usuario_id']);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $detalles = "Campaña QR eliminada (ID: $id, Nombre: {$cRow['nombre_campana']}) por Admin ID: $adminId";
                    $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'DELETE_QR_CAMPAIGN', ?, ?)");
                    $stmtAudit->bind_param("iss", $adminId, $detalles, $ip);
                    $stmtAudit->execute();
                    $stmtAudit->close();
                    
                    echo json_encode(["exito" => true, "mensaje" => "Campaña eliminada exitosamente."]);
                    exit;
                }
            }
        }
        
        http_response_code(400);
        echo json_encode(["exito" => false, "mensaje" => "Datos inválidos."]);
        exit;
    }
    
    if ($action === 'track_click') {
        // Incrementar clics sin requerir logueo de sesión (es público cuando entran al QR link)
        // Se hace por GET o POST. Habilitamos POST público de tracking.
        $input = json_decode(file_get_contents('php://input'), true);
        $codigo = trim($input['codigo'] ?? '');
        
        if (!empty($codigo)) {
            $codigoEsc = $conn->real_escape_string($codigo);
            // Validar que esté activa
            $q = $conn->query("SELECT activo FROM campanas_qr WHERE codigo_campana = '$codigoEsc' LIMIT 1");
            if ($q && $q->num_rows > 0) {
                $cRow = $q->fetch_assoc();
                if (intval($cRow['activo']) !== 1) {
                    http_response_code(403);
                    echo json_encode(["exito" => false, "mensaje" => "Esta campaña ha sido inactivada por el administrador."]);
                    exit;
                }
            }
            
            $conn->query("UPDATE campanas_qr SET clics = clics + 1 WHERE codigo_campana = '$codigoEsc'");
            echo json_encode(["exito" => true]);
            exit;
        }
        
        http_response_code(400);
        echo json_encode(["exito" => false, "mensaje" => "Código faltante."]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
?>
