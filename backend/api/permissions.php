<?php
/**
 * API: Gestión de Usuarios y Permisos
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';

// Validar que el usuario esté logueado y sea Administrador, Digitador o Gerente
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['role'], ['Administrador', 'Digitador', 'Gerente'])) {
    http_response_code(403);
    echo json_encode(["exito" => false, "mensaje" => "Acceso denegado. Se requiere rol de Administrador, Digitador o Gerente."]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$db = Database::getInstance();
$conn = $db->getConnection();

if ($method === 'GET') {
    if ($action === 'list') {
        // Listar todos los usuarios y sus permisos
        $sql = "SELECT u.id, u.username, u.nombre, u.role, u.estado, u.fecha_creacion, u.telefono,
                       p.can_create, p.can_edit, p.can_view, p.can_print, p.can_send, p.can_view_historical,
                       (SELECT COUNT(*) FROM inscritos WHERE registrado_por = u.id) AS total_inscritos
                FROM usuarios u
                LEFT JOIN permisos p ON u.id = p.usuario_id
                ORDER BY u.id ASC";
                
        $res = $conn->query($sql);
        $usuarios = [];
        
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $usuarios[] = [
                    "id" => intval($row['id']),
                    "username" => $row['username'],
                    "nombre" => $row['nombre'],
                    "role" => $row['role'],
                    "estado" => intval($row['estado']),
                    "telefono" => $row['telefono'],
                    "total_inscritos" => intval($row['total_inscritos']),
                    "fecha_creacion" => $row['fecha_creacion'],
                    "permisos" => [
                        "can_create" => intval($row['can_create'] ?? 0),
                        "can_edit" => intval($row['can_edit'] ?? 0),
                        "can_view" => intval($row['can_view'] ?? 0),
                        "can_print" => intval($row['can_print'] ?? 0),
                        "can_send" => intval($row['can_send'] ?? 0),
                        "can_view_historical" => intval($row['can_view_historical'] ?? 0)
                    ]
                ];
            }
        }
        
        echo json_encode(["exito" => true, "usuarios" => $usuarios]);
        exit;
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($action === 'create_user') {
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        $nombre = trim($input['nombre'] ?? '');
        $role = trim($input['role'] ?? 'Digitador');
        $telefono = trim($input['telefono'] ?? '');
        
        if (empty($username) || empty($password) || empty($nombre)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Todos los campos son requeridos."]);
            exit;
        }
        
        $usernameEsc = $conn->real_escape_string($username);
        // Validar si ya existe
        $resCheck = $conn->query("SELECT id FROM usuarios WHERE username = '$usernameEsc' LIMIT 1");
        if ($resCheck && $resCheck->num_rows > 0) {
            http_response_code(409);
            echo json_encode(["exito" => false, "mensaje" => "El nombre de usuario ya está registrado."]);
            exit;
        }
        
        $passHash = password_hash($password, HASH_ALGORITHM, ['cost' => HASH_COST]);
        $nombreEsc = $conn->real_escape_string($nombre);
        $roleEsc = $conn->real_escape_string($role);
        $telefonoEsc = $conn->real_escape_string($telefono);
        
        $conn->begin_transaction();
        
        $sqlUser = "INSERT INTO usuarios (username, password, nombre, role, telefono) VALUES ('$usernameEsc', '$passHash', '$nombreEsc', '$roleEsc', '$telefonoEsc')";
        if ($conn->query($sqlUser)) {
            $newUserId = $conn->insert_id;
            
            // Permisos por defecto según el rol
            $canC = ($role === 'Administrador' || $role === 'Digitador') ? 1 : 0;
            $canE = ($role === 'Administrador' || $role === 'Digitador') ? 1 : 0;
            $canV = 1; // Todos pueden consultar
            $canP = 1; // Todos pueden imprimir
            $canS = 1; // Todos pueden enviar
            $canH = 0; // Desactivado por defecto
            
            $sqlPerms = "INSERT INTO permisos (usuario_id, can_create, can_edit, can_view, can_print, can_send, can_view_historical) 
                         VALUES ($newUserId, $canC, $canE, $canV, $canP, $canS, $canH)";
            
            if ($conn->query($sqlPerms)) {
                $conn->commit();
                
                // Auditoría
                $adminId = intval($_SESSION['usuario_id']);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $detalles = "Usuario creado: $username (Rol: $role, Tel: $telefono) por ID de creador: $adminId";
                $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'CREATE_USER', ?, ?)");
                $stmtAudit->bind_param("iss", $adminId, $detalles, $ip);
                $stmtAudit->execute();
                $stmtAudit->close();
                
                echo json_encode(["exito" => true, "mensaje" => "Usuario creado exitosamente.", "id" => $newUserId]);
                exit;
            }
        }
        
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error interno al crear el usuario."]);
        exit;
    }
    
    // La acción de actualizar permisos es exclusiva de Administrador
    if ($action === 'update_permissions') {
        if ($_SESSION['role'] !== 'Administrador') {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "Acceso denegado. Se requiere rol de Administrador para modificar permisos."]);
            exit;
        }
    }
    
    if ($action === 'update_permissions') {
        $targetUserId = intval($input['usuario_id'] ?? 0);
        $canC = intval($input['can_create'] ?? 0);
        $canE = intval($input['can_edit'] ?? 0);
        $canV = intval($input['can_view'] ?? 0);
        $canP = intval($input['can_print'] ?? 0);
        $canS = intval($input['can_send'] ?? 0);
        $canH = intval($input['can_view_historical'] ?? 0);
        
        if ($targetUserId <= 0) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "ID de usuario inválido."]);
            exit;
        }
        
        // Evitar que el administrador se quite sus propios permisos
        if ($targetUserId === intval($_SESSION['usuario_id'])) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "No puede editar sus propios permisos de Administrador."]);
            exit;
        }
        
        $sql = "INSERT INTO permisos (usuario_id, can_create, can_edit, can_view, can_print, can_send, can_view_historical) 
                VALUES ($targetUserId, $canC, $canE, $canV, $canP, $canS, $canH)
                ON DUPLICATE KEY UPDATE 
                can_create = $canC, can_edit = $canE, can_view = $canV, can_print = $canP, can_send = $canS, can_view_historical = $canH";
                
        if ($conn->query($sql)) {
            // Auditoría
            $adminId = intval($_SESSION['usuario_id']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Permisos actualizados para Usuario ID: $targetUserId (C:$canC, E:$canE, V:$canV, P:$canP, S:$canS, H:$canH) por Admin ID: $adminId";
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'UPDATE_PERMISSIONS', ?, ?)");
            $stmtAudit->bind_param("iss", $adminId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
            
            echo json_encode(["exito" => true, "mensaje" => "Permisos actualizados exitosamente."]);
            exit;
        }
        
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error al actualizar los permisos: " . $conn->error]);
        exit;
    }
    
    if ($action === 'toggle_status') {
        $targetUserId = intval($input['usuario_id'] ?? 0);
        
        if ($targetUserId <= 0) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "ID de usuario inválido."]);
            exit;
        }
        
        if ($targetUserId === intval($_SESSION['usuario_id'])) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "No puede desactivar su propio usuario."]);
            exit;
        }
        
        // Obtener estado actual
        $res = $conn->query("SELECT estado, username FROM usuarios WHERE id = $targetUserId LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $newStatus = $user['estado'] == 1 ? 0 : 1;
            
            $sql = "UPDATE usuarios SET estado = $newStatus WHERE id = $targetUserId";
            if ($conn->query($sql)) {
                // Auditoría
                $adminId = intval($_SESSION['usuario_id']);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $statusText = $newStatus == 1 ? 'activado' : 'desactivado';
                $detalles = "Usuario {$user['username']} (ID: $targetUserId) fue $statusText por Admin ID: $adminId";
                
                $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'TOGGLE_USER_STATUS', ?, ?)");
                $stmtAudit->bind_param("iss", $adminId, $detalles, $ip);
                $stmtAudit->execute();
                $stmtAudit->close();
                
                echo json_encode(["exito" => true, "mensaje" => "Estado del usuario actualizado a: " . ($newStatus == 1 ? 'Activo' : 'Inactivo')]);
                exit;
            }
        }
        
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error al cambiar estado del usuario."]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
?>
