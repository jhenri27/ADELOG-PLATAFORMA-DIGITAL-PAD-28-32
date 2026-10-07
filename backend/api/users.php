<?php
/**
 * API Central: Módulo Usuarios del Sistema, Escalafón y Gestión Territorial
 * PAD/28-32 - Plataforma Electoral - Campaña Pastora Altagracia
 * Conforme a Normas PLAD (PLAD-VAF-SYNC-01 y PLAD-CERT-QR-01)
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';

// Validar inicio de sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión para continuar."]);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';
$currentUserId = intval($_SESSION['usuario_id']);
$currentUserRole = $_SESSION['role'] ?? '';
$isAdmin = ($currentUserRole === 'Administrador' || intval($_SESSION['perfil_id'] ?? 0) === 1);

// Helper para recalcular nivel de un ML individual
function recalcularNivelML($conn, $usuarioId) {
    // 1. Contar colaboradores reales
    $qCount = $conn->query("SELECT COUNT(*) as total FROM inscritos WHERE registrado_por = $usuarioId OR referido_por_ml_id = $usuarioId");
    $totalColab = $qCount ? intval($qCount->fetch_assoc()['total']) : 0;
    
    // 2. Contar prospectos a ML referidos
    $qML = $conn->query("SELECT COUNT(*) as total FROM inscritos WHERE referido_por_ml_id = $usuarioId AND es_militante_lider = 1");
    $totalMLs = $qML ? intval($qML->fetch_assoc()['total']) : 0;
    
    // 3. Obtener nivel correspondiente en escalafon_niveles_ml
    $qNivel = $conn->query("SELECT siglas, nombre_nivel FROM escalafon_niveles_ml 
                            WHERE activo = 1 AND $totalColab >= min_inscritos 
                            ORDER BY min_inscritos DESC LIMIT 1");
    $nivelSiglas = 'ML';
    $nivelLabel = 'Militante Líder';
    if ($qNivel && $qNivel->num_rows > 0) {
        $nRow = $qNivel->fetch_assoc();
        $nivelSiglas = $nRow['siglas'];
        $nivelLabel = $nRow['nombre_nivel'];
    }
    
    $conn->query("UPDATE usuarios SET 
                  total_colaboradores = $totalColab, 
                  total_prospectos_ml = $totalMLs, 
                  nivel_avance = '$nivelSiglas', 
                  nivel_avance_label = '$nivelLabel' 
                  WHERE id = $usuarioId");
    
    return [
        "total_colaboradores" => $totalColab,
        "total_prospectos_ml" => $totalMLs,
        "nivel_avance" => $nivelSiglas,
        "nivel_avance_label" => $nivelLabel
    ];
}

if ($method === 'GET') {
    
    // -------------------------------------------------------------
    // ACCION: LISTAR USUARIOS CON FILTROS, PAGINACION Y METRICAS
    // -------------------------------------------------------------
    if ($action === 'list') {
        $q = trim($_GET['q'] ?? '');
        $perfilId = intval($_GET['perfil_id'] ?? 0);
        $coordinadorId = intval($_GET['coordinador_id'] ?? 0);
        $nivelAvance = trim($_GET['nivel_avance'] ?? '');
        $estadoActivacion = trim($_GET['estado_activacion'] ?? '');
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = max(5, min(100, intval($_GET['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;
        
        $where = ["1=1"];
        
        // Búsqueda libre
        if (!empty($q)) {
            $qEsc = $conn->real_escape_string($q);
            $cleanQ = preg_replace('/\D/', '', $q);
            $whereQ = "(u.nombre LIKE '%$qEsc%' OR u.username LIKE '%$qEsc%' OR u.codigo_ml LIKE '%$qEsc%' OR u.telefono LIKE '%$qEsc%' OR u.email LIKE '%$qEsc%')";
            if (strlen($cleanQ) >= 7) {
                $whereQ .= " OR REPLACE(u.cedula, '-', '') LIKE '%$cleanQ%'";
            }
            $where[] = "($whereQ)";
        }
        
        if ($perfilId > 0) {
            $where[] = "u.perfil_id = $perfilId";
        }
        if ($coordinadorId > 0) {
            $where[] = "u.coordinador_id = $coordinadorId";
        }
        if (!empty($nivelAvance)) {
            $nivEsc = $conn->real_escape_string($nivelAvance);
            $where[] = "u.nivel_avance = '$nivEsc'";
        }
        if (!empty($estadoActivacion)) {
            $estEsc = $conn->real_escape_string($estadoActivacion);
            $where[] = "u.estado_activacion = '$estEsc'";
        }
        
        $whereStr = implode(' AND ', $where);
        
        // Contar total con filtros
        $sqlTotal = "SELECT COUNT(*) as total FROM usuarios u WHERE $whereStr";
        $resTotal = $conn->query($sqlTotal);
        $totalFiltrados = $resTotal ? intval($resTotal->fetch_assoc()['total']) : 0;
        
        // Consulta principal con joins
        $sql = "SELECT u.id, u.codigo_ml, u.username, u.nombre, u.cedula, u.telefono, u.email, 
                       u.role, u.perfil_id, u.coordinador_id, u.nivel_avance, u.nivel_avance_label, 
                       u.total_colaboradores, u.total_prospectos_ml, u.estado, u.estado_activacion, 
                       u.token_activacion, u.token_expiracion, u.fecha_creacion, u.activado_at,
                       p.nombre as perfil_nombre, p.nivel_jerarquico,
                       coord.nombre as coordinador_nombre, coord.role as coordinador_role, coord.codigo_ml as coordinador_codigo
                FROM usuarios u
                LEFT JOIN perfiles p ON u.perfil_id = p.id
                LEFT JOIN usuarios coord ON u.coordinador_id = coord.id
                WHERE $whereStr
                ORDER BY u.id DESC
                LIMIT $offset, $limit";
        
        $res = $conn->query($sql);
        $usuarios = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                // Verificar si el token expiró en tiempo real
                if ($row['estado_activacion'] === 'pendiente' && !empty($row['token_expiracion'])) {
                    if (strtotime($row['token_expiracion']) < time()) {
                        $row['estado_activacion'] = 'vencido';
                    }
                }
                
                // Construir URL de activación y enlace de red personal
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                
                $row['enlace_activacion'] = !empty($row['token_activacion']) 
                    ? "$protocol$host/pad2832/activar.php?token=" . $row['token_activacion'] 
                    : "";
                
                $refCode = !empty($row['codigo_ml']) ? $row['codigo_ml'] : ('USER-' . $row['id']);
                $row['enlace_red_prospeccion'] = "$protocol$host/pad2832/frontend/index.html?canal=red_ml&ref=" . urlencode($refCode);
                
                $usuarios[] = $row;
            }
        }
        
        // Métricas resumen para las tarjetas superiores
        $qTot = $conn->query("SELECT 
            COUNT(*) as total_usuarios,
            SUM(CASE WHEN role LIKE '%Coordinador%' OR perfil_id IN (2,3,4) THEN 1 ELSE 0 END) as total_coordinadores,
            SUM(CASE WHEN role LIKE '%Militante%' OR perfil_id = 5 THEN 1 ELSE 0 END) as total_mls,
            SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) as total_activos,
            SUM(CASE WHEN estado_activacion = 'pendiente' THEN 1 ELSE 0 END) as total_pendientes
            FROM usuarios");
        $metricas = $qTot ? $qTot->fetch_assoc() : [
            "total_usuarios" => 0,
            "total_coordinadores" => 0,
            "total_mls" => 0,
            "total_activos" => 0,
            "total_pendientes" => 0
        ];
        
        echo json_encode([
            "exito" => true,
            "usuarios" => $usuarios,
            "metricas" => $metricas,
            "paginacion" => [
                "pagina_actual" => $page,
                "limite" => $limit,
                "total_registros" => $totalFiltrados,
                "total_paginas" => ceil($totalFiltrados / $limit)
            ]
        ]);
        exit;
    }
    
    // -------------------------------------------------------------
    // ACCION: DETALLE DE USUARIO Y SUS COLABORADORES
    // -------------------------------------------------------------
    if ($action === 'detail') {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "ID de usuario inválido."]);
            exit;
        }
        
        // Recalcular métricas en vivo antes de presentar
        recalcularNivelML($conn, $id);
        
        $sql = "SELECT u.*, p.nombre as perfil_nombre, 
                       coord.nombre as coordinador_nombre, coord.role as coordinador_role, coord.codigo_ml as coordinador_codigo
                FROM usuarios u
                LEFT JOIN perfiles p ON u.perfil_id = p.id
                LEFT JOIN usuarios coord ON u.coordinador_id = coord.id
                WHERE u.id = $id LIMIT 1";
        $res = $conn->query($sql);
        if (!$res || $res->num_rows === 0) {
            http_response_code(404);
            echo json_encode(["exito" => false, "mensaje" => "Usuario no encontrado."]);
            exit;
        }
        $usuario = $res->fetch_assoc();
        
        // Obtener colaboradores y prospectos inscritos por este usuario
        $sqlColabs = "SELECT id, numero_lista, cedula, nombres, apellidos, colegio_electoral, 
                             recinto_ubicacion, sector, municipio, telefono, canal_origen, fecha_registro,
                             es_militante_lider, tipo_elector
                      FROM inscritos 
                      WHERE registrado_por = $id OR referido_por_ml_id = $id 
                      ORDER BY id DESC LIMIT 100";
        $resColabs = $conn->query($sqlColabs);
        $colaboradores = [];
        if ($resColabs) {
            while ($c = $resColabs->fetch_assoc()) {
                $colaboradores[] = $c;
            }
        }
        
        // Enlaces listos
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $refCode = !empty($usuario['codigo_ml']) ? $usuario['codigo_ml'] : ('USER-' . $usuario['id']);
        
        $usuario['enlace_red_prospeccion'] = "$protocol$host/pad2832/frontend/index.html?canal=red_ml&ref=" . urlencode($refCode);
        $usuario['enlace_activacion'] = !empty($usuario['token_activacion']) 
            ? "$protocol$host/pad2832/activar.php?token=" . $usuario['token_activacion'] 
            : "";
        
        echo json_encode([
            "exito" => true,
            "usuario" => $usuario,
            "colaboradores" => $colaboradores
        ]);
        exit;
    }
    
    // -------------------------------------------------------------
    // ACCION: OBTENER LISTA DE ESCALAFON (10 NIVELES)
    // -------------------------------------------------------------
    if ($action === 'get_escalafon') {
        $res = $conn->query("SELECT * FROM escalafon_niveles_ml WHERE activo = 1 ORDER BY nivel_orden ASC");
        $niveles = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $niveles[] = $row;
            }
        }
        echo json_encode(["exito" => true, "niveles" => $niveles]);
        exit;
    }
    
    // -------------------------------------------------------------
    // ACCION: DASHBOARD DE AVANCE DE MILITANTES LIDERES
    // -------------------------------------------------------------
    if ($action === 'dashboard_avance') {
        // 1. Distribución piramidal por niveles
        $qDist = $conn->query("SELECT nivel_avance, COUNT(*) as cantidad 
                               FROM usuarios 
                               WHERE (role LIKE '%Militante%' OR perfil_id = 5) AND estado = 1 
                               GROUP BY nivel_avance");
        $distribucion = [];
        if ($qDist) {
            while ($r = $qDist->fetch_assoc()) {
                $distribucion[$r['nivel_avance']] = intval($r['cantidad']);
            }
        }
        
        // 2. Ranking Top 10 MLs
        $qTop = $conn->query("SELECT u.id, u.codigo_ml, u.nombre, u.nivel_avance, u.nivel_avance_label, 
                                     u.total_colaboradores, u.total_prospectos_ml,
                                     coord.nombre as coordinador_nombre
                              FROM usuarios u
                              LEFT JOIN usuarios coord ON u.coordinador_id = coord.id
                              WHERE (u.role LIKE '%Militante%' OR u.perfil_id = 5) AND u.estado = 1
                              ORDER BY u.total_colaboradores DESC, u.total_prospectos_ml DESC
                              LIMIT 10");
        $topLideres = [];
        if ($qTop) {
            while ($top = $qTop->fetch_assoc()) {
                $topLideres[] = $top;
            }
        }
        
        // 3. Progreso personal del usuario logueado
        $progresoPersonal = null;
        if (!$isAdmin) {
            $progresoPersonal = recalcularNivelML($conn, $currentUserId);
            
            // Buscar siguiente nivel
            $tot = $progresoPersonal['total_colaboradores'];
            $qNext = $conn->query("SELECT * FROM escalafon_niveles_ml 
                                   WHERE activo = 1 AND min_inscritos > $tot 
                                   ORDER BY min_inscritos ASC LIMIT 1");
            if ($qNext && $qNext->num_rows > 0) {
                $nNext = $qNext->fetch_assoc();
                $progresoPersonal['siguiente_nivel_siglas'] = $nNext['siglas'];
                $progresoPersonal['siguiente_nivel_label'] = $nNext['nombre_nivel'];
                $progresoPersonal['meta_siguiente'] = intval($nNext['min_inscritos']);
                $progresoPersonal['faltantes'] = max(0, intval($nNext['min_inscritos']) - $tot);
                $progresoPersonal['porcentaje'] = min(100, round(($tot / intval($nNext['min_inscritos'])) * 100));
            } else {
                $progresoPersonal['siguiente_nivel_label'] = "Máximo Nivel Alcanzado";
                $progresoPersonal['faltantes'] = 0;
                $progresoPersonal['porcentaje'] = 100;
            }
        }
        
        echo json_encode([
            "exito" => true,
            "distribucion" => $distribucion,
            "top_lideres" => $topLideres,
            "progreso_personal" => $progresoPersonal
        ]);
        exit;
    }
    
    // -------------------------------------------------------------
    // ACCION: LISTAR COORDINADORES DISPONIBLES (PARA SELECTS)
    // -------------------------------------------------------------
    if ($action === 'list_coordinadores') {
        $res = $conn->query("SELECT id, nombre, role, codigo_ml, telefono FROM usuarios 
                             WHERE role LIKE '%Coordinador%' OR perfil_id IN (1, 2, 3, 4) 
                             ORDER BY nombre ASC");
        $coords = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $coords[] = $r;
            }
        }
        echo json_encode(["exito" => true, "coordinadores" => $coords]);
        exit;
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // -------------------------------------------------------------
    // ACCION: CREAR NUEVO USUARIO
    // -------------------------------------------------------------
    if ($action === 'create_user') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "Solo los administradores pueden crear usuarios."]);
            exit;
        }
        
        $nombre = trim($input['nombre'] ?? '');
        $username = trim($input['username'] ?? '');
        $cedula = trim($input['cedula'] ?? '');
        $telefono = trim($input['telefono'] ?? '');
        $email = trim($input['email'] ?? '');
        $role = trim($input['role'] ?? 'Digitador');
        $perfilId = intval($input['perfil_id'] ?? 5);
        $coordinadorId = !empty($input['coordinador_id']) ? intval($input['coordinador_id']) : "NULL";
        $password = trim($input['password'] ?? '');
        
        if (empty($nombre)) {
            echo json_encode(["exito" => false, "mensaje" => "El nombre es obligatorio."]);
            exit;
        }
        
        // Si no se proporcionó username, autogenerar
        if (empty($username)) {
            $cleanCed = preg_replace('/\D/', '', $cedula);
            $username = !empty($cleanCed) ? ("ml_" . substr($cleanCed, -6)) : ("user_" . time());
        }
        
        // Validar unicidad de username
        $usernameEsc = $conn->real_escape_string($username);
        $checkU = $conn->query("SELECT id FROM usuarios WHERE username = '$usernameEsc' LIMIT 1");
        if ($checkU && $checkU->num_rows > 0) {
            echo json_encode(["exito" => false, "mensaje" => "El nombre de usuario '$username' ya existe. Elija otro."]);
            exit;
        }
        
        // Si es Militante Líder, generar código ML-XXXX
        $codigoML = null;
        $isML = (str_contains($role, 'Militante') || $perfilId === 5);
        if ($isML) {
            $qMax = $conn->query("SELECT MAX(id) as max_id FROM usuarios");
            $nextId = ($qMax ? intval($qMax->fetch_assoc()['max_id']) : 0) + 1;
            $codigoML = sprintf("ML-%04d", $nextId);
        }
        
        // Contraseña: Si viene vacía y tiene cédula, usar la cédula como credencial cifrada
        if (empty($password)) {
            $password = !empty($cedula) ? preg_replace('/\D/', '', $cedula) : '123456';
        }
        $passHash = password_hash($password, PASSWORD_BCRYPT);
        
        // Token de activación para autoservicio
        $token = bin2hex(random_bytes(32));
        $expiracion = date('Y-m-d H:i:s', strtotime('+72 hours'));
        $estadoActivacion = $isML ? 'pendiente' : 'activo';
        
        $nombreEsc = $conn->real_escape_string($nombre);
        $cedulaEsc = $conn->real_escape_string($cedula);
        $telEsc = $conn->real_escape_string($telefono);
        $emailEsc = $conn->real_escape_string($email);
        $roleEsc = $conn->real_escape_string($role);
        $codeMLEsc = $codigoML ? "'$codigoML'" : "NULL";
        
        $sqlInsert = "INSERT INTO usuarios (codigo_ml, username, password, nombre, cedula, telefono, email, 
                                            role, perfil_id, coordinador_id, nivel_avance, nivel_avance_label, 
                                            token_activacion, token_expiracion, estado, estado_activacion)
                      VALUES ($codeMLEsc, '$usernameEsc', '$passHash', '$nombreEsc', '$cedulaEsc', '$telEsc', '$emailEsc', 
                              '$roleEsc', $perfilId, $coordinadorId, 'ML', 'Militante Líder', 
                              '$token', '$expiracion', 1, '$estadoActivacion')";
        
        if ($conn->query($sqlInsert)) {
            $newId = $conn->insert_id;
            
            // Si el código ML dependía del ID final, asegurar que no tenga colisión
            if ($isML && !$codigoML) {
                $codigoML = sprintf("ML-%04d", $newId);
                $conn->query("UPDATE usuarios SET codigo_ml = '$codigoML' WHERE id = $newId");
            }
            
            // Crear permisos básicos en tabla permisos
            $canCreate = ($role === 'Digitador' || $isML) ? 1 : 0;
            $canEdit = ($isAdmin) ? 1 : 0;
            $conn->query("INSERT INTO permisos (usuario_id, can_create, can_edit, can_view, can_print, can_send, can_view_historical)
                          VALUES ($newId, $canCreate, $canEdit, 1, 1, 0, 0)
                          ON DUPLICATE KEY UPDATE can_view = 1");
            
            // Registrar auditoría
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Usuario creado: $username (Rol: $role, Código: $codigoML, Creado por: {$_SESSION['username']})";
            $conn->query("INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles, ip_address)
                          VALUES ($currentUserId, 'CREATE_USER', 'usuarios', $newId, '$detalles', '$ip')");
            
            echo json_encode([
                "exito" => true,
                "mensaje" => "Usuario creado exitosamente.",
                "usuario_id" => $newId,
                "codigo_ml" => $codigoML,
                "token_activacion" => $token
            ]);
        } else {
            echo json_encode(["exito" => false, "mensaje" => "Error al crear usuario: " . $conn->error]);
        }
        exit;
    }
    
    // -------------------------------------------------------------
    // ACCION: ACTUALIZAR USUARIO
    // -------------------------------------------------------------
    if ($action === 'update_user') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "Solo los administradores pueden editar usuarios."]);
            exit;
        }
        
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["exito" => false, "mensaje" => "ID de usuario inválido."]);
            exit;
        }
        
        $nombre = trim($input['nombre'] ?? '');
        $username = trim($input['username'] ?? '');
        $cedula = trim($input['cedula'] ?? '');
        $telefono = trim($input['telefono'] ?? '');
        $email = trim($input['email'] ?? '');
        $role = trim($input['role'] ?? '');
        $perfilId = intval($input['perfil_id'] ?? 0);
        $coordinadorId = !empty($input['coordinador_id']) ? intval($input['coordinador_id']) : "NULL";
        $password = trim($input['password'] ?? '');
        
        $nombreEsc = $conn->real_escape_string($nombre);
        $usernameEsc = $conn->real_escape_string($username);
        $cedulaEsc = $conn->real_escape_string($cedula);
        $telEsc = $conn->real_escape_string($telefono);
        $emailEsc = $conn->real_escape_string($email);
        $roleEsc = $conn->real_escape_string($role);
        
        // Validar unicidad de username si se cambió
        $checkU = $conn->query("SELECT id FROM usuarios WHERE username = '$usernameEsc' AND id != $id LIMIT 1");
        if ($checkU && $checkU->num_rows > 0) {
            echo json_encode(["exito" => false, "mensaje" => "El nombre de usuario '$username' ya está en uso."]);
            exit;
        }
        
        $updates = [
            "nombre = '$nombreEsc'",
            "username = '$usernameEsc'",
            "cedula = '$cedulaEsc'",
            "telefono = '$telEsc'",
            "email = '$emailEsc'",
            "coordinador_id = $coordinadorId"
        ];
        
        if (!empty($role)) {
            $updates[] = "role = '$roleEsc'";
        }
        if ($perfilId > 0) {
            $updates[] = "perfil_id = $perfilId";
        }
        if (!empty($password)) {
            $passHash = password_hash($password, PASSWORD_BCRYPT);
            $updates[] = "password = '$passHash'";
        }
        
        $sqlUp = "UPDATE usuarios SET " . implode(", ", $updates) . " WHERE id = $id";
        if ($conn->query($sqlUp)) {
            // Auditoría
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Usuario ID $id actualizado por {$_SESSION['username']}";
            $conn->query("INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles, ip_address)
                          VALUES ($currentUserId, 'UPDATE_USER', 'usuarios', $id, '$detalles', '$ip')");
            
            echo json_encode(["exito" => true, "mensaje" => "Usuario actualizado correctamente."]);
        } else {
            echo json_encode(["exito" => false, "mensaje" => "Error al actualizar: " . $conn->error]);
        }
        exit;
    }
    
    // -------------------------------------------------------------
    // ACCION: TOGGLE ESTADO (ACTIVAR / DESACTIVAR)
    // -------------------------------------------------------------
    if ($action === 'toggle_status') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "No autorizado."]);
            exit;
        }
        
        $id = intval($input['id'] ?? 0);
        $q = $conn->query("SELECT estado FROM usuarios WHERE id = $id LIMIT 1");
        if (!$q || $q->num_rows === 0) {
            echo json_encode(["exito" => false, "mensaje" => "Usuario no encontrado."]);
            exit;
        }
        
        $nuevoEstado = ($q->fetch_assoc()['estado'] == 1) ? 0 : 1;
        $nuevoEstadoAct = ($nuevoEstado == 1) ? 'activo' : 'deshabilitado';
        
        $conn->query("UPDATE usuarios SET estado = $nuevoEstado, estado_activacion = '$nuevoEstadoAct' WHERE id = $id");
        
        // Auditoría
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detalles = "Estado cambiado a " . ($nuevoEstado ? 'ACTIVO' : 'DESHABILITADO') . " para usuario ID $id";
        $conn->query("INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles, ip_address)
                      VALUES ($currentUserId, 'TOGGLE_STATUS', 'usuarios', $id, '$detalles', '$ip')");
        
        echo json_encode(["exito" => true, "nuevo_estado" => $nuevoEstado, "estado_activacion" => $nuevoEstadoAct]);
        exit;
    }
    
    // -------------------------------------------------------------
    // ACCION: REGENERAR TOKEN DE ACTIVACION (72 HORAS)
    // -------------------------------------------------------------
    if ($action === 'regenerate_token') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "No autorizado."]);
            exit;
        }
        
        $id = intval($input['id'] ?? 0);
        $token = bin2hex(random_bytes(32));
        $expiracion = date('Y-m-d H:i:s', strtotime('+72 hours'));
        
        $conn->query("UPDATE usuarios SET 
                      token_activacion = '$token', 
                      token_expiracion = '$expiracion', 
                      estado_activacion = 'pendiente' 
                      WHERE id = $id");
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $enlace = "$protocol$host/pad2832/activar.php?token=$token";
        
        echo json_encode([
            "exito" => true,
            "mensaje" => "Nuevo enlace de activación generado con éxito (Válido por 72 horas).",
            "token" => $token,
            "enlace" => $enlace,
            "expira" => $expiracion
        ]);
        exit;
    }
    
    // -------------------------------------------------------------
    // ACCION: ACTUALIZAR LIMITES DEL ESCALAFON (ADMINISTRADOR)
    // -------------------------------------------------------------
    if ($action === 'update_escalafon') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(["exito" => false, "mensaje" => "Solo los administradores pueden reconfigurar el escalafón."]);
            exit;
        }
        
        $nivelesInput = $input['niveles'] ?? [];
        if (!is_array($nivelesInput) || count($nivelesInput) === 0) {
            echo json_encode(["exito" => false, "mensaje" => "Estructura de niveles inválida."]);
            exit;
        }
        
        $actualizados = 0;
        foreach ($nivelesInput as $nv) {
            $nId = intval($nv['id'] ?? 0);
            $minIns = intval($nv['min_inscritos'] ?? 0);
            $maxIns = intval($nv['max_inscritos'] ?? 0);
            $siglas = trim($nv['siglas'] ?? '');
            $nombre = trim($nv['nombre_nivel'] ?? '');
            $color = trim($nv['color_hex'] ?? '#0054A6');
            $desc = trim($nv['descripcion'] ?? '');
            
            if ($nId > 0 && !empty($siglas) && !empty($nombre)) {
                $stmt = $conn->prepare("UPDATE escalafon_niveles_ml SET 
                    siglas = ?, nombre_nivel = ?, min_inscritos = ?, max_inscritos = ?, color_hex = ?, descripcion = ? 
                    WHERE id = ?");
                $stmt->bind_param("ssiissi", $siglas, $nombre, $minIns, $maxIns, $color, $desc, $nId);
                if ($stmt->execute()) {
                    $actualizados++;
                }
                $stmt->close();
            }
        }
        
        // Recálculo masivo instantáneo de todos los MLs activos en la plataforma
        $qMLs = $conn->query("SELECT id FROM usuarios WHERE (role LIKE '%Militante%' OR perfil_id = 5)");
        $mlsRecalculados = 0;
        if ($qMLs) {
            while ($ml = $qMLs->fetch_assoc()) {
                recalcularNivelML($conn, intval($ml['id']));
                $mlsRecalculados++;
            }
        }
        
        // Registrar en auditoría
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detalles = "Límites del Escalafón ML actualizados por {$_SESSION['username']}. $actualizados niveles guardados, $mlsRecalculados MLs recalculados.";
        $conn->query("INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, detalles, ip_address)
                      VALUES ($currentUserId, 'UPDATE_ESCALAFON', 'escalafon_niveles_ml', '$detalles', '$ip')");
        
        echo json_encode([
            "exito" => true,
            "mensaje" => "Límites del Escalafón guardados exitosamente. Se recalcularon los rangos de $mlsRecalculados líderes en tiempo real.",
            "niveles_actualizados" => $actualizados,
            "lideres_recalculados" => $mlsRecalculados
        ]);
        exit;
    }
}

http_response_code(400);
echo json_encode(["exito" => false, "mensaje" => "Acción no reconocida."]);
