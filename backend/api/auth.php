<?php
/**
 * API: Autenticación de Usuarios
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Conectar a la base de datos
$db = Database::getInstance();
$conn = $db->getConnection();

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($action === 'login') {
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        
        if (empty($username) || empty($password)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Usuario y contraseña son requeridos."]);
            exit;
        }
        
        $usernameEsc = $conn->real_escape_string($username);
        $res = $conn->query("SELECT id, username, password, nombre, role, estado FROM usuarios WHERE username = '$usernameEsc' LIMIT 1");
        
        if ($res && $res->num_rows > 0) {
            $user = $res->fetch_assoc();
            
            if ($user['estado'] != 1) {
                http_response_code(403);
                echo json_encode(["exito" => false, "mensaje" => "El usuario está inactivo."]);
                exit;
            }
            
            if (password_verify($password, $user['password'])) {
                // Regenerar ID de sesión para prevenir Session Fixation (Norma ISO 27001)
                session_regenerate_id(true);
                
                // Cargar los permisos del usuario
                $userId = intval($user['id']);
                $resPerms = $conn->query("SELECT can_create, can_edit, can_view, can_print, can_send, can_view_historical FROM permisos WHERE usuario_id = $userId LIMIT 1");
                
                $perms = [
                    "can_create" => 0,
                    "can_edit" => 0,
                    "can_view" => 0,
                    "can_print" => 0,
                    "can_send" => 0,
                    "can_view_historical" => 0
                ];
                
                if ($resPerms && $resPerms->num_rows > 0) {
                    $p = $resPerms->fetch_assoc();
                    $perms = [
                        "can_create" => intval($p['can_create']),
                        "can_edit" => intval($p['can_edit']),
                        "can_view" => intval($p['can_view']),
                        "can_print" => intval($p['can_print']),
                        "can_send" => intval($p['can_send']),
                        "can_view_historical" => isset($p['can_view_historical']) ? intval($p['can_view_historical']) : 0
                    ];
                }
                
                // Guardar en sesión
                $_SESSION['usuario_id'] = $userId;
                $_SESSION['username'] = $user['username'];
                $_SESSION['nombre'] = $user['nombre'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['perms'] = $perms;
                
                // Registrar log de auditoría
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $detalles = "Inicio de sesión exitoso. Usuario: " . $user['username'] . " (Rol: " . $user['role'] . ")";
                $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'LOGIN', ?, ?)");
                $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
                $stmtAudit->execute();
                $stmtAudit->close();
                
                echo json_encode([
                    "exito" => true,
                    "mensaje" => "Conexión exitosa.",
                    "usuario" => [
                        "id" => $userId,
                        "username" => $user['username'],
                        "nombre" => $user['nombre'],
                        "role" => $user['role']
                    ],
                    "permisos" => $perms
                ]);
                exit;
            }
        }
        
        // Registrar intento fallido
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detalles = "Intento fallido de inicio de sesión. Usuario provisto: " . $username;
        $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (NULL, 'LOGIN_FAILED', ?, ?)");
        $stmtAudit->bind_param("ss", $detalles, $ip);
        $stmtAudit->execute();
        $stmtAudit->close();
        
        http_response_code(401);
        echo json_encode(["exito" => false, "mensaje" => "Credenciales incorrectas."]);
        exit;
    }
}

if ($method === 'GET') {
    if ($action === 'check') {
        if (isset($_SESSION['usuario_id'])) {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            $userId = intval($_SESSION['usuario_id']);
            $q = $conn->query("SELECT COUNT(*) as total FROM inscritos WHERE registrado_por = $userId");
            $totalInscritos = $q ? intval($q->fetch_assoc()['total']) : 0;

            echo json_encode([
                "autenticado" => true,
                "usuario" => [
                    "id" => $_SESSION['usuario_id'],
                    "username" => $_SESSION['username'],
                    "nombre" => $_SESSION['nombre'],
                    "role" => $_SESSION['role'],
                    "total_inscritos" => $totalInscritos
                ],
                "permisos" => $_SESSION['perms']
            ]);
        } else {
            echo json_encode(["autenticado" => false]);
        }
        exit;
    }
    
    if ($action === 'logout') {
        if (isset($_SESSION['usuario_id'])) {
            $userId = intval($_SESSION['usuario_id']);
            $username = $_SESSION['username'];
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Cierre de sesión. Usuario: " . $username;
            
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'LOGOUT', ?, ?)");
            $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
        }
        
        session_destroy();
        echo json_encode(["exito" => true, "mensaje" => "Sesión cerrada correctamente."]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
?>
