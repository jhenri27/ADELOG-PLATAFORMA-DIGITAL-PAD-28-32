<?php
/**
 * REST API Endpoint: Mantenimiento de Perfiles y Accesos Granulares (RBAC)
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

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/PerfilManager.php';

$usuario_id = $_SESSION['usuario_id'] ?? 1;
$manager = new PerfilManager();

$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?? [];
$request_data = array_merge($_GET, $_POST, $json_data);

$action = $request_data['action'] ?? 'listar';

try {
    switch ($action) {
        case 'listar':
            $perfiles = $manager->listarPerfiles();
            echo json_encode([
                "exito" => true,
                "mensaje" => "Lista de perfiles obtenida exitosamente.",
                "perfiles" => $perfiles
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'obtener':
            $id = (int)($request_data['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(["exito" => false, "mensaje" => "ID de perfil requerido."]);
                exit;
            }
            $perfil = $manager->obtenerPerfilCompleto($id);
            if ($perfil) {
                echo json_encode([
                    "exito" => true,
                    "perfil" => $perfil
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(["exito" => false, "mensaje" => "Perfil no encontrado."]);
            }
            break;

        case 'malla_modulos':
            $modulos = $manager->obtenerMallaPermisos();
            echo json_encode([
                "exito" => true,
                "modulos" => $modulos
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'crear':
            $res = $manager->crearPerfil($request_data, $usuario_id);
            if (!$res['exito']) http_response_code(400);
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'editar':
            $id = (int)($request_data['id'] ?? 0);
            $res = $manager->editarPerfil($id, $request_data, $usuario_id);
            if (!$res['exito']) http_response_code(400);
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'asignar_permisos':
            $id = (int)($request_data['id'] ?? 0);
            $permisos = $request_data['permisos'] ?? [];
            $res = $manager->asignarPermisosAPerfil($id, $permisos, $usuario_id);
            if (!$res['exito']) http_response_code(400);
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'copiar_permisos':
            $origen = (int)($request_data['perfil_origen'] ?? 0);
            $destino = (int)($request_data['perfil_destino'] ?? 0);
            $res = $manager->copiarPermisos($origen, $destino, $usuario_id);
            if (!$res['exito']) http_response_code(400);
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'eliminar':
            $id = (int)($request_data['id'] ?? 0);
            $res = $manager->eliminarPerfil($id, $usuario_id);
            if (!$res['exito']) http_response_code(400);
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Acción no reconocida."]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["exito" => false, "mensaje" => "Error del servidor: " . $e->getMessage()]);
}
