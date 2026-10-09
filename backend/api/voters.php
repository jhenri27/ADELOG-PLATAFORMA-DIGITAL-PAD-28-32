<?php
/**
 * API: Gestión del Padrón de Inscritos
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ValidadorDocumentos.php';
require_once __DIR__ . '/../Mailer.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$db = Database::getInstance();
$conn = $db->getConnection();

// Helper para validar permisos generales
function checkPerm($permissionName) {
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión."]);
        exit;
    }
    if ($_SESSION['role'] !== 'Administrador' && (!isset($_SESSION['perms'][$permissionName]) || $_SESSION['perms'][$permissionName] != 1)) {
        http_response_code(403);
        echo json_encode(["exito" => false, "mensaje" => "Acceso denegado. Permiso '$permissionName' requerido."]);
        exit;
    }
}

// Helper para validar que el elector pertenece a la Circunscripción 3 de Santo Domingo
function checkCircunscripcion($municipio, $sector, $recinto) {
    $muniUpper = strtoupper($municipio);
    $sectUpper = strtoupper($sector);
    $recUpper = strtoupper($recinto);
    
    $pertenece = false;
    
    // Municipios y distritos directos de Circunscripción 3
    if (str_contains($muniUpper, 'BOCA CHICA') || str_contains($sectUpper, 'BOCA CHICA') || str_contains($recUpper, 'BOCA CHICA') ||
        str_contains($muniUpper, 'GUERRA') || str_contains($sectUpper, 'GUERRA') || str_contains($recUpper, 'GUERRA') ||
        str_contains($muniUpper, 'SAN LUIS') || str_contains($sectUpper, 'SAN LUIS') || str_contains($sectUpper, 'BONITO') || str_contains($recUpper, 'SAN LUIS') ||
        str_contains($muniUpper, 'CALETA') || str_contains($sectUpper, 'CALETA') || str_contains($recUpper, 'CALETA')) {
        $pertenece = true;
    }
    
    // Santo Domingo Este (Sectores de Circunscripción 3)
    if (str_contains($muniUpper, 'SANTO DOMINGO ESTE') || str_contains($muniUpper, 'SDE') || str_contains($muniUpper, 'ESTE')) {
        if (!str_contains($muniUpper, 'OESTE') && !str_contains($muniUpper, 'NORTE')) {
            $pertenece = true;
        }
    }
    
    if (!$pertenece) {
        http_response_code(400);
        echo json_encode(["exito" => false, "mensaje" => "Error de Data Sucia: El elector no pertenece a la Circunscripción 3 de Santo Domingo. La plataforma solo permite inscribir votantes de SDE Región 3, Guerra, Boca Chica, San Luis y La Caleta para evitar datos erróneos."]);
        exit;
    }
}

function getCoordinatorsStats($conn, $nivelFiltro = 'all') {
    $usuariosSql = "SELECT u.id, u.nombre, u.role, u.perfil_id, p.nombre as perfil_nombre, p.nivel_jerarquico, u.telefono, u.email 
                    FROM usuarios u 
                    LEFT JOIN perfiles p ON u.perfil_id = p.id 
                    WHERE u.estado = 1";
    $resU = $conn->query($usuariosSql);
    $coords = [];

    if ($resU) {
        while ($u = $resU->fetch_assoc()) {
            $rol = $u['perfil_nombre'] ?: $u['role'];
            $coords[$u['nombre']] = [
                'id' => 'u_' . $u['id'],
                'nombre' => $u['nombre'],
                'tipo' => 'Líder / Coordinador',
                'rol' => $rol,
                'nivel_jerarquico' => intval($u['nivel_jerarquico'] ?: 2),
                'telefono' => $u['telefono'] ?: '',
                'email' => $u['email'] ?: '',
                'total_directos' => 0,
                'total_ml' => 0,
                'total_red' => 0,
                'meta' => 200
            ];
        }
    }

    $mlSql = "SELECT id, nombres, apellidos, cedula, telefono, email, coordinador, nivel_estructura, numero_lista 
              FROM inscritos 
              WHERE es_militante_lider = 1";
    $resML = $conn->query($mlSql);
    if ($resML) {
        while ($ml = $resML->fetch_assoc()) {
            $nombreCompleto = trim($ml['nombres'] . ' ' . $ml['apellidos']);
            $coords[$nombreCompleto] = [
                'id' => 'i_' . $ml['id'],
                'nombre' => $nombreCompleto,
                'cedula' => $ml['cedula'],
                'tipo' => 'ML - Militante Líder',
                'rol' => 'ML - Militante Líder',
                'coordinador_padre' => $ml['coordinador'],
                'nivel_jerarquico' => 4,
                'telefono' => $ml['telefono'] ?: '',
                'email' => $ml['email'] ?: '',
                'total_directos' => 0,
                'total_ml' => 0,
                'total_red' => 0,
                'meta' => 25
            ];
        }
    }

    $countsRes = $conn->query("SELECT coordinador, es_militante_lider, COUNT(*) as cant FROM inscritos GROUP BY coordinador, es_militante_lider");
    if ($countsRes) {
        while ($cRow = $countsRes->fetch_assoc()) {
            $cName = $cRow['coordinador'];
            $isML = intval($cRow['es_militante_lider']);
            $cant = intval($cRow['cant']);
            
            if (!isset($coords[$cName])) {
                $coords[$cName] = [
                    'id' => 'c_' . md5($cName),
                    'nombre' => $cName,
                    'tipo' => 'Coordinador',
                    'rol' => 'Coordinador',
                    'nivel_jerarquico' => 2,
                    'telefono' => '',
                    'email' => '',
                    'total_directos' => 0,
                    'total_ml' => 0,
                    'total_red' => 0,
                    'meta' => 150
                ];
            }
            
            if ($isML === 1) {
                $coords[$cName]['total_ml'] += $cant;
            } else {
                $coords[$cName]['total_directos'] += $cant;
            }
            $coords[$cName]['total_red'] += $cant;
        }
    }

    $listaFinal = array_values($coords);
    
    foreach ($listaFinal as &$item) {
        if ($item['rol'] === 'Coordinador General' || $item['nivel_jerarquico'] === 1) $item['meta'] = 500;
        elseif ($item['rol'] === 'Coordinador' || $item['nivel_jerarquico'] === 2) $item['meta'] = 200;
        elseif ($item['rol'] === 'Sub-coordinador' || $item['nivel_jerarquico'] === 3) $item['meta'] = 100;
        elseif ($item['rol'] === 'ML - Militante Líder' || $item['nivel_jerarquico'] === 4) $item['meta'] = 25;
        else $item['meta'] = 50;
        
        $item['porcentaje_meta'] = $item['meta'] > 0 ? round(($item['total_red'] / $item['meta']) * 100, 1) : 0;
    }
    unset($item);

    if ($nivelFiltro !== 'all' && !empty($nivelFiltro)) {
        $listaFinal = array_values(array_filter($listaFinal, function($c) use ($nivelFiltro) {
            return stripos($c['rol'], $nivelFiltro) !== false || stripos($c['tipo'], $nivelFiltro) !== false;
        }));
    }

    usort($listaFinal, function($a, $b) {
        return $b['total_red'] <=> $a['total_red'];
    });

    return $listaFinal;
}

// Permitir registros públicos si vienen de la campaña masiva QR o solicitud de comprobantes e impresión
$isPublicRegistration = ($method === 'POST' && $action === 'public_register') || ($method === 'GET' && in_array($action, ['email_voucher', 'detail', 'resolve_ref']));

if (!$isPublicRegistration) {
    // Si no es público, validar autenticación general
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión."]);
        exit;
    }
}

if ($method === 'GET') {
    if ($action === 'resolve_ref') {
        $ref = trim($_GET['ref'] ?? '');
        if (empty($ref)) {
            echo json_encode(["exito" => false, "mensaje" => "Código de referencia vacío."]);
            exit;
        }
        $refEsc = $conn->real_escape_string($ref);
        $cleanRef = preg_replace('/\D/', '', $ref);
        $whereRef = "codigo_ml = '$refEsc' OR username = '$refEsc'";
        if (is_numeric($ref)) {
            $whereRef .= " OR id = " . intval($ref);
        }
        $q = $conn->query("SELECT id, codigo_ml, username, nombre, role, perfil_id FROM usuarios WHERE $whereRef LIMIT 1");
        if ($q && $q->num_rows > 0) {
            $u = $q->fetch_assoc();
            $isML = ($u['perfil_id'] == 5 || stripos($u['role'], 'Militante') !== false || !empty($u['codigo_ml']));
            $isCoord = (stripos($u['role'], 'Coordinador') !== false || in_array(intval($u['perfil_id']), [2, 3, 4]));
            echo json_encode([
                "exito" => true,
                "usuario" => [
                    "id" => intval($u['id']),
                    "codigo_ml" => $u['codigo_ml'],
                    "username" => $u['username'],
                    "nombre" => $u['nombre'],
                    "role" => $u['role'],
                    "es_ml" => $isML,
                    "es_coordinador" => $isCoord
                ]
            ]);
        } else {
            echo json_encode(["exito" => false, "mensaje" => "Referente no encontrado."]);
        }
        exit;
    }

    if ($action === 'email_voucher') {
        $id = intval($_GET['id'] ?? 0);
        $email = trim($_GET['email'] ?? '');
        
        if ($id <= 0 || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "ID de elector o correo electrónico inválido."]);
            exit;
        }
        
        $idEsc = intval($id);
        $res = $conn->query("SELECT * FROM inscritos WHERE id = $idEsc LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            http_response_code(404);
            echo json_encode(["exito" => false, "mensaje" => "Elector no encontrado."]);
            exit;
        }
        $v = $res->fetch_assoc();
        
            $resConfig = $conn->query("SELECT * FROM configuraciones");
            if ($resConfig) {
                $configs = [];
                while ($row = $resConfig->fetch_assoc()) {
                    $configs[$row['clave']] = $row['valor'];
                }
                if (!empty($configs['candidato_nombre'])) $candidato_nombre = $configs['candidato_nombre'];
                if (!empty($configs['candidato_cargo'])) $candidato_cargo = $configs['candidato_cargo'];
            }
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $posBackend = strpos($script, '/backend');
        $baseDir = ($posBackend !== false) ? substr($script, 0, $posBackend) : '';
        $linkComprobante = rtrim("$protocol$host$baseDir", '/') . "/comprobante.php?id=" . $idEsc;
        
        // Cargar asunto de la base de datos
        $subjectRes = $conn->query("SELECT valor FROM configuraciones WHERE clave = 'flow_email_subject' LIMIT 1");
        $subjectTemplate = ($subjectRes && $subjectRes->num_rows > 0) 
            ? $subjectRes->fetch_assoc()['valor'] 
            : "";
        if (empty($subjectTemplate)) {
            $subjectTemplate = "Tu Constancia de Inscripción Padronal PAD/28-32";
        }
        
        // Cargar cuerpo de la base de datos
        $bodyRes = $conn->query("SELECT valor FROM configuraciones WHERE clave = 'flow_email_body' LIMIT 1");
        $bodyTemplate = ($bodyRes && $bodyRes->num_rows > 0) 
            ? $bodyRes->fetch_assoc()['valor'] 
            : "";
        
        if (empty($bodyTemplate)) {
            $bodyTemplate = "
<div style=\"background-color: #f1f5f9; padding: 30px 15px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;\">
    <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;\">
        <!-- Header -->
        <div style=\"background-color: #0054A6; padding: 25px; text-align: center; border-bottom: 4px solid #E3A113;\">
            <h1 style=\"color: #ffffff; margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 1px;\">ADELOG</h1>
            <p style=\"color: #cbd5e1; margin: 5px 0 0 0; font-size: 13px; text-transform: uppercase;\">Constancia Oficial de Inscripción</p>
        </div>
        
        <!-- Content -->
        <div style=\"padding: 30px 25px;\">
            <h2 style=\"color: #0f172a; margin-top: 0; margin-bottom: 10px; font-size: 20px; text-align: center;\">¡Gracias por tu Apoyo y Lealtad!</h2>
            <p style=\"font-size: 14px; color: #475569; text-align: center; margin-bottom: 25px; line-height: 1.5;\">
                Queremos expresarte nuestro más profundo agradecimiento por tu valioso apoyo y lealtad a la candidatura de la <strong>Pastora Altagracia</strong>. 
                Tu compromiso es el motor que nos impulsa a seguir trabajando incansablemente por el cambio y el desarrollo de nuestra gente.
            </p>
            
            <div style=\"background-color: #f8fafc; border: 1px solid #0054A6; border-left: 5px solid #0054A6; border-radius: 8px; padding: 20px; margin-bottom: 25px;\">
                <h4 style=\"margin-top: 0; margin-bottom: 15px; color: #0054A6; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;\">Detalles de la Inscripción</h4>
                <table style=\"width: 100%; border-collapse: collapse; font-size: 13px; color: #334155;\">
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold; width: 40%;\">Número de Lista:</td>
                        <td style=\"padding: 8px 0; text-align: right; font-weight: bold; color: #0f172a; font-size: 16px;\">#{numero_lista}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Cédula:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{cedula}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Nombre Completo:</td>
                        <td style=\"padding: 8px 0; text-align: right; font-weight: bold; color: #0054A6;\">{nombre_completo}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Colegio Electoral:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{colegio}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Recinto Electoral:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{recinto}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Región / Sector:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{region}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Coordinador:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{coordinador}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Centro de Acopio:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{centro_acopio}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Fecha de Registro:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{fecha}</td>
                    </tr>
                </table>
            </div>

            <div style=\"text-align: center; padding-top: 15px; border-top: 1px solid #f1f5f9;\">
                <p style=\"font-size: 13px; font-weight: bold; color: #0f172a; margin: 0 0 5px 0;\">Campaña Pastora Altagracia - PRM 2026</p>
                <p style=\"font-size: 11px; color: #94a3b8; margin: 0;\">Unidos por la transparencia, el cambio y el desarrollo.</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div style=\"background-color: #0f172a; padding: 15px; text-align: center; font-size: 11px; color: #64748b;\">
            Este documento constituye una constancia de inscripción oficial registrada en ADELOG.<br>
            Desarrollado para la administración de logisticas de comandos de campañas en RD, por sypempresariales . Copyright © 2026 Sypempresariales.
        </div>
    </div>
</div>";
        }
        
        // Reemplazar placeholders en la plantilla
        $placeholders = [
            '{numero_lista}' => $v['numero_lista'],
            '{cedula}' => $v['cedula'],
            '{nombre_completo}' => $v['nombres'] . " " . $v['apellidos'],
            '{colegio}' => $v['colegio_electoral'],
            '{recinto}' => $v['recinto_electoral'],
            '{region}' => $v['sector'] . ", " . $v['municipio'],
            '{coordinador}' => $v['coordinador'],
            '{centro_acopio}' => $v['centro_acopio'],
            '{fecha}' => date('d/m/Y h:i A')
        ];
        
        $emailBody = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTemplate);
        $emailSubject = str_replace(array_keys($placeholders), array_values($placeholders), $subjectTemplate);
        
        $exitoMail = Mailer::enviar($email, $emailSubject, $emailBody, true, $idEsc);
        if ($exitoMail) {
            echo json_encode(["exito" => true, "mensaje" => "Comprobante enviado por correo exitosamente."]);
        } else {
            http_response_code(500);
            echo json_encode(["exito" => false, "mensaje" => "No se pudo enviar el correo de comprobante."]);
        }
        exit;
    }

    if (!$isPublicRegistration) {
        checkPerm('can_view');
    }

    if ($action === 'coordinators_stats') {
        $nivel = trim($_GET['nivel'] ?? 'all');
        $stats = getCoordinatorsStats($conn, $nivel);
        echo json_encode(["exito" => true, "coordinadores" => $stats]);
        exit;
    }

    if ($action === 'network_voters') {
        $sessUserId = intval($_SESSION['usuario_id'] ?? 0);
        $sessRole = trim($_SESSION['role'] ?? '');
        $sessPerfilId = intval($_SESSION['perfil_id'] ?? 0);
        $sessNombre = trim($_SESSION['nombre'] ?? '');
        $sessCodigoML = trim($_SESSION['codigo_ml'] ?? '');

        $isSuperior = ($sessPerfilId === 1 || $sessPerfilId === 2 || 
                       $sessRole === 'Administrador' || 
                       $sessRole === 'Coordinador General' || 
                       $sessRole === 'Jefe Electoral');

        $coordName = trim($_GET['coordinador'] ?? '');
        $nivelFiltro = trim($_GET['nivel'] ?? 'all');
        
        $whereNet = ["periodo = '2028'"];
        
        if (!$isSuperior) {
            $nombreEsc = $conn->real_escape_string($sessNombre);
            $codMLEsc = $conn->real_escape_string($sessCodigoML);
            if ($sessRole === 'Digitador' || $sessPerfilId === 6) {
                $whereNet[] = "registrado_por = $sessUserId";
            } elseif ($sessRole === 'ML - Militante Líder' || $sessPerfilId === 5 || !empty($sessCodigoML)) {
                $whereNet[] = "(registrado_por = $sessUserId OR referido_por_ml_id = $sessUserId" . (!empty($codMLEsc) ? " OR codigo_ref_origen = '$codMLEsc'" : "") . ")";
            } elseif ($sessRole === 'Coordinador' || $sessRole === 'Sub-coordinador' || in_array($sessPerfilId, [3, 4])) {
                $whereNet[] = "(coordinador = '$nombreEsc' OR registrado_por = $sessUserId OR coordinador_padre_id = $sessUserId)";
            } else {
                $whereNet[] = "registrado_por = $sessUserId";
            }
        } elseif (!empty($coordName)) {
            $cEsc = $conn->real_escape_string($coordName);
            $whereNet[] = "(coordinador = '$cEsc' OR nombres LIKE '%$cEsc%' OR apellidos LIKE '%$cEsc%')";
        }
        if ($nivelFiltro === 'ML' || $nivelFiltro === 'ML - Militante Líder') {
            $whereNet[] = "es_militante_lider = 1";
        } elseif ($nivelFiltro === 'Votante') {
            $whereNet[] = "es_militante_lider = 0";
        }
        
        $whereSqlNet = "WHERE " . implode(" AND ", $whereNet);
        $resNet = $conn->query("SELECT id, numero_lista, cedula, nombres, apellidos, colegio_electoral, recinto_ubicacion, sector, municipio, telefono, email, coordinador, centro_acopio, canal_origen, fecha_registro, es_militante_lider, nivel_estructura 
                                FROM inscritos 
                                $whereSqlNet 
                                ORDER BY numero_lista DESC LIMIT 200");
        $votersNet = [];
        if ($resNet) {
            while ($r = $resNet->fetch_assoc()) {
                $r['codigo_comprobante'] = "PAD2832-" . $r['numero_lista'] . "-" . $r['cedula'];
                $votersNet[] = $r;
            }
        }
        echo json_encode(["exito" => true, "total" => count($votersNet), "votantes" => $votersNet, "voters" => $votersNet]);
        exit;
    }
    
    if ($action === 'list') {
        // Padrón en tiempo real: búsqueda y filtros
        $search = trim($_GET['search'] ?? '');
        $region = trim($_GET['region'] ?? '');
        $coordinador = trim($_GET['coordinador'] ?? '');
        $centro_acopio = trim($_GET['centro_acopio'] ?? '');
        $periodo = trim($_GET['periodo'] ?? '2028');
        $nivel_estructura = trim($_GET['nivel_estructura'] ?? '');
        $tipo_elector = trim($_GET['tipo_elector'] ?? '');
        
        if ($periodo === '2024') {
            checkPerm('can_view_historical');
        }
        
        $whereClauses = ["periodo = '" . $conn->real_escape_string($periodo) . "'"];
        
        if (!empty($search)) {
            $sEsc = $conn->real_escape_string($search);
            $whereClauses[] = "(cedula LIKE '%$sEsc%' OR nombres LIKE '%$sEsc%' OR apellidos LIKE '%$sEsc%' OR colegio_electoral LIKE '%$sEsc%' OR sector LIKE '%$sEsc%' OR municipio LIKE '%$sEsc%')";
        }
        if (!empty($region)) {
            $rEsc = $conn->real_escape_string($region);
            $whereClauses[] = "(sector LIKE '%$rEsc%' OR recinto_ubicacion LIKE '%$rEsc%' OR municipio LIKE '%$rEsc%')";
        }
        if (!empty($coordinador)) {
            $cEsc = $conn->real_escape_string($coordinador);
            $whereClauses[] = "coordinador = '$cEsc'";
        }
        if (!empty($centro_acopio)) {
            $caEsc = $conn->real_escape_string($centro_acopio);
            $whereClauses[] = "centro_acopio = '$caEsc'";
        }
        if ($tipo_elector === 'ML' || $tipo_elector === '1') {
            $whereClauses[] = "es_militante_lider = 1";
        } elseif ($tipo_elector === 'Votante' || $tipo_elector === '0') {
            $whereClauses[] = "es_militante_lider = 0";
        }
        $registrado_por = intval($_GET['registrado_por'] ?? 0);
        if ($registrado_por > 0) {
            $whereClauses[] = "registrado_por = $registrado_por";
        }
        if (!empty($nivel_estructura) && $nivel_estructura !== 'all') {
            $neEsc = $conn->real_escape_string($nivel_estructura);
            $whereClauses[] = "nivel_estructura = '$neEsc'";
        }
        
        // -------------------------------------------------------------
        // CONTROL ESTRICTO DE VISIBILIDAD POR JERARQUÍA DE ROL (PLAD-SEC-01)
        // -------------------------------------------------------------
        // Perfiles Superiores (Administrador, Coordinador General, Jefe Electoral):
        // Capacidad de ver TODO el universo de inscritos de toda la demarcación.
        // Otros perfiles (Digitador, Militante Líder ML, Coordinadores de Zona):
        // Únicamente tienen visibilidad de sus propios inscritos o su red territorial.
        $sessUserId = intval($_SESSION['usuario_id'] ?? 0);
        $sessRole = trim($_SESSION['role'] ?? '');
        $sessPerfilId = intval($_SESSION['perfil_id'] ?? 0);
        $sessNombre = trim($_SESSION['nombre'] ?? '');
        $sessCodigoML = trim($_SESSION['codigo_ml'] ?? '');

        $isSuperior = ($sessPerfilId === 1 || $sessPerfilId === 2 || 
                       $sessRole === 'Administrador' || 
                       $sessRole === 'Coordinador General' || 
                       $sessRole === 'Jefe Electoral');

        if (!$isSuperior) {
            $nombreEsc = $conn->real_escape_string($sessNombre);
            $codMLEsc = $conn->real_escape_string($sessCodigoML);

            if ($sessRole === 'Digitador' || $sessPerfilId === 6) {
                // Digitador: Únicamente los electores registrados por este usuario
                $whereClauses[] = "registrado_por = $sessUserId";
            } elseif ($sessRole === 'ML - Militante Líder' || $sessPerfilId === 5 || !empty($sessCodigoML)) {
                // Militante Líder (ML): Únicamente sus captaciones directas o referidas a su red
                $whereClauses[] = "(registrado_por = $sessUserId OR referido_por_ml_id = $sessUserId" . (!empty($codMLEsc) ? " OR codigo_ref_origen = '$codMLEsc'" : "") . ")";
            } elseif ($sessRole === 'Coordinador' || $sessRole === 'Sub-coordinador' || in_array($sessPerfilId, [3, 4])) {
                // Coordinador / Sub-coordinador: Sus inscritos directos y los asignados a su coordinación
                $whereClauses[] = "(coordinador = '$nombreEsc' OR registrado_por = $sessUserId OR coordinador_padre_id = $sessUserId)";
            } else {
                // Fallback de seguridad para cualquier otro perfil no superior
                $whereClauses[] = "registrado_por = $sessUserId";
            }
        }
        
        $whereSql = "";
        if (count($whereClauses) > 0) {
            $whereSql = "WHERE " . implode(" AND ", $whereClauses);
        }
        
        // Contar total con filtros
        $countRes = $conn->query("SELECT COUNT(*) as total FROM inscritos $whereSql");
        $totalRows = $countRes->fetch_assoc()['total'];
        
        // Paginación simple
        $page = intval($_GET['page'] ?? 1);
        if ($page < 1) $page = 1;
        $limit = 100; // Máximo 100 registros por página
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT id, numero_lista, cedula, nombres, apellidos, nacionalidad, colegio_electoral, recinto_ubicacion, direccion, sector, municipio, telefono, telefono_fijo, email, coordinador, centro_acopio, canal_origen, fecha_registro, es_militante_lider, nivel_estructura 
                FROM inscritos 
                $whereSql 
                ORDER BY numero_lista DESC 
                LIMIT $limit OFFSET $offset";
                
        $res = $conn->query($sql);
        $voters = [];
        
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['codigo_comprobante'] = "PAD2832-" . $row['numero_lista'] . "-" . $row['cedula'];
                $voters[] = $row;
            }
        }
        
        // Auditoría de consulta masiva (ISO 27001 / ISO 54001)
        $userId = intval($_SESSION['usuario_id']);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detalles = "Consultó el padrón en tiempo real (Filtros: búsqueda='$search', region='$region', coord='$coordinador'). Registros devueltos: " . count($voters);
        $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'VIEW_PADRON', ?, ?)");
        $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
        $stmtAudit->execute();
        $stmtAudit->close();
        
        echo json_encode([
            "exito" => true,
            "total" => intval($totalRows),
            "pagina" => $page,
            "limite" => $limit,
            "votantes" => $voters,
            "voters" => $voters
        ]);
        exit;
    }
    
    if ($action === 'detail') {
        $id = intval($_GET['id'] ?? 0);
        $sqlDetail = "
            SELECT i.*, u.nombre as registrado_por_nombre, p.nombre as perfil_registrador
            FROM inscritos i
            LEFT JOIN usuarios u ON i.registrado_por = u.id
            LEFT JOIN perfiles p ON u.perfil_id = p.id
            WHERE i.id = $id LIMIT 1
        ";
        $res = $conn->query($sqlDetail);
        if ($res && $res->num_rows > 0) {
            $v = $res->fetch_assoc();
            $v['codigo_comprobante'] = "PAD2832-" . $v['numero_lista'] . "-" . $v['cedula'];
            if (empty($v['tipo_elector'])) {
                $v['tipo_elector'] = !empty($v['es_militante_lider']) ? 'Nuevo Elector (ML)' : 'Nuevo Elector';
            }
            
            // Cruce con Padrón Maestro de Partido
            $cleanCed = preg_replace('/[^0-9]/', '', $v['cedula']);
            $cedEsc = $conn->real_escape_string($v['cedula']);
            $resPm = $conn->query("SELECT * FROM padron_maestro_consulta WHERE cedula = '$cedEsc' OR REPLACE(cedula, '-', '') = '$cleanCed' LIMIT 1");
            if ($resPm && $resPm->num_rows > 0) {
                $pm = $resPm->fetch_assoc();
                $v['en_padron_partido'] = true;
                $v['militancia_partido_label'] = (!empty($pm['militancia_prm']) && $pm['militancia_prm'] == 1) ? 'Militante Vigente (PRM)' : 'Militante Histórico';
                if (!empty($pm['militancia_historica']) && trim($pm['militancia_historica']) !== '') {
                    $v['militancia_partido_label'] .= ' [' . trim($pm['militancia_historica']) . ']';
                }
                $v['codigo_recinto'] = $pm['codigo_recinto'] ?? '';
                $v['posicion_recinto'] = $pm['posicion_recinto'] ?? '';
            } else {
                // Fallback padron_consulta_circ3
                $resC3 = $conn->query("SELECT * FROM padron_consulta_circ3 WHERE cedula = '$cedEsc' OR REPLACE(cedula, '-', '') = '$cleanCed' LIMIT 1");
                if ($resC3 && $resC3->num_rows > 0) {
                    $c3 = $resC3->fetch_assoc();
                    $v['en_padron_partido'] = true;
                    $v['militancia_partido_label'] = 'Militante Circunscripción 3 (PRM)';
                    $v['codigo_recinto'] = '';
                    $v['posicion_recinto'] = $c3['zona'] ?? '';
                } else {
                    $v['en_padron_partido'] = false;
                    $v['militancia_partido_label'] = 'No figura en Padrón Partido (Independiente / Externo)';
                }
            }
            
            // Detección precisa de circunscripción electoral
            $circunscripcionElector = 'Circunscripción 3 (SDE)';
            $secUp = strtoupper($v['sector'] ?? '');
            $recUp = strtoupper($v['recinto_ubicacion'] ?? '');
            $munUp = strtoupper($v['municipio'] ?? '');
            $colNum = trim($v['colegio_electoral'] ?? '');
            
            if ($secUp === 'ISABELITA' || strpos($recUp, 'ISABELITA') !== false || $colNum === '1823') {
                $circunscripcionElector = 'Circunscripción 1 (Santo Domingo Este)';
            } elseif (in_array($secUp, ['ENSANCHE OZAMA', 'ALMA ROSA', 'VILLA DUARTE', 'LOS MAMEYES', 'LOS TRES OJOS', 'CALERO', 'MAQUITERIA'])) {
                $circunscripcionElector = 'Circunscripción 1 (Santo Domingo Este)';
            } elseif (in_array($secUp, ['LOS MINA', 'CANCINO', 'KATANGA', 'PUERTO RICO', 'VIETNAM', 'LUCERNA'])) {
                $circunscripcionElector = 'Circunscripción 2 (Santo Domingo Este)';
            } elseif ($munUp === 'SANTO DOMINGO NORTE' || strpos($secUp, 'SABANA PERDIDA') !== false || strpos($secUp, 'VILLA MELLA') !== false) {
                $circunscripcionElector = 'Santo Domingo Norte (Circ. 6)';
            } elseif ($munUp === 'DISTRITO NACIONAL') {
                $circunscripcionElector = 'Distrito Nacional';
            } elseif ($munUp === 'SAN ANTONIO DE GUERRA') {
                $circunscripcionElector = 'Guerra (Circ. 3)';
            } elseif ($munUp === 'BOCA CHICA') {
                $circunscripcionElector = 'Boca Chica (Circ. 3)';
            }
            
            $esFueraDeCirc3 = ($circunscripcionElector !== 'Circunscripción 3 (SDE)' && $circunscripcionElector !== 'Guerra (Circ. 3)' && $circunscripcionElector !== 'Boca Chica (Circ. 3)');
            $v['circunscripcion_elector'] = $circunscripcionElector;
            $v['es_fuera_circ3'] = $esFueraDeCirc3;
            if ($esFueraDeCirc3 && !$v['en_padron_partido']) {
                $v['militancia_partido_label'] = 'No figura en Padrón Circ. 3 (Elector ' . $circunscripcionElector . ')';
                $v['tipo_elector'] = 'Nuevo Elector (Simpatizante Externo)';
            }
            
            echo json_encode(["exito" => true, "votante" => $v]);
        } else {
            http_response_code(404);
            echo json_encode(["exito" => false, "mensaje" => "Votante no encontrado."]);
        }
        exit;
    }

    if ($action === 'query_2024') {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión para consultar el estatus del elector."]);
            exit;
        }
        $search = trim($_GET['search'] ?? $_POST['search'] ?? $_GET['cedula'] ?? $_POST['cedula'] ?? '');
        if (empty($search)) {
            echo json_encode(["exito" => true, "total" => 0, "votantes" => []]);
            exit;
        }
        
        $searchCleanDigits = preg_replace('/\D/', '', $search);
        $searchEsc = $conn->real_escape_string($search);
        
        $voters = [];
        $cedulasVistas = [];
        
        // 1. Si el término de búsqueda parece una cédula (o fragmento numérico de 9+ dígitos)
        $isCedulaLookup = (strlen($searchCleanDigits) >= 9);
        
        if ($isCedulaLookup) {
            $cedFormatted = $search;
            if (strlen($searchCleanDigits) === 11) {
                $cedFormatted = substr($searchCleanDigits, 0, 3) . '-' . substr($searchCleanDigits, 3, 7) . '-' . substr($searchCleanDigits, 10, 1);
            }
            $cedEsc = $conn->real_escape_string($cedFormatted);
            $cleanEsc = $conn->real_escape_string($searchCleanDigits);
            
            // Buscar en Padrón Maestro Oficial JCE / PRM
            $sqlPm = "
                SELECT pm.*, cr.direccion_recinto as dir_recinto_oficial, cr.es_nuevo as recinto_es_nuevo
                FROM padron_maestro_consulta pm
                LEFT JOIN catalogo_recintos_jce cr ON pm.codigo_recinto = cr.codigo_recinto
                WHERE pm.cedula = '$cedEsc' OR REPLACE(pm.cedula, '-', '') = '$cleanEsc'
                LIMIT 1
            ";
            $resPm = $conn->query($sqlPm);
            
            // Buscar en Padrón Activo de la Candidata (inscritos)
            $sqlIns = "
                SELECT i.*, u.nombre as nombre_registrador, p.nombre as perfil_registrador 
                FROM inscritos i
                LEFT JOIN usuarios u ON i.registrado_por = u.id
                LEFT JOIN perfiles p ON u.perfil_id = p.id
                WHERE i.cedula = '$cedEsc' OR REPLACE(i.cedula, '-', '') = '$cleanEsc'
                LIMIT 1
            ";
            $resIns = $conn->query($sqlIns);
            
            $enPartido = ($resPm && $resPm->num_rows > 0);
            $enCandidata = ($resIns && $resIns->num_rows > 0);
            
            $pmData = $enPartido ? $resPm->fetch_assoc() : null;
            $insData = $enCandidata ? $resIns->fetch_assoc() : null;
            
            // Si no estuvo en padron_maestro_consulta, verificar contingencia en padron_consulta_circ3
            if (!$enPartido) {
                $sqlC3 = "SELECT * FROM padron_consulta_circ3 WHERE cedula = '$cedEsc' OR REPLACE(cedula, '-', '') = '$cleanEsc' LIMIT 1";
                $resC3 = $conn->query($sqlC3);
                if ($resC3 && $resC3->num_rows > 0) {
                    $enPartido = true;
                    $c3Data = $resC3->fetch_assoc();
                    $pmData = [
                        'cedula' => $c3Data['cedula'],
                        'nombres' => $c3Data['nombres'],
                        'apellidos' => trim($c3Data['apellido1'] . ' ' . $c3Data['apellido2']),
                        'colegio_electoral' => $c3Data['colegio_electoral'] ?? '',
                        'codigo_recinto' => '',
                        'nombre_recinto' => $c3Data['recinto'],
                        'sector' => $c3Data['sector'],
                        'municipio' => $c3Data['municipio'],
                        'posicion_recinto' => $c3Data['zona'] ?? '',
                        'militancia_prm' => 1,
                        'militancia_historica' => 'Circunscripción 3'
                    ];
                }
            }
            
            if ($enPartido || $enCandidata) {
                $cedulaFinal = $insData['cedula'] ?? $pmData['cedula'] ?? $cedFormatted;
                $nombresFinal = $insData['nombres'] ?? $pmData['nombres'] ?? '';
                $apellidosFinal = $insData['apellidos'] ?? $pmData['apellidos'] ?? '';
                $colegioFinal = $insData['colegio_electoral'] ?? $pmData['colegio_electoral'] ?? '';
                $recintoFinal = $insData['recinto_ubicacion'] ?? $pmData['nombre_recinto'] ?? '';
                $sectorFinal = $insData['sector'] ?? $pmData['sector'] ?? '';
                $municipioFinal = $insData['municipio'] ?? $pmData['municipio'] ?? 'SANTO DOMINGO ESTE';
                
                $estadoDiag = 'NO_LOCALIZADO';
                if ($enPartido && $enCandidata) {
                    $estadoDiag = 'COMPROMETIDO'; // Estado 1: Militante Comprometido (Nuevo Elector Registrado)
                } elseif ($enPartido && !$enCandidata) {
                    $estadoDiag = 'NO_CAPTADO';   // Estado 2: Militante Partido No Captado (Objetivo Estratégico)
                } elseif (!$enPartido && $enCandidata) {
                    $estadoDiag = 'EXTERNO';      // Estado 3: Simpatizante Externo / Nuevo Elector Independiente
                }
                
                $militanciaLabel = 'No figura en Padrón Partido';
                if ($enPartido) {
                    $militanciaLabel = (!empty($pmData['militancia_prm']) && $pmData['militancia_prm'] == 1) ? 'Militante Vigente (PRM)' : 'Militante Histórico';
                    if (!empty($pmData['militancia_historica']) && trim($pmData['militancia_historica']) !== '') {
                        $militanciaLabel .= ' [' . trim($pmData['militancia_historica']) . ']';
                    }
                }
                
                $tipoElectorLabel = $enCandidata ? ($insData['tipo_elector'] ?: 'Nuevo Elector') : 'Pendiente de Captar';
                
                $circunscripcionElector = 'Circunscripción 3 (SDE)';
                $secUp = strtoupper($sectorFinal);
                $recUp = strtoupper($recintoFinal);
                $munUp = strtoupper($municipioFinal);
                $colNum = trim($colegioFinal);

                if ($secUp === 'ISABELITA' || strpos($recUp, 'ISABELITA') !== false || $colNum === '1823') {
                    $circunscripcionElector = 'Circunscripción 1 (Santo Domingo Este)';
                } elseif (in_array($secUp, ['ENSANCHE OZAMA', 'ALMA ROSA', 'VILLA DUARTE', 'LOS MAMEYES', 'LOS TRES OJOS', 'CALERO', 'MAQUITERIA'])) {
                    $circunscripcionElector = 'Circunscripción 1 (Santo Domingo Este)';
                } elseif (in_array($secUp, ['LOS MINA', 'CANCINO', 'KATANGA', 'PUERTO RICO', 'VIETNAM', 'LUCERNA'])) {
                    $circunscripcionElector = 'Circunscripción 2 (Santo Domingo Este)';
                } elseif ($munUp === 'SANTO DOMINGO NORTE' || strpos($secUp, 'SABANA PERDIDA') !== false || strpos($secUp, 'VILLA MELLA') !== false) {
                    $circunscripcionElector = 'Santo Domingo Norte (Circ. 6)';
                } elseif ($munUp === 'DISTRITO NACIONAL') {
                    $circunscripcionElector = 'Distrito Nacional';
                } elseif ($munUp === 'SAN ANTONIO DE GUERRA') {
                    $circunscripcionElector = 'Guerra (Circ. 3)';
                } elseif ($munUp === 'BOCA CHICA') {
                    $circunscripcionElector = 'Boca Chica (Circ. 3)';
                }

                $esFueraDeCirc3 = ($circunscripcionElector !== 'Circunscripción 3 (SDE)' && $circunscripcionElector !== 'Guerra (Circ. 3)' && $circunscripcionElector !== 'Boca Chica (Circ. 3)');
                
                if ($esFueraDeCirc3 && $enCandidata && !$enPartido) {
                    $estadoDiag = 'EXTERNO';
                    $militanciaLabel = 'No figura en Padrón Circ. 3 (Elector ' . $circunscripcionElector . ')';
                    $tipoElectorLabel = 'Nuevo Elector (Simpatizante Externo)';
                }
                
                $voters[] = [
                    'id' => $insData['id'] ?? null,
                    'cedula' => $cedulaFinal,
                    'nombres' => $nombresFinal,
                    'apellidos' => $apellidosFinal,
                    'nombre_completo' => trim($nombresFinal . ' ' . $apellidosFinal),
                    'colegio_electoral' => $colegioFinal,
                    'codigo_recinto' => $pmData['codigo_recinto'] ?? '',
                    'recinto_ubicacion' => $recintoFinal,
                    'posicion_recinto' => $pmData['posicion_recinto'] ?? '',
                    'numero_orden' => $pmData['numero_orden'] ?? '',
                    'sector' => $sectorFinal,
                    'municipio' => $municipioFinal,
                    'circunscripcion_elector' => $circunscripcionElector,
                    'es_fuera_circ3' => $esFueraDeCirc3,
                    'direccion' => $insData['direccion'] ?? '',
                    'telefono' => $insData['telefono'] ?? $pmData['celular'] ?? $pmData['telefono_fijo'] ?? '',
                    'email' => $insData['email'] ?? '',
                    'coordinador' => $insData['coordinador'] ?? 'No Asignado',
                    'registrado_por' => $insData['nombre_registrador'] ?? ($insData['registrado_por'] ? 'Usuario #' . $insData['registrado_por'] : 'N/A'),
                    'perfil_registrador' => $insData['perfil_registrador'] ?? 'N/A',
                    'canal_origen' => $insData['canal_origen'] ?? 'Padrón Maestro',
                    'fecha_registro' => $insData['fecha_registro'] ?? $pmData['fecha_ingesta'] ?? null,
                    'periodo' => $insData['periodo'] ?? '2028',
                    'numero_lista' => $insData['numero_lista'] ?? null,
                    'codigo_comprobante' => !empty($insData['numero_lista']) ? ("PAD2832-" . $insData['numero_lista'] . "-" . $insData['cedula']) : null,
                    'en_padron_partido' => $enPartido,
                    'en_padron_candidata' => $enCandidata,
                    'estado_diagnostico' => $estadoDiag,
                    'militancia_partido_label' => $militanciaLabel,
                    'tipo_elector_label' => $tipoElectorLabel,
                    'es_militante_lider' => intval($insData['es_militante_lider'] ?? 0)
                ];
            }
        } else {
            // Búsqueda por Nombre / Apellidos / Texto en ambas bases
            $sqlText = "
                SELECT pm.*, cr.direccion_recinto as dir_recinto_oficial
                FROM padron_maestro_consulta pm
                LEFT JOIN catalogo_recintos_jce cr ON pm.codigo_recinto = cr.codigo_recinto
                WHERE pm.nombres LIKE '%$searchEsc%' 
                   OR pm.apellidos LIKE '%$searchEsc%'
                   OR CONCAT(pm.nombres, ' ', pm.apellidos) LIKE '%$searchEsc%'
                   OR pm.colegio_electoral = '$searchEsc'
                LIMIT 25
            ";
            $resText = $conn->query($sqlText);
            
            if ($resText) {
                while ($pmRow = $resText->fetch_assoc()) {
                    $cEsc = $conn->real_escape_string($pmRow['cedula']);
                    $cleanC = preg_replace('/\D/', '', $pmRow['cedula']);
                    $cedulasVistas[$pmRow['cedula']] = true;
                    
                    $sqlCheckIns = "
                        SELECT i.*, u.nombre as nombre_registrador, p.nombre as perfil_registrador 
                        FROM inscritos i
                        LEFT JOIN usuarios u ON i.registrado_por = u.id
                        LEFT JOIN perfiles p ON u.perfil_id = p.id
                        WHERE i.cedula = '$cEsc' OR REPLACE(i.cedula, '-', '') = '$cleanC'
                        LIMIT 1
                    ";
                    $resCheckIns = $conn->query($sqlCheckIns);
                    $enCandidata = ($resCheckIns && $resCheckIns->num_rows > 0);
                    $insRow = $enCandidata ? $resCheckIns->fetch_assoc() : null;
                    
                    $militanciaLabel = (!empty($pmRow['militancia_prm']) && $pmRow['militancia_prm'] == 1) ? 'Militante Vigente (PRM)' : 'Militante Histórico';
                    if (!empty($pmRow['militancia_historica']) && trim($pmRow['militancia_historica']) !== '') {
                        $militanciaLabel .= ' [' . trim($pmRow['militancia_historica']) . ']';
                    }
                    
                    $voters[] = [
                        'id' => $insRow['id'] ?? null,
                        'cedula' => $pmRow['cedula'],
                        'nombres' => $pmRow['nombres'],
                        'apellidos' => $pmRow['apellidos'],
                        'nombre_completo' => trim($pmRow['nombres'] . ' ' . $pmRow['apellidos']),
                        'colegio_electoral' => $pmRow['colegio_electoral'],
                        'codigo_recinto' => $pmRow['codigo_recinto'] ?? '',
                        'recinto_ubicacion' => $pmRow['nombre_recinto'] ?? '',
                        'posicion_recinto' => $pmRow['posicion_recinto'] ?? '',
                        'numero_orden' => $pmRow['numero_orden'] ?? '',
                        'sector' => $pmRow['sector'] ?? '',
                        'municipio' => $pmRow['municipio'] ?? 'SANTO DOMINGO ESTE',
                        'telefono' => $insRow['telefono'] ?? $pmRow['celular'] ?? $pmRow['telefono_fijo'] ?? '',
                        'email' => $insRow['email'] ?? '',
                        'coordinador' => $insRow['coordinador'] ?? 'No Asignado',
                        'registrado_por' => $insRow['nombre_registrador'] ?? 'N/A',
                        'perfil_registrador' => $insRow['perfil_registrador'] ?? 'N/A',
                        'canal_origen' => $insRow['canal_origen'] ?? 'Padrón Maestro',
                        'fecha_registro' => $insRow['fecha_registro'] ?? $pmRow['fecha_ingesta'] ?? null,
                        'periodo' => $insRow['periodo'] ?? '2028',
                        'numero_lista' => $insRow['numero_lista'] ?? null,
                        'codigo_comprobante' => !empty($insRow['numero_lista']) ? ("PAD2832-" . $insRow['numero_lista'] . "-" . $insRow['cedula']) : null,
                        'en_padron_partido' => true,
                        'en_padron_candidata' => $enCandidata,
                        'estado_diagnostico' => $enCandidata ? 'COMPROMETIDO' : 'NO_CAPTADO',
                        'militancia_partido_label' => $militanciaLabel,
                        'tipo_elector_label' => $enCandidata ? ($insRow['tipo_elector'] ?: 'Nuevo Elector') : 'Pendiente de Captar',
                        'es_militante_lider' => intval($insRow['es_militante_lider'] ?? 0)
                    ];
                }
            }
            
            // Buscar también en inscritos por si hay simpatizantes independientes
            $sqlInsText = "
                SELECT i.*, u.nombre as nombre_registrador, p.nombre as perfil_registrador 
                FROM inscritos i
                LEFT JOIN usuarios u ON i.registrado_por = u.id
                LEFT JOIN perfiles p ON u.perfil_id = p.id
                WHERE i.nombres LIKE '%$searchEsc%' 
                   OR i.apellidos LIKE '%$searchEsc%'
                   OR CONCAT(i.nombres, ' ', i.apellidos) LIKE '%$searchEsc%'
                LIMIT 15
            ";
            $resInsText = $conn->query($sqlInsText);
            if ($resInsText) {
                while ($insRow = $resInsText->fetch_assoc()) {
                    if (isset($cedulasVistas[$insRow['cedula']])) continue;
                    
                    $circunscripcionElector = 'Circunscripción 3 (SDE)';
                    $secUp = strtoupper($insRow['sector'] ?? '');
                    $recUp = strtoupper($insRow['recinto_ubicacion'] ?? '');
                    $munUp = strtoupper($insRow['municipio'] ?? '');
                    $colNum = trim($insRow['colegio_electoral'] ?? '');

                    if ($secUp === 'ISABELITA' || strpos($recUp, 'ISABELITA') !== false || $colNum === '1823') {
                        $circunscripcionElector = 'Circunscripción 1 (Santo Domingo Este)';
                    } elseif (in_array($secUp, ['ENSANCHE OZAMA', 'ALMA ROSA', 'VILLA DUARTE', 'LOS MAMEYES', 'LOS TRES OJOS', 'CALERO', 'MAQUITERIA'])) {
                        $circunscripcionElector = 'Circunscripción 1 (Santo Domingo Este)';
                    } elseif (in_array($secUp, ['LOS MINA', 'CANCINO', 'KATANGA', 'PUERTO RICO', 'VIETNAM', 'LUCERNA'])) {
                        $circunscripcionElector = 'Circunscripción 2 (Santo Domingo Este)';
                    } elseif ($munUp === 'SANTO DOMINGO NORTE' || strpos($secUp, 'SABANA PERDIDA') !== false || strpos($secUp, 'VILLA MELLA') !== false) {
                        $circunscripcionElector = 'Santo Domingo Norte (Circ. 6)';
                    } elseif ($munUp === 'DISTRITO NACIONAL') {
                        $circunscripcionElector = 'Distrito Nacional';
                    } elseif ($munUp === 'SAN ANTONIO DE GUERRA') {
                        $circunscripcionElector = 'Guerra (Circ. 3)';
                    } elseif ($munUp === 'BOCA CHICA') {
                        $circunscripcionElector = 'Boca Chica (Circ. 3)';
                    }

                    $esFueraDeCirc3 = ($circunscripcionElector !== 'Circunscripción 3 (SDE)' && $circunscripcionElector !== 'Guerra (Circ. 3)' && $circunscripcionElector !== 'Boca Chica (Circ. 3)');
                    $militanciaPartLabel = $esFueraDeCirc3 ? ('No figura en Padrón Circ. 3 (Elector ' . $circunscripcionElector . ')') : 'No figura en Padrón Partido';
                    $tagElectorLabel = $esFueraDeCirc3 ? 'Nuevo Elector (Simpatizante Externo)' : ($insRow['tipo_elector'] ?: 'Nuevo Elector Independiente');

                    $voters[] = [
                        'id' => $insRow['id'],
                        'cedula' => $insRow['cedula'],
                        'nombres' => $insRow['nombres'],
                        'apellidos' => $insRow['apellidos'],
                        'nombre_completo' => trim($insRow['nombres'] . ' ' . $insRow['apellidos']),
                        'colegio_electoral' => $insRow['colegio_electoral'],
                        'codigo_recinto' => '',
                        'recinto_ubicacion' => $insRow['recinto_ubicacion'],
                        'posicion_recinto' => '',
                        'numero_orden' => '',
                        'sector' => $insRow['sector'],
                        'municipio' => $insRow['municipio'],
                        'circunscripcion_elector' => $circunscripcionElector,
                        'es_fuera_circ3' => $esFueraDeCirc3,
                        'direccion' => $insRow['direccion'] ?? '',
                        'telefono' => $insRow['telefono'],
                        'email' => $insRow['email'],
                        'coordinador' => $insRow['coordinador'],
                        'registrado_por' => $insRow['nombre_registrador'] ?? 'N/A',
                        'perfil_registrador' => $insRow['perfil_registrador'] ?? 'N/A',
                        'canal_origen' => $insRow['canal_origen'],
                        'fecha_registro' => $insRow['fecha_registro'],
                        'periodo' => $insRow['periodo'],
                        'numero_lista' => $insRow['numero_lista'],
                        'codigo_comprobante' => "PAD2832-" . $insRow['numero_lista'] . "-" . $insRow['cedula'],
                        'en_padron_partido' => false,
                        'en_padron_candidata' => true,
                        'estado_diagnostico' => 'EXTERNO',
                        'militancia_partido_label' => $militanciaPartLabel,
                        'tipo_elector_label' => $tagElectorLabel,
                        'es_militante_lider' => intval($insRow['es_militante_lider'] ?? 0)
                    ];
                }
            }
        }
        
        $firstVoter = count($voters) > 0 ? $voters[0] : null;
        echo json_encode([
            "exito" => true,
            "total" => count($voters),
            "partido_encontrado" => $firstVoter ? boolval($firstVoter['en_padron_partido']) : false,
            "candidata_encontrado" => $firstVoter ? boolval($firstVoter['en_padron_candidata']) : false,
            "estado_diagnostico" => $firstVoter ? $firstVoter['estado_diagnostico'] : 'NO_LOCALIZADO',
            "votantes" => $voters
        ]);
        exit;
    }

    if ($action === 'export_pdf_2024') {
        checkPerm('can_view_historical');
        header('Content-Type: text/html; charset=utf-8');
        
        $region = trim($_GET['region'] ?? '');
        $regionFilter = "";
        if (!empty($region)) {
            $regEsc = $conn->real_escape_string($region);
            $regionFilter = " WHERE i.sector LIKE '%$regEsc%' OR i.municipio LIKE '%$regEsc%'";
        }
        
        $sql = "
            SELECT i.*, pm.militancia_prm, pm.posicion_recinto, pm.codigo_recinto, pm.nombre_recinto as nombre_recinto_oficial
            FROM inscritos i
            LEFT JOIN padron_maestro_consulta pm ON i.cedula = pm.cedula
            $regionFilter 
            ORDER BY i.numero_lista ASC
        ";
        $res = $conn->query($sql);
        $voters = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $voters[] = $row;
            }
        }
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Padrón Electoral Sincronizado - Pastora Altagracia</title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap');
                body {
                    font-family: 'Inter', sans-serif;
                    margin: 0;
                    padding: 20px;
                    background: #fff;
                    color: #000;
                }
                .header-banner {
                    text-align: center;
                    margin-bottom: 20px;
                }
                .header-banner img {
                    width: 100%;
                    max-height: 120px;
                    object-fit: contain;
                    border-bottom: 4px solid #E3A113;
                    border-radius: 8px;
                }
                .title {
                    font-family: 'Outfit', sans-serif;
                    color: #0054A6;
                    text-align: center;
                    margin: 10px 0 5px 0;
                    font-size: 20px;
                    text-transform: uppercase;
                    font-weight: 800;
                }
                .subtitle {
                    text-align: center;
                    font-size: 12px;
                    color: #64748b;
                    margin-bottom: 15px;
                }
                .region-badge {
                    text-align: center;
                    font-size: 13px;
                    font-weight: 700;
                    color: #0f172a;
                    margin-bottom: 15px;
                    background: #f1f5f9;
                    padding: 6px 14px;
                    border-radius: 6px;
                    display: inline-block;
                }
                .voter-table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .voter-table th, .voter-table td {
                    border: 1px solid #cbd5e1;
                    padding: 6px 8px;
                    text-align: left;
                    font-size: 11px;
                }
                .voter-table th {
                    background-color: #0f172a;
                    color: #ffffff;
                    text-transform: uppercase;
                    font-size: 10px;
                }
                .badge-pill {
                    display: inline-block;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-size: 9px;
                    font-weight: bold;
                    text-transform: uppercase;
                }
                .footer-note {
                    text-align: center;
                    font-size: 10px;
                    color: #64748b;
                    margin-top: 25px;
                    border-top: 1px solid #e2e8f0;
                    padding-top: 12px;
                }
                @media print {
                    body { padding: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body onload="window.print()">
            <div class="header-banner">
                <img src="../../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png" alt="Pastora Altagracia">
            </div>
            <div class="title">Padrón Electoral Oficial Sincronizado</div>
            <div class="subtitle">Conforme a Normas PLAD-REL-INGESTA-01 y PLAD-ENTREGABLES-SYNC-01 • Certificación Día D</div>
            <div style="text-align: center;">
                <div class="region-badge">
                    Demarcación: <?php echo empty($region) ? 'TODAS LAS REGIONES (Circ. 3 SDE)' : htmlspecialchars(strtoupper($region)); ?>
                </div>
            </div>
            
            <table class="voter-table">
                <thead>
                    <tr>
                        <th style="width: 4%;">No.</th>
                        <th style="width: 11%;">Folio</th>
                        <th style="width: 11%;">Cédula</th>
                        <th style="width: 20%;">Nombre Completo</th>
                        <th style="width: 10%;">Etiqueta</th>
                        <th style="width: 10%;">Padrón PRM</th>
                        <th style="width: 6%;">Colegio</th>
                        <th style="width: 14%;">Recinto Oficial</th>
                        <th style="width: 10%;">Coordinador</th>
                        <th style="width: 4%;">Firma</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($voters)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 20px;">No hay electores registrados en el corte del padrón.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($voters as $index => $v): ?>
                            <?php 
                            $folio = "PAD2832-" . $v['numero_lista'] . "-" . $v['cedula'];
                            $etiqueta = !empty($v['tipo_elector']) ? $v['tipo_elector'] : ($v['es_militante_lider'] ? 'Nuevo Elector (ML)' : 'Nuevo Elector');
                            $prmEstatus = (!empty($v['militancia_prm']) && $v['militancia_prm'] == 1) ? 'Militante' : 'Simpatizante';
                            $recintoTxt = !empty($v['nombre_recinto_oficial']) ? $v['nombre_recinto_oficial'] : $v['recinto_ubicacion'];
                            ?>
                            <tr>
                                <td><?php echo $v['numero_lista']; ?></td>
                                <td style="font-family: monospace; font-size: 10px;"><?php echo htmlspecialchars($folio); ?></td>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($v['cedula']); ?></td>
                                <td style="text-transform: uppercase; font-weight: 500;"><?php echo htmlspecialchars($v['nombres'] . ' ' . $v['apellidos']); ?></td>
                                <td><span class="badge-pill" style="background:#fef3c7; color:#92400e;"><?php echo htmlspecialchars($etiqueta); ?></span></td>
                                <td><span class="badge-pill" style="<?php echo ($prmEstatus === 'Militante') ? 'background:#d1fae5; color:#065f46;' : 'background:#e2e8f0; color:#475569;'; ?>"><?php echo $prmEstatus; ?></span></td>
                                <td style="text-align: center; font-weight: bold;"><?php echo htmlspecialchars($v['colegio_electoral']); ?></td>
                                <td style="text-transform: uppercase; font-size: 10px;"><?php echo htmlspecialchars($recintoTxt); ?></td>
                                <td style="text-transform: uppercase; font-size: 10px;"><?php echo htmlspecialchars($v['coordinador']); ?></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="footer-note">
                Documento de Auditoría Electoral y Certificación Oficial • Campaña Pastora Altagracia 2028 • Santo Domingo Circ. 3.
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    if ($action === 'export_excel') {
        $periodo = trim($_GET['periodo'] ?? '2028');
        if ($periodo === '2024') {
            checkPerm('can_view_historical');
        }
        
        $region = trim($_GET['region'] ?? '');
        $whereClauses = [];
        
        if (!empty($periodo)) {
            $whereClauses[] = "i.periodo = '" . $conn->real_escape_string($periodo) . "'";
        }
        
        if (!empty($region)) {
            $rEsc = $conn->real_escape_string($region);
            $whereClauses[] = "(i.sector LIKE '%$rEsc%' OR i.recinto_ubicacion LIKE '%$rEsc%' OR i.municipio LIKE '%$rEsc%')";
        }
        
        $whereSql = !empty($whereClauses) ? ("WHERE " . implode(" AND ", $whereClauses)) : "";
        
        $sql = "
            SELECT i.*, 
                   u.nombre as nombre_registrador, 
                   p.nombre as perfil_registrador,
                   pm.militancia_prm, 
                   pm.militancia_historica, 
                   pm.posicion_recinto, 
                   pm.codigo_recinto, 
                   pm.nombre_recinto as nombre_recinto_oficial
            FROM inscritos i 
            LEFT JOIN usuarios u ON i.registrado_por = u.id 
            LEFT JOIN perfiles p ON u.perfil_id = p.id
            LEFT JOIN padron_maestro_consulta pm ON i.cedula = pm.cedula
            $whereSql 
            ORDER BY i.numero_lista ASC
        ";
                
        $res = $conn->query($sql);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="padron_sincronizado_' . $periodo . (!empty($region) ? '_' . str_replace(' ', '_', $region) : '') . '.csv"');
        
        echo "\xEF\xBB\xBF";
        
        $output = fopen('php://output', 'w');
        
        // Cabecera sincronizada según Norma 8 (PLAD-ENTREGABLES-SYNC-01)
        fputcsv($output, [
            'Número Lista',
            'Folio Comprobante',
            'Cédula',
            'Nombres',
            'Apellidos',
            'Etiqueta Elector (Campaña)',
            'Estatus Partido (PRM Circ. 3)',
            'Militancia Histórica',
            'Colegio Electoral',
            'Código Recinto',
            'Nombre Recinto JCE',
            'Posición en Recinto',
            'Sector',
            'Municipio',
            'Teléfono Celular',
            'Teléfono Fijo',
            'Email',
            'Coordinador Responsable',
            'Registrado Por (Perfil)',
            'Canal de Ingesta',
            'Periodo',
            'Fecha y Hora de Ingesta'
        ]);
        
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $folio = "PAD2832-" . $row['numero_lista'] . "-" . $row['cedula'];
                $etiqueta = !empty($row['tipo_elector']) ? $row['tipo_elector'] : ($row['es_militante_lider'] ? 'Nuevo Elector (ML)' : 'Nuevo Elector');
                $prmEstatus = (!empty($row['militancia_prm']) && $row['militancia_prm'] == 1) ? 'Militante Vigente (PRM)' : 'Simpatizante Circ. 3';
                $recintoNombre = !empty($row['nombre_recinto_oficial']) ? $row['nombre_recinto_oficial'] : $row['recinto_ubicacion'];
                $registradorTxt = $row['nombre_registrador'] ? ($row['nombre_registrador'] . ' (' . ($row['perfil_registrador'] ?: 'Usuario') . ')') : 'Sistema Central';
                
                fputcsv($output, [
                    $row['numero_lista'],
                    $folio,
                    $row['cedula'],
                    $row['nombres'],
                    $row['apellidos'],
                    $etiqueta,
                    $prmEstatus,
                    $row['militancia_historica'] ?? '',
                    $row['colegio_electoral'],
                    $row['codigo_recinto'] ?? '',
                    $recintoNombre,
                    $row['posicion_recinto'] ?? '',
                    $row['sector'],
                    $row['municipio'],
                    $row['telefono'],
                    $row['telefono_fijo'] ?? '',
                    $row['email'],
                    $row['coordinador'],
                    $registradorTxt,
                    $row['canal_origen'],
                    $row['periodo'],
                    $row['fecha_registro']
                ]);
            }
        }
        
        fclose($output);
        
        $userId = intval($_SESSION['usuario_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detalles = "Exportó padrón sincronizado a Excel (Periodo: $periodo, Región: $region)";
        $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, 'EXPORT_EXCEL', ?, ?)");
        $stmtAudit->bind_param("iss", $userId, $detalles, $ip);
        $stmtAudit->execute();
        $stmtAudit->close();
        
        exit;
    }
}

if ($method === 'POST') {
    if ($action === 'register' || $isPublicRegistration) {
        if (!$isPublicRegistration) {
            checkPerm('can_create');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $cedula = trim($input['cedula'] ?? '');
        $nombres = trim($input['nombres'] ?? '');
        $apellidos = trim($input['apellidos'] ?? '');
        $nacionalidad = trim($input['nacionalidad'] ?? 'DOMINICANA');
        $colegio = trim($input['colegio_electoral'] ?? '');
        $recinto = trim($input['recinto_ubicacion'] ?? '');
        $direccion = trim($input['direccion'] ?? '');
        $sector = trim($input['sector'] ?? '');
        $municipio = trim($input['municipio'] ?? '');
        $telefono = trim($input['telefono'] ?? '');
        $telefono_fijo = trim($input['telefono_fijo'] ?? '');
        $email = trim($input['email'] ?? '');
        $coordinador = trim($input['coordinador'] ?? '');
        $centro_acopio = trim($input['centro_acopio'] ?? '');
        $canal_origen = $input['canal_origen'] ?? ($isPublicRegistration ? 'QR Campaign' : 'Manual');
        
        // Validar campos obligatorios
        if (empty($cedula) || empty($nombres) || empty($apellidos) || empty($colegio) || empty($recinto) || empty($telefono) || empty($coordinador)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Los campos cédula, nombres, apellidos, colegio, recinto, teléfono y coordinador son requeridos."]);
            exit;
        }
        
        // Validar circunscripción para evitar data sucia
        checkCircunscripcion($municipio, $sector, $recinto);
        
        // 1.2 Validación restrictiva de Colegio Electoral (COEL)
        $colegioEsc = $conn->real_escape_string($colegio);
        $checkCoel = $conn->query("SELECT * FROM colegios_estructural WHERE colegio = '$colegioEsc' LIMIT 1");
        
        $esIrregular = true;
        if ($checkCoel && $checkCoel->num_rows > 0) {
            $coelRow = $checkCoel->fetch_assoc();
            if (!empty($coelRow['region']) && !empty($coelRow['zona'])) {
                $esIrregular = false;
            }
        }
        
        $forceIrregular = filter_var($input['force_irregular'] ?? $_GET['force_irregular'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($esIrregular && !$forceIrregular) {
            $coordNameEsc = $conn->real_escape_string($coordinador);
            $coordRes = $conn->query("SELECT email FROM usuarios WHERE nombre = '$coordNameEsc' OR username = '$coordNameEsc' LIMIT 1");
            $coordEmail = ($coordRes && $coordRes->num_rows > 0) ? $coordRes->fetch_assoc()['email'] : '';
            
            http_response_code(409);
            echo json_encode([
                "exito" => false,
                "codigo_irregular" => true,
                "mensaje" => "El Colegio Electoral '$colegio' es irregular o no existe en la estructura electoral oficial JCE 2024.",
                "coordinador_nombre" => $coordinador,
                "coordinador_email" => $coordEmail
            ]);
            exit;
        }

        // 1. Validación Algorítmica Dominicana (Cédula)
        if (!ValidadorDocumentos::validarCedula($cedula)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Cédula inválida según el algoritmo Luhn Mod 10 dominicano."]);
            exit;
        }
        
        // 2. Validación Algorítmica Dominicana (Teléfono)
        if (!ValidadorDocumentos::validarTelefono($telefono)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "El número de teléfono celular es inválido. Debe tener 10 dígitos y prefijo 809, 829 o 849."]);
            exit;
        }
        
        // Normalizar Cédula (quitar guiones para búsquedas homogéneas)
        $cedulaClean = preg_replace('/\D/', '', $cedula);
        $cedulaFormateada = substr($cedulaClean, 0, 3) . '-' . substr($cedulaClean, 3, 7) . '-' . substr($cedulaClean, 10, 1);
        
        // 3. Verificación de Duplicidad estricta y regla de coordinador (Norma PLAD-REL-INGESTA-01: Universal sin distinción de perfiles)
        $cedulaEsc = $conn->real_escape_string($cedulaFormateada);
        $cleanCedEsc = $conn->real_escape_string($cedulaClean);
        $checkDup = $conn->query("SELECT cedula, coordinador, fecha_registro FROM inscritos WHERE cedula = '$cedulaEsc' OR REPLACE(cedula, '-', '') = '$cleanCedEsc' LIMIT 1");
        
        if ($checkDup && $checkDup->num_rows > 0) {
            $dupRow = $checkDup->fetch_assoc();
            $coordinadorReg = $dupRow['coordinador'];
            $fechaReg = !empty($dupRow['fecha_registro']) ? date('d/m/Y H:i', strtotime($dupRow['fecha_registro'])) : 'Fecha no especificada';
            
            http_response_code(409);
            echo json_encode([
                "exito" => false,
                "duplicado" => true,
                "mensaje" => "RESTRICCIÓN UNIVERSAL UNIQUE: La cédula $cedulaFormateada ya está registrada como elector en la plataforma (suministrada por: \"$coordinadorReg\", fecha: $fechaReg). Esta restricción de unicidad aplica de manera uniforme e inviolable para TODOS los perfiles (Administrador, Coordinador, Promotor, Digitador) sin excepción."
            ]);
            exit;
        }
        
        $conn->begin_transaction();
        
        // 4. Generación automática y segura de número_lista
        $maxRes = $conn->query("SELECT MAX(numero_lista) AS max_num FROM inscritos FOR UPDATE");
        $maxRow = $maxRes->fetch_assoc();
        $numero_lista = intval($maxRow['max_num'] ?? 0) + 1;
        
        // 5. Insertar en base de datos
        $nombresEsc = $conn->real_escape_string($nombres);
        $apellidosEsc = $conn->real_escape_string($apellidos);
        $nacionalidadEsc = $conn->real_escape_string($nacionalidad);
        $colegioEsc = $conn->real_escape_string($colegio);
        $recintoEsc = $conn->real_escape_string($recinto);
        $direccionEsc = $conn->real_escape_string($direccion);
        $sectorEsc = $conn->real_escape_string($sector);
        $municipioEsc = $conn->real_escape_string($municipio);
        $telefonoEsc = $conn->real_escape_string($telefono);
        $telefonoFijoEsc = $conn->real_escape_string($telefono_fijo);
        $emailEsc = $conn->real_escape_string($email);
        $coordinadorEsc = $conn->real_escape_string($coordinador);
        $centroEsc = $conn->real_escape_string($centro_acopio);
        $canalEsc = $conn->real_escape_string($canal_origen);
        
        $registradoPor = isset($_SESSION['usuario_id']) ? intval($_SESSION['usuario_id']) : "NULL";
        $esML = (!empty($input['es_militante_lider']) || (isset($input['tipo']) && strtolower($input['tipo']) === 'ml') || (isset($_GET['tipo']) && strtolower($_GET['tipo']) === 'ml')) ? 1 : 0;
        $nivelEstructura = trim($input['nivel_estructura'] ?? ($esML ? 'ML - Militante Líder' : 'Votante'));
        $nivelEstructuraEsc = $conn->real_escape_string($nivelEstructura);
        $coordPadreId = !empty($input['coordinador_padre_id']) ? intval($input['coordinador_padre_id']) : "NULL";
        
        // -------------------------------------------------------------
        // DETECCIÓN Y ATRIBUCIÓN DE ENLACE DE RED (ref=ML-XXXX / USER-X)
        // -------------------------------------------------------------
        $refInput = trim($input['ref'] ?? $input['referido_por'] ?? $_GET['ref'] ?? '');
        $referidoPorMLId = "NULL";
        $codigoRefOrigen = "NULL";
        
        if (!empty($refInput)) {
            $refEsc = $conn->real_escape_string($refInput);
            $whereRef = "codigo_ml = '$refEsc' OR username = '$refEsc'";
            if (is_numeric($refInput)) {
                $whereRef .= " OR id = " . intval($refInput);
            }
            $qRef = $conn->query("SELECT id, coordinador_id, nombre, role, perfil_id, codigo_ml FROM usuarios WHERE $whereRef LIMIT 1");
            if ($qRef && $qRef->num_rows > 0) {
                $refUser = $qRef->fetch_assoc();
                $refUserId = intval($refUser['id']);
                $codigoRefOrigen = "'$refEsc'";
                $isCoord = (stripos($refUser['role'] ?? '', 'Coordinador') !== false || in_array(intval($refUser['perfil_id'] ?? 0), [2, 3, 4]));
                
                if ($isCoord) {
                    $canal_origen = 'Red Coordinador';
                    $coordinadorEsc = $conn->real_escape_string($refUser['nombre']);
                    $coordPadreId = $refUserId;
                } else {
                    $referidoPorMLId = $refUserId;
                    $canal_origen = 'Red ML';
                    if (empty($coordPadreId) || $coordPadreId === "NULL") {
                        $coordPadreId = !empty($refUser['coordinador_id']) ? intval($refUser['coordinador_id']) : $referidoPorMLId;
                    }
                    if (empty($coordinador) || $coordinador === 'Campaña Digital') {
                        $coordinadorEsc = $conn->real_escape_string($refUser['nombre']);
                    }
                }
                $canalEsc = $conn->real_escape_string($canal_origen);
            }
        }
        
        $tipoElector = $esML ? 'Nuevo Elector (ML)' : 'Nuevo Elector';
        $tipoElectorEsc = $conn->real_escape_string($tipoElector);
        
        $estadoDatos = $esIrregular ? 'pendiente-reg-data' : 'validado';
        $sqlInsert = "INSERT INTO inscritos (numero_lista, cedula, nombres, apellidos, nacionalidad, colegio_electoral, recinto_ubicacion, direccion, sector, municipio, telefono, telefono_fijo, email, coordinador, centro_acopio, registrado_por, referido_por_ml_id, codigo_ref_origen, canal_origen, estado_datos, tipo_elector, es_militante_lider, nivel_estructura, coordinador_padre_id) 
                      VALUES ($numero_lista, '$cedulaEsc', '$nombresEsc', '$apellidosEsc', '$nacionalidadEsc', '$colegioEsc', '$recintoEsc', '$direccionEsc', '$sectorEsc', '$municipioEsc', '$telefonoEsc', '$telefonoFijoEsc', '$emailEsc', '$coordinadorEsc', '$centroEsc', $registradoPor, $referidoPorMLId, $codigoRefOrigen, '$canalEsc', '$estadoDatos', '$tipoElectorEsc', $esML, '$nivelEstructuraEsc', $coordPadreId)";
                      
        if ($conn->query($sqlInsert)) {
            $newVoterId = $conn->insert_id;
            
            // -------------------------------------------------------------
            // AUTO-PROVISIÓN DE CUENTA PARA MILITANTE LÍDER (PLAD)
            // -------------------------------------------------------------
            $codigoMLAsignado = null;
            $tokenML = null;
            $cleanCedulaEsc = preg_replace('/\D/', '', $cedula);
            
            if ($esML) {
                // Verificar si ya tiene cuenta existente para no duplicar
                $checkUser = $conn->query("SELECT id, codigo_ml FROM usuarios WHERE cedula = '$cedulaEsc' OR (LENGTH('$cleanCedulaEsc') >= 9 AND REPLACE(cedula, '-', '') = '$cleanCedulaEsc') LIMIT 1");
                if ($checkUser && $checkUser->num_rows > 0) {
                    $uRow = $checkUser->fetch_assoc();
                    $userMLId = intval($uRow['id']);
                    $codigoMLAsignado = $uRow['codigo_ml'];
                    if (empty($codigoMLAsignado)) {
                        $codigoMLAsignado = sprintf("ML-%04d", $userMLId);
                        $conn->query("UPDATE usuarios SET codigo_ml = '$codigoMLAsignado' WHERE id = $userMLId");
                    }
                    $conn->query("UPDATE usuarios SET inscrito_id = $newVoterId WHERE id = $userMLId");
                } else {
                    // Generar código autoincremental ML-XXXX
                    $qMax = $conn->query("SELECT MAX(id) as max_id FROM usuarios");
                    $nextUId = ($qMax ? intval($qMax->fetch_assoc()['max_id']) : 0) + 1;
                    $codigoMLAsignado = sprintf("ML-%04d", $nextUId);
                    
                    $userMlName = "ml_" . (!empty($cleanCedulaEsc) ? substr($cleanCedulaEsc, -6) : $nextUId);
                    // Cédula como credencial cifrada con BCRYPT
                    $cleanPass = !empty($cleanCedulaEsc) ? $cleanCedulaEsc : '123456';
                    $passHash = password_hash($cleanPass, PASSWORD_BCRYPT);
                    
                    $tokenML = bin2hex(random_bytes(32));
                    $expiraML = date('Y-m-d H:i:s', strtotime('+72 hours'));
                    
                    $sqlNewU = "INSERT INTO usuarios (codigo_ml, username, password, nombre, cedula, telefono, email, 
                                                      role, perfil_id, coordinador_id, inscrito_id, nivel_avance, nivel_avance_label, 
                                                      token_activacion, token_expiracion, estado, estado_activacion)
                                VALUES ('$codigoMLAsignado', '$userMlName', '$passHash', '$nombresEsc $apellidosEsc', '$cedulaEsc', '$telefonoEsc', '$emailEsc', 
                                        'ML - Militante Líder', 5, $coordPadreId, $newVoterId, 'ML', 'Militante Líder', 
                                        '$tokenML', '$expiraML', 1, 'pendiente')";
                    if ($conn->query($sqlNewU)) {
                        $userMLId = $conn->insert_id;
                        $conn->query("INSERT INTO permisos (usuario_id, can_create, can_edit, can_view, can_print, can_send, can_view_historical)
                                      VALUES ($userMLId, 1, 0, 1, 1, 0, 1)
                                      ON DUPLICATE KEY UPDATE can_view = 1, can_view_historical = 1");
                    }
                }
            }
            
            // -------------------------------------------------------------
            // ATRIBUCIÓN DE CRECIMIENTO AL MILITANTE LÍDER PROMOTOR
            // -------------------------------------------------------------
            if ($referidoPorMLId !== "NULL" && intval($referidoPorMLId) > 0) {
                $refIdInt = intval($referidoPorMLId);
                $campoExtra = $esML ? ", total_prospectos_ml = total_prospectos_ml + 1" : "";
                $conn->query("UPDATE usuarios SET total_colaboradores = total_colaboradores + 1 $campoExtra WHERE id = $refIdInt");
                
                // Recalcular nivel del promotor en tiempo real
                $qTot = $conn->query("SELECT total_colaboradores FROM usuarios WHERE id = $refIdInt");
                if ($qTot) {
                    $totP = intval($qTot->fetch_assoc()['total_colaboradores']);
                    $qNiv = $conn->query("SELECT siglas, nombre_nivel FROM escalafon_niveles_ml WHERE activo = 1 AND $totP >= min_inscritos ORDER BY min_inscritos DESC LIMIT 1");
                    if ($qNiv && $qNiv->num_rows > 0) {
                        $nRow = $qNiv->fetch_assoc();
                        $conn->query("UPDATE usuarios SET nivel_avance = '{$nRow['siglas']}', nivel_avance_label = '{$nRow['nombre_nivel']}' WHERE id = $refIdInt");
                    }
                }
            }
            
            // URLs dinámicas para el comprobante
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $enlaceRedProspeccion = $codigoMLAsignado ? "$protocol$host/pad2832/frontend/index.html?canal=red_ml&ref=" . urlencode($codigoMLAsignado) : "";
            $enlaceActivacion = $tokenML ? "$protocol$host/pad2832/activar.php?token=" . $tokenML : "";
            
            // Si es campaña QR, incrementar contador
            if ($canal_origen === 'QR Campaign' && !empty($input['campana_codigo'])) {
                $codeEsc = $conn->real_escape_string($input['campana_codigo']);
                $conn->query("UPDATE campanas_qr SET inscritos = inscritos + 1 WHERE codigo_campana = '$codeEsc'");
            }
            
            $conn->commit();
            
            // 6. Auditoría
            $creatorId = isset($_SESSION['usuario_id']) ? intval($_SESSION['usuario_id']) : 'NULL';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Votante inscrito exitosamente: $nombres $apellidos ($cedulaFormateada), No. Lista: $numero_lista, Canal: $canal_origen. Suministrado por Coordinador: $coordinador";
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles, ip_address) VALUES ($registradoPor, 'INSERT_VOTER', 'inscritos', ?, ?, ?)");
            $stmtAudit->bind_param("iss", $newVoterId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
            
            // 7. Generar y enviar comprobante (Voucher)
            // Validar si el flujo de correo automático está activo
            $flowCheck = $conn->query("SELECT valor FROM configuraciones WHERE clave = 'flow_email_voucher' LIMIT 1");
            $flowActive = true;
            if ($flowCheck && $flowCheck->num_rows > 0) {
                $flowActive = ($flowCheck->fetch_assoc()['valor'] === '1');
            }
            
            if ($flowActive) {
                // Cargar asunto de la base de datos
                $subjectRes = $conn->query("SELECT valor FROM configuraciones WHERE clave = 'flow_email_subject' LIMIT 1");
                $subjectTemplate = ($subjectRes && $subjectRes->num_rows > 0) 
                    ? $subjectRes->fetch_assoc()['valor'] 
                    : "";
                if (empty($subjectTemplate)) {
                    $subjectTemplate = "Tu Constancia de Inscripción Padronal PAD/28-32";
                }
                
                // Cargar cuerpo de la base de datos
                $bodyRes = $conn->query("SELECT valor FROM configuraciones WHERE clave = 'flow_email_body' LIMIT 1");
                $bodyTemplate = ($bodyRes && $bodyRes->num_rows > 0) 
                    ? $bodyRes->fetch_assoc()['valor'] 
                    : "";
                
                if (empty($bodyTemplate)) {
                    // Usar la plantilla HTML profesional por defecto
                    $bodyTemplate = "
<div style=\"background-color: #f1f5f9; padding: 30px 15px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;\">
    <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;\">
        <!-- Header -->
        <div style=\"background-color: #0054A6; padding: 25px; text-align: center; border-bottom: 4px solid #E3A113;\">
            <h1 style=\"color: #ffffff; margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 1px;\">ADELOG</h1>
            <p style=\"color: #cbd5e1; margin: 5px 0 0 0; font-size: 13px; text-transform: uppercase;\">Constancia Oficial de Inscripción</p>
        </div>
        
        <!-- Content -->
        <div style=\"padding: 30px 25px;\">
            <h2 style=\"color: #0f172a; margin-top: 0; margin-bottom: 10px; font-size: 20px; text-align: center;\">¡Gracias por tu Apoyo y Lealtad!</h2>
            <p style=\"font-size: 14px; color: #475569; text-align: center; margin-bottom: 25px; line-height: 1.5;\">
                Queremos expresarte nuestro más profundo agradecimiento por tu valioso apoyo y lealtad a la candidatura de la <strong>Pastora Altagracia</strong>. 
                Tu compromiso es el motor que nos impulsa a seguir trabajando incansablemente por el cambio y el desarrollo de nuestra gente.
            </p>
            
            <div style=\"background-color: #f8fafc; border: 1px solid #0054A6; border-left: 5px solid #0054A6; border-radius: 8px; padding: 20px; margin-bottom: 25px;\">
                <h4 style=\"margin-top: 0; margin-bottom: 15px; color: #0054A6; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;\">Detalles de la Inscripción</h4>
                <table style=\"width: 100%; border-collapse: collapse; font-size: 13px; color: #334155;\">
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold; width: 40%;\">Número de Lista:</td>
                        <td style=\"padding: 8px 0; text-align: right; font-weight: bold; color: #0f172a; font-size: 16px;\">#{numero_lista}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Cédula:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{cedula}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Nombre Completo:</td>
                        <td style=\"padding: 8px 0; text-align: right; font-weight: bold; color: #0054A6;\">{nombre_completo}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Colegio Electoral:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{colegio}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Recinto Electoral:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{recinto}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Región / Sector:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{region}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Coordinador:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{coordinador}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Centro de Acopio:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{centro_acopio}</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Fecha de Registro:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">{fecha}</td>
                    </tr>
                </table>
            </div>

            <div style=\"text-align: center; padding-top: 15px; border-top: 1px solid #f1f5f9;\">
                <p style=\"font-size: 13px; font-weight: bold; color: #0f172a; margin: 0 0 5px 0;\">Campaña Pastora Altagracia - PRM 2026</p>
                <p style=\"font-size: 11px; color: #94a3b8; margin: 0;\">Unidos por la transparencia, el cambio y el desarrollo.</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div style=\"background-color: #0f172a; padding: 15px; text-align: center; font-size: 11px; color: #64748b;\">
            Este documento constituye una constancia de inscripción oficial registrada en ADELOG.<br>
            Desarrollado para la administración de logisticas de comandos de campañas en RD, por sypempresariales . Copyright © 2026 Sypempresariales.
        </div>
    </div>
</div>";
                }
                
                // Reemplazar placeholders en la plantilla
                $codigoComprobante = "PAD2832-" . $numero_lista . "-" . $cedulaFormateada;
                $placeholders = [
                    '{numero_lista}' => $numero_lista,
                    '{cedula}' => $cedulaFormateada,
                    '{codigo_comprobante}' => $codigoComprobante,
                    '{nombre_completo}' => "$nombres $apellidos",
                    '{colegio}' => $colegio,
                    '{recinto}' => $recinto,
                    '{region}' => "$sector, $municipio",
                    '{coordinador}' => $coordinador,
                    '{centro_acopio}' => $centro_acopio,
                    '{fecha}' => date('d/m/Y h:i A')
                ];
                
                $emailBody = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTemplate);
                $emailSubject = "[$codigoComprobante] Tu Constancia Oficial de Inscripción - PAD/28-32";
                if (!empty($subjectTemplate)) {
                    $customSubj = str_replace(array_keys($placeholders), array_values($placeholders), $subjectTemplate);
                    if (stripos($customSubj, 'PAD2832') === false) {
                        $emailSubject = "[$codigoComprobante] " . $customSubj;
                    } else {
                        $emailSubject = $customSubj;
                    }
                }
                
                if ($esIrregular) {
                    $emailBodyIrregular = "
<div style=\"background-color: #fef2f2; padding: 30px 15px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #7f1d1d; line-height: 1.6;\">
    <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #fee2e2;\">
        <div style=\"background-color: #ef4444; padding: 25px; text-align: center; border-bottom: 4px solid #b91c1c;\">
            <h1 style=\"color: #ffffff; margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 1px;\">ADELOG</h1>
            <p style=\"color: #fca5a5; margin: 5px 0 0 0; font-size: 13px; text-transform: uppercase;\">Alerta de Regularización de Datos</p>
        </div>
        <div style=\"padding: 30px 25px; color: #1f2937;\">
            <h2 style=\"color: #991b1b; margin-top: 0; margin-bottom: 10px; font-size: 20px; text-align: center;\">Incidencia de Datos Detectada</h2>
            <p style=\"font-size: 14px; color: #4b5563; text-align: center; margin-bottom: 25px;\">
                Se ha detectado una irregularidad en el Colegio Electoral del elector registrado, el cual no coincide con la estructura del padrón oficial.
            </p>
            <div style=\"background-color: #f9fafb; border: 1px solid #e5e7eb; border-left: 5px solid #ef4444; border-radius: 8px; padding: 20px; margin-bottom: 25px;\">
                <h4 style=\"margin-top: 0; margin-bottom: 15px; color: #991b1b; font-size: 15px; text-transform: uppercase; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px;\">Detalles del Elector</h4>
                <table style=\"width: 100%; border-collapse: collapse; font-size: 13px; color: #374151;\">
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold; width: 40%;\">Folio / ID:</td>
                        <td style=\"padding: 8px 0; text-align: right; font-weight: bold; font-family: monospace;\">$codigoComprobante</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold; width: 40%;\">Cédula:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">$cedulaFormateada</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Nombre Elector:</td>
                        <td style=\"padding: 8px 0; text-align: right; font-weight: bold;\">$nombres $apellidos</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Colegio Electoral:</td>
                        <td style=\"padding: 8px 0; text-align: right; color: #ef4444; font-weight: bold;\">$colegio (Irregular)</td>
                    </tr>
                    <tr>
                        <td style=\"padding: 8px 0; font-weight: bold;\">Coordinador:</td>
                        <td style=\"padding: 8px 0; text-align: right;\">$coordinador</td>
                    </tr>
                </table>
            </div>
            <p style=\"font-size: 13px; color: #4b5563; line-height: 1.5; margin-bottom: 25px;\">
                <strong>Instrucciones:</strong> El coordinador debe ponerse en contacto con el votante para verificar su colegio de votación físico y actualizar su estatus a fin de retener el voto y asegurar el sufragio.
            </p>
            <div style=\"text-align: center; padding-top: 15px; border-top: 1px solid #f3f4f6;\">
                <p style=\"font-size: 13px; font-weight: bold; color: #111827; margin: 0 0 5px 0;\">Campaña $candidato_nombre</p>
                <p style=\"font-size: 11px; color: #9ca3af; margin: 0;\">Fidelización y retención electoral - Normativa PLAD</p>
            </div>
        </div>
    </div>
</div>";
                    $subjectIrregular = "[$codigoComprobante] PENDIENTE REGULARIZACIÓN: Elector $nombres $apellidos";

                    if (!empty($email)) {
                        Mailer::enviar($email, $subjectIrregular, $emailBodyIrregular, true, $newVoterId);
                    }

                    $coordNameEsc = $conn->real_escape_string($coordinador);
                    $coordRes = $conn->query("SELECT email FROM usuarios WHERE nombre = '$coordNameEsc' OR username = '$coordNameEsc' LIMIT 1");
                    if ($coordRes && $coordRes->num_rows > 0) {
                        $coordEmail = $coordRes->fetch_assoc()['email'];
                        if (!empty($coordEmail)) {
                            Mailer::enviar($coordEmail, $subjectIrregular, $emailBodyIrregular, true, $newVoterId);
                        }
                    }

                    Mailer::enviar(MAIL_FROM, "[$codigoComprobante] Alerta Regularización No. Lista: $numero_lista ($cedulaFormateada)", $emailBodyIrregular, true, $newVoterId);
                } else {
                    if (!empty($email)) {
                        Mailer::enviar($email, $emailSubject, $emailBody, true, $newVoterId);
                    }
                    
                    Mailer::enviar(MAIL_FROM, "[$codigoComprobante] Auditoría Registro No. Lista: $numero_lista ($cedulaFormateada)", $emailBody, true, $newVoterId);
                }
            }
            
            echo json_encode([
                "exito" => true,
                "mensaje" => "Inscripción completada exitosamente.",
                "datos" => [
                    "id" => $newVoterId,
                    "numero_lista" => $numero_lista,
                    "cedula" => $cedulaFormateada,
                    "codigo_comprobante" => $codigoComprobante,
                    "nombres" => $nombres,
                    "apellidos" => $apellidos,
                    "colegio_electoral" => $colegio,
                    "recinto_ubicacion" => $recinto,
                    "sector" => $sector,
                    "municipio" => $municipio,
                    "telefono" => $telefono,
                    "coordinador" => $coordinador,
                    "es_militante_lider" => $esML,
                    "nivel_estructura" => $nivelEstructura,
                    "codigo_ml" => $codigoMLAsignado,
                    "enlace_red_prospeccion" => $enlaceRedProspeccion,
                    "enlace_activacion" => $enlaceActivacion
                ]
            ]);
            exit;
        }
        
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error interno al registrar en la base de datos."]);
        exit;
    }
    
    if ($action === 'edit') {
        checkPerm('can_edit');
        
        $input = json_decode(file_get_contents('php://input'), true);
        $voterId = intval($input['id'] ?? 0);
        
        if ($voterId <= 0) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "ID de votante inválido."]);
            exit;
        }
        
        $nombres = trim($input['nombres'] ?? '');
        $apellidos = trim($input['apellidos'] ?? '');
        $colegio = trim($input['colegio_electoral'] ?? '');
        $recinto = trim($input['recinto_ubicacion'] ?? '');
        $direccion = trim($input['direccion'] ?? '');
        $sector = trim($input['sector'] ?? '');
        $municipio = trim($input['municipio'] ?? '');
        $telefono = trim($input['telefono'] ?? '');
        $telefono_fijo = trim($input['telefono_fijo'] ?? '');
        $email = trim($input['email'] ?? '');
        $coordinador = trim($input['coordinador'] ?? '');
        $centro_acopio = trim($input['centro_acopio'] ?? '');
        
        if (empty($nombres) || empty($apellidos) || empty($colegio) || empty($recinto) || empty($telefono) || empty($coordinador)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Campos obligatorios vacíos."]);
            exit;
        }
        
        // Validar circunscripción para evitar data sucia
        checkCircunscripcion($municipio, $sector, $recinto);
        
        if (!ValidadorDocumentos::validarTelefono($telefono)) {
            http_response_code(400);
            echo json_encode(["exito" => false, "mensaje" => "Número de celular inválido."]);
            exit;
        }
        
        $nombresEsc = $conn->real_escape_string($nombres);
        $apellidosEsc = $conn->real_escape_string($apellidos);
        $colegioEsc = $conn->real_escape_string($colegio);
        $recintoEsc = $conn->real_escape_string($recinto);
        $direccionEsc = $conn->real_escape_string($direccion);
        $sectorEsc = $conn->real_escape_string($sector);
        $municipioEsc = $conn->real_escape_string($municipio);
        $telefonoEsc = $conn->real_escape_string($telefono);
        $telefonoFijoEsc = $conn->real_escape_string($telefono_fijo);
        $emailEsc = $conn->real_escape_string($email);
        $coordinadorEsc = $conn->real_escape_string($coordinador);
        $centroEsc = $conn->real_escape_string($centro_acopio);
        
        // Obtener datos antiguos para comparar en logs
        $oldRes = $conn->query("SELECT * FROM inscritos WHERE id = $voterId LIMIT 1");
        $oldVoter = $oldRes->fetch_assoc();
        
        $esML = isset($input['es_militante_lider']) ? intval($input['es_militante_lider']) : intval($oldVoter['es_militante_lider'] ?? 0);
        $nivelEstructura = trim($input['nivel_estructura'] ?? ($esML ? 'ML - Militante Líder' : ($oldVoter['nivel_estructura'] ?? 'Votante')));
        $nivelEstructuraEsc = $conn->real_escape_string($nivelEstructura);
        $coordPadreId = !empty($input['coordinador_padre_id']) ? intval($input['coordinador_padre_id']) : 'NULL';
        $coordPadreVal = ($coordPadreId === 'NULL') ? "NULL" : intval($coordPadreId);
        
        $sqlUpdate = "UPDATE inscritos SET 
                      nombres = '$nombresEsc', 
                      apellidos = '$apellidosEsc', 
                      colegio_electoral = '$colegioEsc', 
                      recinto_ubicacion = '$recintoEsc', 
                      direccion = '$direccionEsc', 
                      sector = '$sectorEsc', 
                      municipio = '$municipioEsc', 
                      telefono = '$telefonoEsc', 
                      telefono_fijo = '$telefonoFijoEsc', 
                      email = '$emailEsc', 
                      coordinador = '$coordinadorEsc', 
                      centro_acopio = '$centroEsc',
                      es_militante_lider = $esML,
                      nivel_estructura = '$nivelEstructuraEsc',
                      coordinador_padre_id = $coordPadreVal 
                      WHERE id = $voterId";
                      
        if ($conn->query($sqlUpdate)) {
            // Auditoría
            $userId = intval($_SESSION['usuario_id']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $detalles = "Votante ID: $voterId corregido por Usuario ID: $userId. Cambios: ";
            if ($oldVoter['nombres'] !== $nombres) $detalles .= "Nombres [{$oldVoter['nombres']} -> $nombres] ";
            if ($oldVoter['telefono'] !== $telefono) $detalles .= "Teléfono [{$oldVoter['telefono']} -> $telefono] ";
            
            $stmtAudit = $conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles, ip_address) VALUES (?, 'EDIT_VOTER', 'inscritos', ?, ?, ?)");
            $stmtAudit->bind_param("iiss", $userId, $voterId, $detalles, $ip);
            $stmtAudit->execute();
            $stmtAudit->close();
            
            echo json_encode(["exito" => true, "mensaje" => "Registro corregido correctamente."]);
            exit;
        }
        
        http_response_code(500);
        echo json_encode(["exito" => false, "mensaje" => "Error al actualizar el registro."]);
        exit;
    }
}

http_response_code(405);
echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
?>
