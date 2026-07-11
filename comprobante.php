<?php
/**
 * Comprobante Público de Inscripción
 * PAD/28-32 - Plataforma Electoral
 */

require_once __DIR__ . '/backend/db.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Error: ID de elector inválido.");
}

$db = Database::getInstance();
$conn = $db->getConnection();

$idEsc = intval($id);
$res = $conn->query("SELECT * FROM inscritos WHERE id = $idEsc LIMIT 1");
if (!$res || $res->num_rows === 0) {
    die("Error: Elector no encontrado.");
}

$v = $res->fetch_assoc();

// Cargar configuración de branding por defecto (Pastora Altagracia)
$candidato_nombre = "Pastora Altagracia De Los Santos";
$candidato_cargo = "Diputada Santo Domingo Circ. 3";
$plataforma_nombre = "Plataforma Oficial Digital Pastora Altagracia";
$banner_url = "GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png";

// Si existe la tabla de configuración (módulo de ADELOG), cargarla dinámicamente
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante de Inscripción - <?php echo htmlspecialchars($v['nombres'] . ' ' . $v['apellidos']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            overflow: hidden;
            padding: 20px;
        }
        .btn-print {
            background-color: #0054A6;
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: background 0.2s;
            font-size: 15px;
        }
        .btn-print:hover {
            background-color: #003d7a;
        }
        @media print {
            body {
                background-color: #ffffff;
                padding: 0;
            }
            .container {
                box-shadow: none;
                max-width: 100%;
                padding: 0;
                border: none;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Banner Header -->
        <div style="text-align: center; margin-bottom: 25px;">
            <img src="<?php echo htmlspecialchars($banner_url); ?>" alt="<?php echo htmlspecialchars($candidato_nombre); ?>" style="width: 100%; height: auto; display: block; border-bottom: 4px solid #E3A113; border-radius: 8px;">
        </div>
        
        <!-- Metadata Boxes -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 30px;">
            <div style="border: 2px solid #e2e8f0; padding: 12px; border-radius: 10px; background: #f8fafc;">
                <span style="font-weight: 700; color: #0054A6; font-size: 13px; display: block;">Coordinador:</span>
                <span style="color: #334155; font-size: 14px; font-weight: 500; text-transform: uppercase;"><?php echo htmlspecialchars($v['coordinador']); ?></span>
            </div>
            <div style="border: 2px solid #e2e8f0; padding: 12px; border-radius: 10px; background: #f8fafc;">
                <span style="font-weight: 700; color: #0054A6; font-size: 13px; display: block;">Región:</span>
                <span style="color: #334155; font-size: 14px; font-weight: 500; text-transform: uppercase;"><?php echo htmlspecialchars($v['region'] ?? 'N/A'); ?></span>
            </div>
            <div style="border: 2px solid #e2e8f0; padding: 12px; border-radius: 10px; background: #f8fafc;">
                <span style="font-weight: 700; color: #0054A6; font-size: 13px; display: block;">Teléfono:</span>
                <span style="color: #334155; font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($v['telefono']); ?></span>
            </div>
            <div style="border: 2px solid #e2e8f0; padding: 12px; border-radius: 10px; background: #f8fafc;">
                <span style="font-weight: 700; color: #0054A6; font-size: 13px; display: block;">Zona:</span>
                <span style="color: #334155; font-size: 14px; font-weight: 500; text-transform: uppercase;"><?php echo htmlspecialchars($v['zona'] ?? 'N/A'); ?></span>
            </div>
            <div style="border: 2px solid #e2e8f0; padding: 12px; border-radius: 10px; background: #f8fafc;">
                <span style="font-weight: 700; color: #0054A6; font-size: 13px; display: block;">Sector:</span>
                <span style="color: #334155; font-size: 14px; font-weight: 500; text-transform: uppercase;"><?php echo htmlspecialchars($v['sector']); ?></span>
            </div>
            <div style="border: 2px solid #e2e8f0; padding: 12px; border-radius: 10px; background: #f8fafc;">
                <span style="font-weight: 700; color: #0054A6; font-size: 13px; display: block;">Municipio:</span>
                <span style="color: #334155; font-size: 14px; font-weight: 500; text-transform: uppercase;"><?php echo htmlspecialchars($v['municipio']); ?></span>
            </div>
        </div>
        
        <!-- Voucher Card -->
        <div style="background-color: #0b1320; border: 2px solid #E3A113; border-radius: 16px; padding: 30px; text-align: center; color: #ffffff; margin-bottom: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
            <div style="display: inline-flex; align-items: center; gap: 6px; background-color: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; padding: 6px 16px; border-radius: 9999px; font-size: 12px; font-weight: 700; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 0.5px;">
                ✓ Registro Completado Vía Bot
            </div>
            
            <h2 style="font-size: 24px; font-weight: 700; color: #ffffff; margin: 0 0 8px 0; font-family: sans-serif; letter-spacing: -0.5px;">¡Gracias por su apoyo!</h2>
            <div style="display: inline-block; background-color: rgba(227, 161, 19, 0.2); color: #E3A113; border: 1px solid #E3A113; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 700; margin-bottom: 12px; text-transform: uppercase;">
                Periodo Electoral: <?php echo htmlspecialchars($v['periodo'] ?? '2028'); ?>
            </div>
            <p style="color: #94a3b8; font-size: 14px; margin: 0 0 25px 0;">Guarde su número de lista oficial</p>
            
            <div style="background-color: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; text-align: left; max-width: 440px; margin: 0 auto; display: flex; flex-direction: column; gap: 12px; font-family: monospace;">
                <div style="font-size: 15px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 8px; font-family: sans-serif;">
                    <strong style="color: #94a3b8;">Número de Lista:</strong> 
                    <span style="font-size: 22px; color: #E3A113; font-weight: bold; margin-left: 8px;"><?php echo htmlspecialchars($v['numero_lista']); ?></span>
                </div>
                <div style="font-size: 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 8px; font-family: sans-serif;">
                    <strong style="color: #94a3b8;">Cédula:</strong> 
                    <span style="color: #ffffff; font-weight: 600; margin-left: 8px;"><?php echo htmlspecialchars($v['cedula']); ?></span>
                </div>
                <div style="font-size: 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 8px; font-family: sans-serif;">
                    <strong style="color: #94a3b8;">Nombre:</strong> 
                    <span style="color: #ffffff; font-weight: 600; margin-left: 8px; text-transform: uppercase;"><?php echo htmlspecialchars($v['nombres'] . ' ' . $v['apellidos']); ?></span>
                </div>
                <div style="font-size: 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 8px; font-family: sans-serif;">
                    <strong style="color: #94a3b8;">Colegio Electoral:</strong> 
                    <span style="color: #ffffff; font-weight: 600; margin-left: 8px;"><?php echo htmlspecialchars($v['colegio_electoral']); ?></span>
                </div>
                <div style="font-size: 14px; font-family: sans-serif;">
                    <strong style="color: #94a3b8;">Recinto Electoral:</strong> 
                    <span style="color: #ffffff; font-weight: 600; margin-left: 8px; text-transform: uppercase;"><?php echo htmlspecialchars($v['recinto_ubicacion']); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Print Button -->
        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button class="btn-print" onclick="window.print()"><i class="fa fa-print"></i> Imprimir / Guardar PDF</button>
        </div>
        
        <!-- Footer -->
        <div style="text-align: center; margin-top: 35px; border-top: 1px solid #e2e8f0; padding-top: 15px; font-size: 12px; color: #64748b;">
            <p>© 2026 Campaña <?php echo htmlspecialchars($candidato_nombre); ?> - <?php echo htmlspecialchars($candidato_cargo); ?>. Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>
