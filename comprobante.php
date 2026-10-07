<?php
/**
 * Comprobante Público Oficial de Inscripción con Validación QR
 * PAD/28-32 - Plataforma Electoral - Campaña Pastora Altagracia
 * Conforme a Normas PLAD (PLAD-CERT-QR-01 y PLAD-ENTREGABLES-SYNC-01)
 */

require_once __DIR__ . '/backend/db.php';

$id = intval($_GET['id'] ?? 0);
$cedulaParam = trim($_GET['cedula'] ?? '');

$db = Database::getInstance();
$conn = $db->getConnection();

$whereClause = "";
if ($id > 0) {
    $whereClause = "i.id = " . intval($id);
} elseif (!empty($cedulaParam)) {
    $cedEsc = $conn->real_escape_string($cedulaParam);
    $cleanEsc = preg_replace('/\D/', '', $cedulaParam);
    $whereClause = "i.cedula = '$cedEsc' OR REPLACE(i.cedula, '-', '') = '$cleanEsc'";
} else {
    die("Error: Parámetro de elector (id o cédula) inválido.");
}

// Consultar inscritos con perfil registrador
$sql = "
    SELECT i.*, 
           u.nombre as nombre_registrador, 
           p.nombre as perfil_registrador 
    FROM inscritos i
    LEFT JOIN usuarios u ON i.registrado_por = u.id
    LEFT JOIN perfiles p ON u.perfil_id = p.id
    WHERE $whereClause
    LIMIT 1
";
$res = $conn->query($sql);
if (!$res || $res->num_rows === 0) {
    die("Error: Elector no encontrado en el padrón activo de la candidata.");
}

$v = $res->fetch_assoc();

// Consultar Padrón Maestro Oficial JCE / PRM para enriquecimiento
$cedEsc = $conn->real_escape_string($v['cedula']);
$cleanEsc = preg_replace('/\D/', '', $v['cedula']);

$sqlPm = "
    SELECT pm.*, cr.direccion_recinto as dir_recinto_oficial 
    FROM padron_maestro_consulta pm
    LEFT JOIN catalogo_recintos_jce cr ON pm.codigo_recinto = cr.codigo_recinto
    WHERE pm.cedula = '$cedEsc' OR REPLACE(pm.cedula, '-', '') = '$cleanEsc'
    LIMIT 1
";
$resPm = $conn->query($sqlPm);
$pm = ($resPm && $resPm->num_rows > 0) ? $resPm->fetch_assoc() : null;

// Cargar configuración de branding
$candidato_nombre = "Pastora Altagracia De Los Santos";
$candidato_cargo = "Diputada Santo Domingo Circ. 3";
$plataforma_nombre = "Plataforma Oficial Digital Pastora Altagracia";
$banner_url = "GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832-02.png";

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
        if (!empty($configs['plataforma_nombre'])) $plataforma_nombre = $configs['plataforma_nombre'];
        if (!empty($configs['candidato_logo_url'])) $banner_url = $configs['candidato_logo_url'];
    }
}

// Resolver archivo del banner y convertir a Base64 Data-URI para garantizar que NUNCA falle ni dependa de rutas relativas
$bannerDataUri = '';
$posiblesRutasBanner = [
    __DIR__ . '/GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832-02.png',
    __DIR__ . '/GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png',
    __DIR__ . '/GRAFICOS PARA LA PAGINA WEB/BANNER-ADLS.png'
];

if (!empty($banner_url)) {
    $rutaLimpia = preg_replace('/^\.\.[\/\\\\]/', '', $banner_url);
    array_unshift($posiblesRutasBanner, __DIR__ . '/' . ltrim($rutaLimpia, '/\\'));
}

foreach ($posiblesRutasBanner as $rutaPrueba) {
    if (file_exists($rutaPrueba) && is_readable($rutaPrueba)) {
        $mime = 'image/png';
        if (preg_match('/\.(jpe?g)$/i', $rutaPrueba)) {
            $mime = 'image/jpeg';
        }
        $imgRaw = file_get_contents($rutaPrueba);
        if ($imgRaw !== false && strlen($imgRaw) > 0) {
            $bannerDataUri = 'data:' . $mime . ';base64,' . base64_encode($imgRaw);
            break;
        }
    }
}

$bannerWebSrc = !empty($bannerDataUri) ? $bannerDataUri : "GRAFICOS%20PARA%20LA%20PAGINA%20WEB/BANNER%20PLATAFORMA%20WEB%20PAD-2832-02.png";

$codigoComprobante = "PAD2832-" . $v['numero_lista'] . "-" . $v['cedula'];

// Construir URL pública absoluta para validación del QR
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$folder = "PLATAFORMA DIGITAL-PAD-28-32";
if (str_contains($uri, 'PLATAFORMA%20DIGITAL-PAD-28-32')) {
    $folder = "PLATAFORMA%20DIGITAL-PAD-28-32";
} elseif (str_contains($uri, 'PLATAFORMA_INTEGRADA')) {
    $folder = "PLATAFORMA_INTEGRADA";
}

$urlValidar = $protocol . $host . "/" . $folder . "/validar.php?cedula=" . urlencode($v['cedula']) . "&folio=" . urlencode($codigoComprobante);

// Detección de circunscripción y elector externo
$circunscripcion = 'Circunscripción 3 (Santo Domingo Este)';
$esFueraCirc3 = false;
$sectorUpper = strtoupper($v['sector'] ?? '');
$recintoUpper = strtoupper($v['recinto_ubicacion'] ?? '');
$colegioStr = strval($v['colegio_electoral'] ?? '');
$regionUpper = strtoupper($v['region'] ?? '');

if (str_contains($sectorUpper, 'ISABELITA') || str_contains($recintoUpper, 'ISABELITA') || $colegioStr === '1823' || str_contains($regionUpper, 'CIRCUNSCRIPCIÓN 1') || str_contains($regionUpper, 'CIRCUNSCRIPCION 1')) {
    $circunscripcion = 'Circunscripción 1 (Santo Domingo Este)';
    $esFueraCirc3 = true;
}

// Etiquetas
$etiquetaCampaña = !empty($v['tipo_elector']) ? $v['tipo_elector'] : ($esFueraCirc3 ? 'Nuevo Elector (Simpatizante Externo)' : ($v['es_militante_lider'] ? 'Nuevo Elector (ML)' : 'Nuevo Elector'));
$etiquetaPartido = $esFueraCirc3 ? 'Elector Circunscripción 1 (Simpatizante Externo)' : 'Simpatizante Circ. 3';
if ($pm) {
    if (!empty($pm['militancia_prm']) && $pm['militancia_prm'] == 1) {
        $etiquetaPartido = 'Militante Vigente (PRM)';
    } elseif (!empty($pm['militancia_historica'])) {
        $etiquetaPartido = 'Militante Histórico [' . trim($pm['militancia_historica']) . ']';
    }
}
$recintoNombre = !empty($pm['nombre_recinto']) ? $pm['nombre_recinto'] : $v['recinto_ubicacion'];

// Consultar si este elector tiene una cuenta de usuario como Militante Líder (ML)
$uMl = null;
$esMlConCuenta = false;
$urlRedML = '';
$urlActivarML = '';
$codigoMlOficial = '';

$sqlMl = "
    SELECT u.*, p.nombre as perfil_nombre, c.nombre as nombre_coordinador
    FROM usuarios u
    LEFT JOIN perfiles p ON u.perfil_id = p.id
    LEFT JOIN usuarios c ON u.coordinador_id = c.id
    WHERE (u.inscrito_id = " . intval($v['id']) . " 
           OR u.cedula = '$cedEsc' 
           OR REPLACE(u.cedula, '-', '') = '$cleanEsc')
      AND (u.perfil_id = 4 OR u.codigo_ml IS NOT NULL OR u.codigo_ml != '')
    LIMIT 1
";
$resMl = $conn->query($sqlMl);
if ($resMl && $resMl->num_rows > 0) {
    $uMl = $resMl->fetch_assoc();
    $esMlConCuenta = true;
    $codigoMlOficial = $uMl['codigo_ml'] ?: ('ML-' . str_pad($uMl['id'], 4, '0', STR_PAD_LEFT));
    $urlRedML = $protocol . $host . "/" . $folder . "/registro.html?canal=red_ml&ref=" . urlencode($codigoMlOficial);
    if (!empty($uMl['token_activacion'])) {
        $urlActivarML = $protocol . $host . "/" . $folder . "/activar.php?token=" . urlencode($uMl['token_activacion']);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante Oficial - <?php echo htmlspecialchars($codigoComprobante); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="frontend/assets/lib/qrcode.min.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 650px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            padding: 24px;
        }
        .btn-print {
            background-color: #0054A6;
            color: #ffffff;
            border: none;
            padding: 12px 26px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: background 0.2s;
            font-size: 15px;
        }
        .btn-print:hover { background-color: #003870; }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-warning { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-primary { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        @media print {
            body { background-color: #ffffff; padding: 0; }
            .container { box-shadow: none; max-width: 100%; padding: 0; border: none; }
            .no-print { display: none !important; }
            @page { size: portrait; margin: 6mm 8mm; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Banner Header spanning full width -->
        <div style="width: 100%; margin-bottom: 20px; text-align: center;">
            <img src="<?php echo $bannerWebSrc; ?>" alt="<?php echo htmlspecialchars($candidato_nombre); ?>" style="width: 100%; max-width: 100%; height: auto; display: block; border-bottom: 4px solid #E3A113; border-radius: 8px;">
        </div>
        
        <!-- Sello Superior de Certificación -->
        <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 16px; margin-bottom: 20px; flex-wrap: wrap; gap: 8px;">
            <div>
                <span style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; display: block;">Certificación Electoral Oficial:</span>
                <span style="font-size: 13px; font-weight: 800; color: #0054A6;"><i class="fa fa-shield-alt"></i> PLAD-CERT-QR-01 • Estatus Sincronizado</span>
            </div>
            <div>
                <?php if ($esFueraCirc3): ?>
                    <span class="badge badge-warning"><i class="fa fa-map-marker-alt"></i> Simpatizante Externo (Circ. 1)</span>
                <?php else: ?>
                    <span class="badge badge-success"><i class="fa fa-check-circle"></i> Elector Activo Circ. 3</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tarjeta Principal del Voucher (Ahorro de Tinta para Impresión) -->
        <div style="background-color: #ffffff; border: 2px solid #0054A6; border-radius: 16px; padding: 25px 20px; text-align: center; color: #000000; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.06);">
            
            <div style="display: inline-flex; align-items: center; gap: 6px; background-color: #ecfdf5; color: #059669; border: 1px solid #10b981; padding: 5px 14px; border-radius: 9999px; font-size: 12px; font-weight: 700; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                ✓ Inscripción Confirmada
            </div>
            
            <h2 style="font-size: 22px; font-weight: 800; color: #0054A6; margin: 0 0 6px 0; letter-spacing: -0.5px;">¡Gracias por su apoyo!</h2>
            <div style="display: inline-block; background-color: #fef3c7; color: #b45309; border: 1px solid #f59e0b; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; margin-bottom: 15px; text-transform: uppercase;">
                Período Electoral: <?php echo htmlspecialchars($v['periodo'] ?? '2028'); ?>
            </div>
            <?php if ($esFueraCirc3): ?>
            <div style="display: block; margin-bottom: 15px;">
                <span style="background: #fffbeb; color: #b45309; border: 1px solid #f59e0b; padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: bold;">
                    ⚠️ Simpatizante Externo (Votante Oficial en <?php echo htmlspecialchars($circunscripcion); ?>)
                </span>
            </div>
            <?php endif; ?>
            
            <!-- Bloque Central de Datos -->
            <div style="background-color: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 18px; text-align: left; max-width: 520px; margin: 0 auto; display: flex; flex-direction: column; gap: 10px;">
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Folio Oficial:</strong> 
                    <span style="color: #0054A6; font-weight: bold; font-family: monospace;"><?php echo htmlspecialchars($codigoComprobante); ?></span>
                </div>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Número de Lista:</strong> 
                    <span style="font-size: 18px; color: #059669; font-weight: bold;">#<?php echo htmlspecialchars($v['numero_lista']); ?></span>
                </div>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Cédula de Identidad:</strong> 
                    <span style="color: #000000; font-weight: 700;"><?php echo htmlspecialchars($v['cedula']); ?></span>
                </div>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Nombre Completo:</strong> 
                    <span style="color: #000000; font-weight: 700; text-transform: uppercase;"><?php echo htmlspecialchars($v['nombres'] . ' ' . $v['apellidos']); ?></span>
                </div>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Demarcación Electoral:</strong> 
                    <span style="color: <?php echo $esFueraCirc3 ? '#b45309' : '#0054A6'; ?>; font-weight: 700;"><?php echo htmlspecialchars($circunscripcion); ?></span>
                </div>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Etiqueta Elector (Campaña):</strong> 
                    <span style="color: #b45309; font-weight: 700;"><?php echo htmlspecialchars($etiquetaCampaña); ?></span>
                </div>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Estatus Padrón Partido:</strong> 
                    <span style="color: #0054A6; font-weight: 700;"><?php echo htmlspecialchars($etiquetaPartido); ?></span>
                </div>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Colegio / Recinto JCE:</strong> 
                    <span style="color: #000000; font-weight: 600; text-align: right; max-width: 60%;">Colegio <?php echo htmlspecialchars($v['colegio_electoral']); ?> • <?php echo htmlspecialchars($recintoNombre); ?></span>
                </div>
                <?php if (!empty($v['direccion'])): ?>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Dirección Registrada:</strong> 
                    <span style="color: #000000; font-weight: 600; text-align: right; max-width: 60%;"><?php echo htmlspecialchars($v['direccion']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($pm['posicion_recinto'])): ?>
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Posición en Recinto:</strong> 
                    <span style="color: #b45309; font-weight: bold; font-family: monospace;"><?php echo htmlspecialchars($pm['posicion_recinto']); ?></span>
                </div>
                <?php endif; ?>
                <div style="font-size: 13px; display: flex; justify-content: space-between;">
                    <strong style="color: #475569;">Coordinador Asignado:</strong> 
                    <span style="color: #0054A6; font-weight: 700; text-transform: uppercase;"><?php echo htmlspecialchars($v['coordinador']); ?></span>
                </div>
            </div>
        </div>

        <!-- Sección de Código QR y Validación Oficial -->
        <div style="border: 2px dashed #0054A6; border-radius: 12px; padding: 20px; background: #f8fafc; margin-bottom: 25px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; justify-content: center;">
            <div id="comprobante-qr-box" style="background: #ffffff; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <div id="qrcode"></div>
            </div>
            <div style="flex: 1; min-width: 240px; text-align: left;">
                <span style="font-size: 11px; color: #0054A6; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">
                    <i class="fa fa-qrcode"></i> Validación Digital en Tiempo Real
                </span>
                <p style="font-size: 13px; color: #334155; line-height: 1.4; margin-bottom: 8px;">
                    Escanee este código QR con la cámara de su teléfono móvil para verificar en vivo la sincronización del elector en los padrones oficiales.
                </p>
                <div style="font-size: 11px; color: #64748b;">
                    <div><strong>Registrado Por:</strong> <?php echo htmlspecialchars($v['nombre_registrador'] ? ($v['nombre_registrador'] . ' (' . ($v['perfil_registrador'] ?: 'Usuario') . ')') : 'Sistema Central'); ?></div>
                    <div><strong>Canal de Ingesta:</strong> <?php echo htmlspecialchars($v['canal_origen'] ?? 'Manual'); ?></div>
                    <div><strong>Fecha de Ingesta:</strong> <?php echo date('d/m/Y H:i:s', strtotime($v['fecha_registro'])); ?></div>
                </div>
            </div>
        </div>

        <?php if ($esMlConCuenta || !empty($v['es_militante_lider'])): ?>
        <!-- SECCIÓN EXCLUSIVA PARA MILITANTE LÍDER (ML): CREDENCIALES Y CANAL DE PROSPECCIÓN -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); border: 2px solid #E3A113; border-radius: 16px; padding: 24px; color: #ffffff; margin-bottom: 25px; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(227, 161, 19, 0.4); padding-bottom: 12px; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="background: #E3A113; color: #0f172a; font-weight: 900; font-size: 18px; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <i class="fa fa-star"></i>
                    </span>
                    <div>
                        <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #ffffff; letter-spacing: -0.3px;">CREDENCIALES Y ACCESO DE MILITANTE LÍDER</h3>
                        <span style="font-size: 11px; color: #cbd5e1; text-transform: uppercase; font-weight: 600;">Estatus: <?php echo htmlspecialchars($uMl['nivel_avance_label'] ?? 'NIVEL MILITANTE LÍDER (ML)'); ?></span>
                    </div>
                </div>
                <div style="background: rgba(227, 161, 19, 0.2); border: 1px solid #E3A113; color: #fef08a; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 800; font-family: monospace;">
                    ID: <?php echo htmlspecialchars($codigoMlOficial ?: 'ML-' . str_pad($v['id'], 4, '0', STR_PAD_LEFT)); ?>
                </div>
            </div>

            <!-- Tabla de credenciales para login -->
            <div style="background: rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; border: 1px solid rgba(255, 255, 255, 0.1);">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; font-size: 13px;">
                    <div>
                        <span style="color: #94a3b8; display: block; font-size: 11px; text-transform: uppercase;">Usuario / ID de Acceso:</span>
                        <strong style="color: #38bdf8; font-size: 15px; font-family: monospace;"><?php echo htmlspecialchars($codigoMlOficial ?: ($uMl['username'] ?? 'ML-' . str_pad($v['id'], 4, '0', STR_PAD_LEFT))); ?></strong>
                    </div>
                    <div>
                        <span style="color: #94a3b8; display: block; font-size: 11px; text-transform: uppercase;">Contraseña de Ingreso:</span>
                        <strong style="color: #4ade80; font-size: 15px; font-family: monospace;"><?php echo htmlspecialchars($v['cedula']); ?></strong>
                        <span style="display: block; font-size: 10px; color: #cbd5e1;">(Cédula completa o sin guiones)</span>
                    </div>
                    <div>
                        <span style="color: #94a3b8; display: block; font-size: 11px; text-transform: uppercase;">Coordinador Asignado:</span>
                        <strong style="color: #ffffff;"><?php echo htmlspecialchars($uMl['nombre_coordinador'] ?? $v['coordinador'] ?? 'Coordinador General'); ?></strong>
                    </div>
                    <div>
                        <span style="color: #94a3b8; display: block; font-size: 11px; text-transform: uppercase;">Colaboradores Vinculados:</span>
                        <strong style="color: #fde047; font-size: 15px;"><?php echo intval($uMl['total_colaboradores'] ?? 0); ?> Registrados</strong>
                    </div>
                </div>
            </div>

            <!-- Caja de Prospección y QR Personal -->
            <div style="background: #ffffff; color: #0f172a; border-radius: 14px; padding: 18px; display: flex; align-items: center; gap: 18px; flex-wrap: wrap;">
                <div id="ml-referral-qr-box" style="background: #f8fafc; padding: 8px; border-radius: 10px; border: 1.5px solid #e2e8f0; text-align: center;">
                    <div id="qrcode_ml"></div>
                    <span style="display: block; font-size: 10px; font-weight: 800; color: #0369a1; margin-top: 4px;">QR PROMETEDOR</span>
                </div>
                <div style="flex: 1; min-width: 240px;">
                    <h4 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 800; color: #0054A6;">
                        <i class="fa fa-users"></i> Su Enlace Personal de Captación
                    </h4>
                    <p style="font-size: 12px; color: #475569; margin: 0 0 10px 0; line-height: 1.4;">
                        Comparta este código QR o su enlace directo con sus familiares, simpatizantes y colaboradores. Todas las personas que se inscriban con este enlace sumarán directamente a su meta en el Escalafón.
                    </p>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <input type="text" id="input_enlace_ml" value="<?php echo htmlspecialchars($urlRedML); ?>" readonly style="flex: 1; min-width: 180px; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; font-family: monospace; background: #f1f5f9; color: #0f172a;">
                        <button type="button" onclick="copiarEnlaceML()" style="background: #0054A6; color: #ffffff; border: none; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="fa fa-copy"></i> Copiar
                        </button>
                        <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('¡Hola! Te invito a formar parte de nuestro equipo de apoyo a la candidata Pastora Altagracia De Los Santos. Inscríbete en nuestro padrón oficial aquí: ' . $urlRedML); ?>" target="_blank" style="background: #25D366; color: #ffffff; text-decoration: none; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="fab fa-whatsapp"></i> WhatsApp
                        </a>
                    </div>
                    <?php if (!empty($urlActivarML)): ?>
                    <div style="margin-top: 10px; font-size: 11px; color: #64748b;">
                        <span>Enlace de activación de cuenta: </span>
                        <a href="<?php echo htmlspecialchars($urlActivarML); ?>" target="_blank" style="color: #0284c7; font-weight: 700; text-decoration: underline;">
                            Completar / Personalizar Cuenta en Línea
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Print Button -->
        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button class="btn-print" onclick="window.print()"><i class="fa fa-print"></i> Imprimir / Guardar PDF</button>
            <a href="frontend/dashboard.html" class="btn-print" style="background: #475569; margin-left: 10px;"><i class="fa fa-arrow-left"></i> Volver al Panel</a>
        </div>
        
        <!-- Footer -->
        <div style="text-align: center; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 11px; color: #64748b;">
            <p>© 2026 Campaña <?php echo htmlspecialchars($candidato_nombre); ?> • <?php echo htmlspecialchars($candidato_cargo); ?> • Normas PLAD</p>
        </div>
    </div>

    <!-- Script para renderizar el Código QR de forma segura -->
    <script>
        function copiarEnlaceML() {
            var input = document.getElementById('input_enlace_ml');
            if (input) {
                input.select();
                input.setSelectionRange(0, 99999);
                navigator.clipboard.writeText(input.value).then(function() {
                    alert('✓ Enlace personal copiado al portapapeles. ¡Listo para compartir!');
                }).catch(function() {
                    document.execCommand('copy');
                    alert('✓ Enlace personal copiado al portapapeles.');
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var urlValidacion = <?php echo json_encode($urlValidar); ?>;
            var qrContainer = document.getElementById('qrcode');
            if (typeof QRCode !== 'undefined' && qrContainer) {
                new QRCode(qrContainer, {
                    text: urlValidacion,
                    width: 120,
                    height: 120,
                    colorDark: "#0f172a",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            } else if (qrContainer) {
                var img = document.createElement('img');
                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + encodeURIComponent(urlValidacion);
                img.alt = 'QR Validación';
                img.style.width = '120px';
                img.style.height = '120px';
                qrContainer.appendChild(img);
            }

            // QR de Militante Líder
            var urlRedML = <?php echo json_encode($urlRedML); ?>;
            var qrContainerML = document.getElementById('qrcode_ml');
            if (qrContainerML && urlRedML) {
                if (typeof QRCode !== 'undefined') {
                    new QRCode(qrContainerML, {
                        text: urlRedML,
                        width: 120,
                        height: 120,
                        colorDark: "#0054A6",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } else {
                    var imgML = document.createElement('img');
                    imgML.src = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + encodeURIComponent(urlRedML);
                    imgML.alt = 'QR Red ML';
                    imgML.style.width = '120px';
                    imgML.style.height = '120px';
                    qrContainerML.appendChild(imgML);
                }
            }

            <?php if (!empty($_GET['print']) || !empty($_GET['auto_print'])): ?>
            setTimeout(function() {
                window.print();
            }, 600);
            <?php endif; ?>
        });
    </script>
</body>
</html>
