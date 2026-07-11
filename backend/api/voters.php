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

// Permitir registros públicos si vienen de la campaña masiva QR o solicitud de comprobantes
$isPublicRegistration = ($method === 'POST' && $action === 'public_register') || ($method === 'GET' && $action === 'email_voucher');

if (!$isPublicRegistration) {
    // Si no es público, validar autenticación general
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión."]);
        exit;
    }
}

if ($method === 'GET') {
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
        
        $candidato_nombre = "Pastora Altagracia De Los Santos";
        $candidato_cargo = "Diputada Santo Domingo Circ. 3";
        
        $tableCheck = $conn->query("SHOW TABLES LIKE 'configuraciones'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $resConfig = $conn->query("SELECT * FROM configuraciones");
            if ($resConfig) {
                $configs = [];
                while ($row = $resConfig->fetch_assoc()) {
                    $configs[$row['clave']] = $row['valor'];
                }
                if (!empty($configs['candidato_nombre'])) $candidato_nombre = $configs['candidato_nombre'];
                if (!empty($configs['candidato_cargo'])) $candidato_cargo = $configs['candidato_cargo'];
            }
        }
        
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $folder = "PLATAFORMA DIGITAL-PAD-28-32";
        if (str_contains($uri, 'PLATAFORMA%20DIGITAL-PAD-28-32')) {
            $folder = "PLATAFORMA%20DIGITAL-PAD-28-32";
        } else if (str_contains($uri, 'PLATAFORMA_INTEGRADA')) {
            $folder = "PLATAFORMA_INTEGRADA";
        }
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $linkComprobante = $protocol . $host . "/" . $folder . "/comprobante.php?id=" . $idEsc;
        
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

    checkPerm('can_view');
    
    if ($action === 'list') {
        // Padrón en tiempo real: búsqueda y filtros
        $search = trim($_GET['search'] ?? '');
        $region = trim($_GET['region'] ?? '');
        $coordinador = trim($_GET['coordinador'] ?? '');
        $centro_acopio = trim($_GET['centro_acopio'] ?? '');
        $periodo = trim($_GET['periodo'] ?? '2028');
        
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
        
        $sql = "SELECT id, numero_lista, cedula, nombres, apellidos, nacionalidad, colegio_electoral, recinto_ubicacion, direccion, sector, municipio, telefono, email, coordinador, centro_acopio, canal_origen, fecha_registro 
                FROM inscritos 
                $whereSql 
                ORDER BY numero_lista DESC 
                LIMIT $limit OFFSET $offset";
                
        $res = $conn->query($sql);
        $voters = [];
        
        if ($res) {
            while ($row = $res->fetch_assoc()) {
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
            "votantes" => $voters
        ]);
        exit;
    }
    
    if ($action === 'detail') {
        $id = intval($_GET['id'] ?? 0);
        $res = $conn->query("SELECT * FROM inscritos WHERE id = $id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            echo json_encode(["exito" => true, "votante" => $res->fetch_assoc()]);
        } else {
            http_response_code(404);
            echo json_encode(["exito" => false, "mensaje" => "Votante no encontrado."]);
        }
        exit;
    }

    if ($action === 'query_2024') {
        checkPerm('can_view_historical');
        $search = trim($_GET['search'] ?? $_POST['search'] ?? '');
        if (empty($search)) {
            echo json_encode(["exito" => true, "votantes" => []]);
            exit;
        }
        
        $searchEsc = $conn->real_escape_string($search);
        // Buscar por cedula, nombres o apellidos en el periodo 2024
        $sql = "SELECT * FROM inscritos 
                WHERE periodo = '2024' AND (
                    cedula LIKE '%$searchEsc%' OR 
                    nombres LIKE '%$searchEsc%' OR 
                    apellidos LIKE '%$searchEsc%'
                ) LIMIT 15";
        $res = $conn->query($sql);
        $voters = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $voters[] = $row;
            }
        }
        echo json_encode(["exito" => true, "votantes" => $voters]);
        exit;
    }

    if ($action === 'export_pdf_2024') {
        checkPerm('can_view_historical');
        header('Content-Type: text/html; charset=utf-8');
        
        // Obtener todos los inscritos del 2024
        $region = trim($_GET['region'] ?? '');
        $regionFilter = "";
        if (!empty($region)) {
            $regEsc = $conn->real_escape_string($region);
            $regionFilter = " AND sector = '$regEsc'";
        }
        
        $sql = "SELECT * FROM inscritos WHERE periodo = '2024' $regionFilter ORDER BY numero_lista ASC";
        $res = $conn->query($sql);
        $voters = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $voters[] = $row;
            }
        }
        
        // Renderizar página HTML imprimible adaptada a la estética oficial
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Padrón Histórico 2024 - Pastora Altagracia</title>
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
                    height: auto;
                    border-bottom: 4px solid #E3A113;
                    border-radius: 8px;
                }
                .title {
                    font-family: 'Outfit', sans-serif;
                    color: #0054A6;
                    text-align: center;
                    margin: 10px 0 25px 0;
                    font-size: 22px;
                    text-transform: uppercase;
                    font-weight: 800;
                }
                .region-badge {
                    text-align: center;
                    font-size: 14px;
                    font-weight: 700;
                    color: #475569;
                    margin-bottom: 15px;
                }
                .voter-table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .voter-table th, .voter-table td {
                    border: 1px solid #94a3b8;
                    padding: 8px 10px;
                    text-align: left;
                    font-size: 12px;
                }
                .voter-table th {
                    background-color: #0f172a;
                    color: #ffffff;
                    text-transform: uppercase;
                    font-size: 11px;
                }
                .footer-note {
                    text-align: center;
                    font-size: 11px;
                    color: #64748b;
                    margin-top: 30px;
                    border-top: 1px solid #e2e8f0;
                    padding-top: 15px;
                }
                @media print {
                    body {
                        padding: 0;
                    }
                }
            </style>
        </head>
        <body onload="window.print()">
            <div class="header-banner">
                <img src="../../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png" alt="Pastora Altagracia">
            </div>
            <div class="title">Padrón Electoral Histórico - Contienda 2024</div>
            <div class="region-badge">
                Región/Sector: <?php echo empty($region) ? 'TODAS LAS REGIONES' : htmlspecialchars(strtoupper($region)); ?>
            </div>
            
            <table class="voter-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">No.</th>
                        <th style="width: 15%;">Cédula</th>
                        <th style="width: 30%;">Nombre Completo</th>
                        <th style="width: 10%;">Colegio</th>
                        <th style="width: 15%;">Región / Sector</th>
                        <th style="width: 15%;">Recinto</th>
                        <th style="width: 10%;">Firma</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($voters)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">No hay electores inscritos para este filtro en el Padrón Histórico 2024.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($voters as $index => $v): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($v['cedula']); ?></td>
                                <td><?php echo htmlspecialchars($v['nombres'] . ' ' . $v['apellidos']); ?></td>
                                <td><?php echo htmlspecialchars($v['colegio_electoral']); ?></td>
                                <td><?php echo htmlspecialchars($v['sector']); ?></td>
                                <td><?php echo htmlspecialchars($v['recinto_ubicacion']); ?></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="footer-note">
                Documento de Auditoría Electoral Interna - Campaña Pastora Altagracia 2024/2028.
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
        
        $whereClauses = ["periodo = '" . $conn->real_escape_string($periodo) . "'"];
        if (!empty($region)) {
            $rEsc = $conn->real_escape_string($region);
            $whereClauses[] = "(sector LIKE '%$rEsc%' OR recinto_ubicacion LIKE '%$rEsc%' OR municipio LIKE '%$rEsc%')";
        }
        
        $whereSql = "WHERE " . implode(" AND ", $whereClauses);
        
        $sql = "SELECT numero_lista, cedula, nombres, apellidos, nacionalidad, colegio_electoral, recinto_ubicacion, direccion, sector, municipio, telefono, email, coordinador, centro_acopio, canal_origen, fecha_registro, periodo 
                FROM inscritos 
                $whereSql 
                ORDER BY numero_lista DESC";
                
        $res = $conn->query($sql);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="padron_' . $periodo . (!empty($region) ? '_' . str_replace(' ', '_', $region) : '') . '.csv"');
        
        echo "\xEF\xBB\xBF";
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, [
            'Número Lista',
            'Cédula',
            'Nombres',
            'Apellidos',
            'Nacionalidad',
            'Colegio Electoral',
            'Recinto',
            'Dirección',
            'Sector',
            'Municipio',
            'Teléfono',
            'Email',
            'Coordinador',
            'Centro de Acopio',
            'Canal de Origen',
            'Fecha de Registro',
            'Periodo'
        ]);
        
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                fputcsv($output, [
                    $row['numero_lista'],
                    $row['cedula'],
                    $row['nombres'],
                    $row['apellidos'],
                    $row['nacionalidad'],
                    $row['colegio_electoral'],
                    $row['recinto_ubicacion'],
                    $row['direccion'],
                    $row['sector'],
                    $row['municipio'],
                    $row['telefono'],
                    $row['email'],
                    $row['coordinador'],
                    $row['centro_acopio'],
                    $row['canal_origen'],
                    $row['fecha_registro'],
                    $row['periodo']
                ]);
            }
        }
        
        fclose($output);
        
        $userId = intval($_SESSION['usuario_id']);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detalles = "Exportó padrón a Excel (Periodo: $periodo, Región: $region)";
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
        
        // 3. Verificación de Duplicidad estricta y regla de coordinador
        $cedulaEsc = $conn->real_escape_string($cedulaFormateada);
        $checkDup = $conn->query("SELECT cedula, coordinador FROM inscritos WHERE cedula = '$cedulaEsc' LIMIT 1");
        
        if ($checkDup && $checkDup->num_rows > 0) {
            $dupRow = $checkDup->fetch_assoc();
            $coordinadorReg = $dupRow['coordinador'];
            
            http_response_code(409);
            echo json_encode([
                "exito" => false,
                "duplicado" => true,
                "mensaje" => "La cédula $cedulaFormateada ya está registrada en la plataforma y fue suministrada por el coordinador \"$coordinadorReg\"."
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
        $emailEsc = $conn->real_escape_string($email);
        $coordinadorEsc = $conn->real_escape_string($coordinador);
        $centroEsc = $conn->real_escape_string($centro_acopio);
        $canalEsc = $conn->real_escape_string($canal_origen);
        
        $registradoPor = isset($_SESSION['usuario_id']) ? intval($_SESSION['usuario_id']) : "NULL";
        
        $estadoDatos = $esIrregular ? 'pendiente-reg-data' : 'validado';
        $sqlInsert = "INSERT INTO inscritos (numero_lista, cedula, nombres, apellidos, nacionalidad, colegio_electoral, recinto_ubicacion, direccion, sector, municipio, telefono, email, coordinador, centro_acopio, registrado_por, canal_origen, estado_datos) 
                      VALUES ($numero_lista, '$cedulaEsc', '$nombresEsc', '$apellidosEsc', '$nacionalidadEsc', '$colegioEsc', '$recintoEsc', '$direccionEsc', '$sectorEsc', '$municipioEsc', '$telefonoEsc', '$emailEsc', '$coordinadorEsc', '$centroEsc', $registradoPor, '$canalEsc', '$estadoDatos')";
                      
        if ($conn->query($sqlInsert)) {
            $newVoterId = $conn->insert_id;
            
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
                $placeholders = [
                    '{numero_lista}' => $numero_lista,
                    '{cedula}' => $cedulaFormateada,
                    '{nombre_completo}' => "$nombres $apellidos",
                    '{colegio}' => $colegio,
                    '{recinto}' => $recinto,
                    '{region}' => "$sector, $municipio",
                    '{coordinador}' => $coordinador,
                    '{centro_acopio}' => $centro_acopio,
                    '{fecha}' => date('d/m/Y h:i A')
                ];
                
                $emailBody = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTemplate);
                $emailSubject = str_replace(array_keys($placeholders), array_values($placeholders), $subjectTemplate);
                
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
                    $subjectIrregular = "PENDIENTE REGULARIZACIÓN: Elector $nombres $apellidos";

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

                    Mailer::enviar(MAIL_FROM, "Alerta Regularización No. Lista: $numero_lista ($cedulaFormateada)", $emailBodyIrregular, true, $newVoterId);
                } else {
                    if (!empty($email)) {
                        Mailer::enviar($email, $emailSubject, $emailBody, true, $newVoterId);
                    }
                    
                    Mailer::enviar(MAIL_FROM, "Auditoría Registro No. Lista: $numero_lista ($cedulaFormateada)", $emailBody, true, $newVoterId);
                }
            }
            
            echo json_encode([
                "exito" => true,
                "mensaje" => "Inscripción completada exitosamente.",
                "datos" => [
                    "id" => $newVoterId,
                    "numero_lista" => $numero_lista,
                    "cedula" => $cedulaFormateada,
                    "nombres" => $nombres,
                    "apellidos" => $apellidos
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
        $emailEsc = $conn->real_escape_string($email);
        $coordinadorEsc = $conn->real_escape_string($coordinador);
        $centroEsc = $conn->real_escape_string($centro_acopio);
        
        // Obtener datos antiguos para comparar en logs
        $oldRes = $conn->query("SELECT * FROM inscritos WHERE id = $voterId LIMIT 1");
        $oldVoter = $oldRes->fetch_assoc();
        
        $sqlUpdate = "UPDATE inscritos SET 
                      nombres = '$nombresEsc', 
                      apellidos = '$apellidosEsc', 
                      colegio_electoral = '$colegioEsc', 
                      recinto_ubicacion = '$recintoEsc', 
                      direccion = '$direccionEsc', 
                      sector = '$sectorEsc', 
                      municipio = '$municipioEsc', 
                      telefono = '$telefonoEsc', 
                      email = '$emailEsc', 
                      coordinador = '$coordinadorEsc', 
                      centro_acopio = '$centroEsc' 
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
