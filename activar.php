<?php
/**
 * Módulo Público de Activación y Autoservicio de Cuenta para Militantes Líderes (ML)
 * PAD/28-32 - Plataforma Electoral - Campaña Pastora Altagracia
 * Conforme a Normas PLAD (PLAD-CERT-QR-01 y PLAD-VAF-SYNC-01)
 */

require_once __DIR__ . '/backend/db.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$mensajeError = '';
$mensajeExito = '';
$usuario = null;

// Cargar banner como Base64 para garantizar visualización perfecta
$bannerDataUri = '';
$bannerRutas = [
    __DIR__ . '/GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832-02.png',
    __DIR__ . '/GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png',
    __DIR__ . '/GRAFICOS PARA LA PAGINA WEB/BANNER-ADLS.png'
];
foreach ($bannerRutas as $r) {
    if (file_exists($r) && is_readable($r)) {
        $imgRaw = file_get_contents($r);
        if ($imgRaw !== false) {
            $bannerDataUri = 'data:image/png;base64,' . base64_encode($imgRaw);
            break;
        }
    }
}
$bannerSrc = $bannerDataUri ? $bannerDataUri : "GRAFICOS%20PARA%20LA%20PAGINA%20WEB/BANNER%20PLATAFORMA%20WEB%20PAD-2832-02.png";

// 1. Validar token
if (empty($token)) {
    $mensajeError = "No se ha provisto un token de activación válido.";
} else {
    $tokenEsc = $conn->real_escape_string($token);
    $sql = "SELECT u.*, coord.nombre as coordinador_nombre 
            FROM usuarios u
            LEFT JOIN usuarios coord ON u.coordinador_id = coord.id
            WHERE u.token_activacion = '$tokenEsc' LIMIT 1";
    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) {
        $mensajeError = "El enlace de activación no es válido o ya fue utilizado previamente.";
    } else {
        $usuario = $res->fetch_assoc();
        
        // Verificar expiración
        if (!empty($usuario['token_expiracion']) && strtotime($usuario['token_expiracion']) < time()) {
            $mensajeError = "Este enlace de activación ha vencido (plazo de 72 horas expirado). Por favor contacte a su Coordinador para generar uno nuevo.";
            $conn->query("UPDATE usuarios SET estado_activacion = 'vencido' WHERE id = " . intval($usuario['id']));
        }
    }
}

// 2. Procesar POST de activación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$mensajeError && $usuario) {
    $uId = intval($usuario['id']);
    $usernameNuevo = trim($_POST['username'] ?? '');
    $passwordNuevo = trim($_POST['password'] ?? '');
    $passwordConf = trim($_POST['password_confirm'] ?? '');
    $telefonoNuevo = trim($_POST['telefono'] ?? '');
    
    if (empty($usernameNuevo)) {
        $mensajeError = "El nombre de usuario es obligatorio.";
    } elseif (strlen($passwordNuevo) < 6) {
        $mensajeError = "La contraseña debe contener al menos 6 caracteres.";
    } elseif ($passwordNuevo !== $passwordConf) {
        $mensajeError = "Las contraseñas no coinciden.";
    } else {
        // Validar unicidad del nombre de usuario
        $uEsc = $conn->real_escape_string($usernameNuevo);
        $checkU = $conn->query("SELECT id FROM usuarios WHERE username = '$uEsc' AND id != $uId LIMIT 1");
        if ($checkU && $checkU->num_rows > 0) {
            $mensajeError = "El nombre de usuario '$usernameNuevo' ya está en uso. Por favor elija otro.";
        } else {
            $passHash = password_hash($passwordNuevo, PASSWORD_BCRYPT);
            $telEsc = $conn->real_escape_string($telefonoNuevo);
            
            $sqlUp = "UPDATE usuarios SET 
                      username = '$uEsc',
                      password = '$passHash',
                      telefono = '$telEsc',
                      estado = 1,
                      estado_activacion = 'activo',
                      activado_at = NOW(),
                      token_activacion = NULL,
                      token_expiracion = NULL
                      WHERE id = $uId";
            
            if ($conn->query($sqlUp)) {
                $mensajeExito = "¡Cuenta activada exitosamente! Ya puede acceder a la plataforma con sus credenciales.";
                // Registrar auditoría
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $detalles = "Activación de cuenta completada por el Militante Líder: {$usuario['nombre']} (Usuario: $usernameNuevo, Código: {$usuario['codigo_ml']})";
                $conn->query("INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles, ip_address)
                              VALUES ($uId, 'ACTIVATE_ACCOUNT', 'usuarios', $uId, '$detalles', '$ip')");
                
                // Actualizar datos locales para el renderizado
                $usuario['username'] = $usernameNuevo;
                $usuario['estado_activacion'] = 'activo';
            } else {
                $mensajeError = "Error al actualizar la cuenta: " . $conn->error;
            }
        }
    }
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$enlaceRed = ($usuario && !empty($usuario['codigo_ml'])) 
    ? "$protocol$host/pad2832/frontend/index.html?canal=red_ml&ref=" . urlencode($usuario['codigo_ml']) 
    : "";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activación de Cuenta ML - Campaña Pastora Altagracia</title>
    <link rel="shortcut icon" type="image/png" href="GRAFICOS%20PARA%20LA%20PAGINA%20WEB/adelog_logo_icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="frontend/assets/lib/qrcode.min.js"></script>
    <style>
        :root {
            --primary: #0054A6;
            --secondary: #E3A113;
            --success: #10B981;
            --danger: #EF4444;
            --dark: #0f172a;
            --light: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 15px;
        }
        .main-card {
            background: #ffffff;
            max-width: 580px;
            width: 100%;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .banner-box {
            width: 100%;
            background: #ffffff;
            border-bottom: 4px solid var(--secondary);
        }
        .banner-box img {
            width: 100%;
            height: auto;
            display: block;
        }
        .content {
            padding: 28px 24px;
        }
        .badge-ml {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .info-grid {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 22px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 13.5px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px dashed #e2e8f0;
            padding-bottom: 6px;
        }
        .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .info-label { color: #64748b; font-weight: 600; }
        .info-val { color: #0f172a; font-weight: 700; text-align: right; }
        .form-group {
            margin-bottom: 16px;
        }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 84, 166, 0.15);
        }
        .btn-submit {
            width: 100%;
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 12px 20px;
            font-size: 15px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit:hover { background: #004080; }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .qr-card {
            border: 2px dashed var(--primary);
            border-radius: 12px;
            padding: 16px;
            background: #f8fafc;
            text-align: center;
            margin-top: 20px;
        }
        .qr-box {
            background: #ffffff;
            display: inline-block;
            padding: 8px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            margin: 10px 0;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            margin: 4px;
        }
        .btn-wa { background: #25D366; color: #ffffff; border: none; }
        .btn-login { background: var(--primary); color: #ffffff; border: none; }
    </style>
</head>
<body>

    <div class="main-card">
        <div class="banner-box">
            <img src="<?php echo $bannerSrc; ?>" alt="Pastora Altagracia">
        </div>

        <div class="content">
            <?php if (!empty($mensajeError) && !$usuario): ?>
                <div class="alert alert-danger">
                    <i class="fa fa-exclamation-triangle" style="font-size: 20px;"></i>
                    <div><?php echo htmlspecialchars($mensajeError); ?></div>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="frontend/login.html" class="btn-action btn-login"><i class="fa fa-sign-in-alt"></i> Ir a Iniciar Sesión</a>
                </div>
            <?php elseif (!empty($mensajeExito)): ?>
                <div class="alert alert-success">
                    <i class="fa fa-check-circle" style="font-size: 24px;"></i>
                    <div>
                        <strong>¡Enhorabuena!</strong><br>
                        <?php echo htmlspecialchars($mensajeExito); ?>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-row">
                        <span class="info-label">Código ID Oficial:</span>
                        <span class="info-val" style="color: var(--primary); font-size: 16px;"><?php echo htmlspecialchars($usuario['codigo_ml']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Usuario:</span>
                        <span class="info-val"><?php echo htmlspecialchars($usuario['username']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Rol Asignado:</span>
                        <span class="info-val">Militante Líder (ML)</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Coordinador:</span>
                        <span class="info-val"><?php echo htmlspecialchars($usuario['coordinador_nombre'] ?: 'Coordinación Central'); ?></span>
                    </div>
                </div>

                <!-- Tarjeta con Código QR de Promotor para compartir -->
                <?php if (!empty($enlaceRed)): ?>
                <div class="qr-card">
                    <span style="font-size: 12px; color: var(--primary); font-weight: 800; text-transform: uppercase;">
                        <i class="fa fa-qrcode"></i> Su Código QR Personal de Promotor
                    </span>
                    <p style="font-size: 12.5px; color: #475569; margin: 4px 0 10px;">
                        Muestre este código a sus prospectos o comparta su enlace para comenzar a sumar colaboradores a su red:
                    </p>
                    <div class="qr-box">
                        <div id="qr-promotor"></div>
                    </div>
                    <div>
                        <a href="https://api.whatsapp.com/send?text=<?php echo urlencode("¡Hola! Te invito a formar parte de nuestro equipo de apoyo a la Pastora Altagracia. Inscríbete con mi enlace oficial de Militante Líder: $enlaceRed"); ?>" target="_blank" class="btn-action btn-wa">
                            <i class="fab fa-whatsapp"></i> Compartir por WhatsApp
                        </a>
                        <a href="frontend/login.html" class="btn-action btn-login">
                            <i class="fa fa-sign-in-alt"></i> Iniciar Sesión en el Panel
                        </a>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        new QRCode(document.getElementById('qr-promotor'), {
                            text: <?php echo json_encode($enlaceRed); ?>,
                            width: 140,
                            height: 140,
                            colorDark: "#0f172a",
                            colorLight: "#ffffff"
                        });
                    });
                </script>
                <?php endif; ?>

            <?php else: ?>
                <!-- Formulario de Activación de Autoservicio -->
                <div style="text-align: center; margin-bottom: 20px;">
                    <div class="badge-ml">
                        <i class="fa fa-id-badge"></i> Código: <?php echo htmlspecialchars($usuario['codigo_ml'] ?? 'ML-NUEVO'); ?>
                    </div>
                    <h2 style="font-size: 20px; color: var(--primary); font-weight: 800;">Bienvenido(a) al Equipo Territorial</h2>
                    <p style="color: #64748b; font-size: 13.5px; margin-top: 4px;">Complete su registro oficial para activar su cuenta de Militante Líder.</p>
                </div>

                <?php if (!empty($mensajeError)): ?>
                    <div class="alert alert-danger">
                        <i class="fa fa-exclamation-circle"></i>
                        <div><?php echo htmlspecialchars($mensajeError); ?></div>
                    </div>
                <?php endif; ?>

                <div class="info-grid">
                    <div class="info-row">
                        <span class="info-label">Nombre del Líder:</span>
                        <span class="info-val"><?php echo htmlspecialchars($usuario['nombre']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Cédula:</span>
                        <span class="info-val"><?php echo htmlspecialchars($usuario['cedula'] ?: 'Confirmada'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Coordinador Responsable:</span>
                        <span class="info-val"><?php echo htmlspecialchars($usuario['coordinador_nombre'] ?: 'Coordinación Central'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Circunscripción:</span>
                        <span class="info-val">Circ. 3 (Santo Domingo Este)</span>
                    </div>
                </div>

                <form method="POST" action="activar.php?token=<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="username"><i class="fa fa-user"></i> Nombre de Usuario Deseado</label>
                        <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($usuario['username']); ?>" required autocomplete="username">
                        <small style="color: #64748b; font-size: 11px;">Puede usar este usuario o su Código ID (<?php echo htmlspecialchars($usuario['codigo_ml']); ?>) para acceder.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="telefono"><i class="fa fa-phone"></i> Teléfono / WhatsApp Confirmado</label>
                        <input type="text" id="telefono" name="telefono" class="form-control" value="<?php echo htmlspecialchars($usuario['telefono'] ?? ''); ?>" placeholder="809-000-0000">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password"><i class="fa fa-lock"></i> Crear Contraseña Personal</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password_confirm"><i class="fa fa-check-double"></i> Confirmar Contraseña</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="form-control" placeholder="Repita la contraseña" required autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fa fa-shield-alt"></i> Activar Mi Cuenta Oficial
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
