<?php
/**
 * Instalador y Reconstructor de Base de Datos
 * PAD/28-32 - Plataforma Electoral
 */

require_once __DIR__ . '/config.php';

echo "=== INICIANDO INSTALACIÓN DE BASE DE DATOS electoral_pad_2832 ===\n";

// 1. Conectar a MySQL sin especificar base de datos
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, '', DB_PORT);
if ($conn->connect_error) {
    die("ERROR: No se pudo conectar a MySQL. Verifique WampServer.\nDetalle: " . $conn->connect_error . "\n");
}

// 2. Crear la Base de Datos
$dbName = DB_NAME;
$sqlCreateDB = "CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn->query($sqlCreateDB)) {
    echo "✓ Base de datos `$dbName` creada o ya existente.\n";
} else {
    die("ERROR al crear la base de datos: " . $conn->error . "\n");
}

// Seleccionar la base de datos
$conn->select_db($dbName);

// 3. Crear Tabla: usuarios
$sqlTableUsers = "CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    role ENUM('Administrador', 'Digitador', 'Coordinador', 'Jefe Electoral') DEFAULT 'Digitador',
    estado TINYINT DEFAULT 1,
    telefono VARCHAR(20) DEFAULT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sqlTableUsers)) {
    echo "✓ Tabla `usuarios` creada.\n";
} else {
    die("ERROR al crear tabla `usuarios`: " . $conn->error . "\n");
}

// 4. Crear Tabla: permisos
$sqlTablePerms = "CREATE TABLE IF NOT EXISTS permisos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    can_create TINYINT DEFAULT 0,
    can_edit TINYINT DEFAULT 0,
    can_view TINYINT DEFAULT 0,
    can_print TINYINT DEFAULT 0,
    can_send TINYINT DEFAULT 0,
    can_view_historical TINYINT DEFAULT 0,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY uq_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sqlTablePerms)) {
    echo "✓ Tabla `permisos` creada.\n";
} else {
    die("ERROR al crear tabla `permisos`: " . $conn->error . "\n");
}

// 5. Crear Tabla: resultados_historicos
$sqlTableHistory = "CREATE TABLE IF NOT EXISTS resultados_historicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region VARCHAR(100) NOT NULL UNIQUE,
    votos_prm INT DEFAULT 0,
    votos_diputada INT DEFAULT 0,
    porciento DECIMAL(6,4) DEFAULT 0.0000,
    INDEX idx_region (region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sqlTableHistory)) {
    echo "✓ Tabla `resultados_historicos` creada.\n";
} else {
    die("ERROR al crear tabla `resultados_historicos`: " . $conn->error . "\n");
}

// 6. Crear Tabla: campanas_qr
$sqlTableQR = "CREATE TABLE IF NOT EXISTS campanas_qr (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_campana VARCHAR(50) NOT NULL UNIQUE,
    nombre_campana VARCHAR(150) NOT NULL,
    coordinador VARCHAR(100) NOT NULL,
    clics INT DEFAULT 0,
    inscritos INT DEFAULT 0,
    activo TINYINT DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_codigo (codigo_campana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sqlTableQR)) {
    echo "✓ Tabla `campanas_qr` creada.\n";
} else {
    die("ERROR al crear tabla `campanas_qr`: " . $conn->error . "\n");
}

// 7. Crear Tabla: inscritos
$sqlTableVoters = "CREATE TABLE IF NOT EXISTS inscritos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_lista INT NOT NULL UNIQUE,
    cedula VARCHAR(15) NOT NULL UNIQUE,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    nacionalidad VARCHAR(50) DEFAULT 'DOMINICANA',
    colegio_electoral VARCHAR(20) NOT NULL,
    recinto_ubicacion TEXT NOT NULL,
    direccion TEXT NOT NULL,
    sector VARCHAR(100) NOT NULL,
    municipio VARCHAR(100) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    coordinador VARCHAR(100) NOT NULL,
    centro_acopio VARCHAR(100) NOT NULL,
    registrado_por INT DEFAULT NULL,
    canal_origen ENUM('Manual', 'OCR', 'WhatsApp Bot', 'QR Campaign') DEFAULT 'Manual',
    periodo VARCHAR(10) DEFAULT '2028',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registrado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_cedula (cedula),
    INDEX idx_municipio_sector (municipio, sector),
    INDEX idx_coordinador (coordinador),
    INDEX idx_colegio (colegio_electoral)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sqlTableVoters)) {
    echo "✓ Tabla `inscritos` creada.\n";
} else {
    die("ERROR al crear tabla `inscritos`: " . $conn->error . "\n");
}

// 8. Crear Tabla: bot_sesiones
$sqlTableBotSessions = "CREATE TABLE IF NOT EXISTS bot_sesiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telefono VARCHAR(20) NOT NULL UNIQUE,
    paso INT DEFAULT 1,
    temp_data JSON DEFAULT NULL,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_telefono (telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sqlTableBotSessions)) {
    echo "✓ Tabla `bot_sesiones` creada.\n";
} else {
    die("ERROR al crear tabla `bot_sesiones`: " . $conn->error . "\n");
}

// 9. Crear Tabla: chat_mensajes
$sqlTableChat = "CREATE TABLE IF NOT EXISTS chat_mensajes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coordinador_telefono VARCHAR(20) NOT NULL,
    coordinador_nombre VARCHAR(100) NOT NULL,
    direccion ENUM('entrante', 'saliente') NOT NULL,
    mensaje TEXT NOT NULL,
    leido TINYINT DEFAULT 0,
    usuario_id INT DEFAULT NULL,
    fecha_envio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_coordinador (coordinador_telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sqlTableChat)) {
    echo "✓ Tabla `chat_mensajes` creada.\n";
} else {
    die("ERROR al crear tabla `chat_mensajes`: " . $conn->error . "\n");
}

// 10. Crear Tabla: helpdesk_incidencias
$sqlTableHelpdesk = "CREATE TABLE IF NOT EXISTS helpdesk_incidencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reportado_por VARCHAR(100) NOT NULL,
    rol_reportante ENUM('Coordinador', 'Digitador', 'Administrador') NOT NULL,
    tipo_incidencia ENUM('Campaña Electoral', 'Anomalía del Sistema', 'Soporte Técnico', 'Otro') NOT NULL,
    descripcion TEXT NOT NULL,
    estado ENUM('Pendiente', 'En Proceso', 'Resuelto') DEFAULT 'Pendiente',
    soporte_asignado VARCHAR(100) DEFAULT 'Soporte TI de Turno',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_resolucion TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sqlTableHelpdesk)) {
    echo "✓ Tabla `helpdesk_incidencias` creada.\n";
} else {
    die("ERROR al crear tabla `helpdesk_incidencias`: " . $conn->error . "\n");
}

// 11. Crear Tabla: logs_auditoria
$sqlTableAudit = "CREATE TABLE IF NOT EXISTS logs_auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT DEFAULT NULL,
    accion VARCHAR(100) NOT NULL,
    tabla_afectada VARCHAR(50) DEFAULT NULL,
    registro_id INT DEFAULT NULL,
    detalles TEXT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    fecha_evento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_usuario (usuario_id),
    INDEX idx_fecha (fecha_evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sqlTableAudit)) {
    echo "✓ Tabla `logs_auditoria` creada.\n";
} else {
    die("ERROR al crear tabla `logs_auditoria`: " . $conn->error . "\n");
}

// 12. Sembrar Usuarios por Defecto (Roles: Administrador, Digitador, Coordinador, Jefe Electoral)
echo "=== SEMBRANDO USUARIOS POR DEFECTO ===\n";

$defaultUsers = [
    [
        'username' => 'admin',
        'password' => password_hash('admin123', HASH_ALGORITHM, ['cost' => HASH_COST]),
        'nombre' => 'Administrador Principal',
        'role' => 'Administrador',
        'perms' => [1, 1, 1, 1, 1] // can_create, can_edit, can_view, can_print, can_send
    ],
    [
        'username' => 'digitador1',
        'password' => password_hash('digitador123', HASH_ALGORITHM, ['cost' => HASH_COST]),
        'nombre' => 'Digitador de Apoyo SDE',
        'role' => 'Digitador',
        'perms' => [1, 1, 1, 1, 1] // Todos los permisos para digitador por defecto
    ],
    [
        'username' => 'coordinador1',
        'password' => password_hash('coordinador123', HASH_ALGORITHM, ['cost' => HASH_COST]),
        'nombre' => 'Coordinador Regional SDE 3',
        'role' => 'Coordinador',
        'perms' => [0, 0, 1, 1, 1] // Ver, Imprimir, Enviar (No Crear/Editar en DB directamente)
    ],
    [
        'username' => 'jefe1',
        'password' => password_hash('jefe123', HASH_ALGORITHM, ['cost' => HASH_COST]),
        'nombre' => 'Jefe Electoral Campaña ADLS',
        'role' => 'Jefe Electoral',
        'perms' => [0, 0, 1, 1, 1] // Consulta Padrón y Reportes en Tiempo Real
    ]
];

foreach ($defaultUsers as $u) {
    // Verificar si el usuario ya existe
    $usernameEsc = $conn->real_escape_string($u['username']);
    $resCheck = $conn->query("SELECT id FROM usuarios WHERE username = '$usernameEsc'");
    
    if ($resCheck && $resCheck->num_rows === 0) {
        $passEsc = $conn->real_escape_string($u['password']);
        $nombreEsc = $conn->real_escape_string($u['nombre']);
        $roleEsc = $conn->real_escape_string($u['role']);
        
        $sqlInsert = "INSERT INTO usuarios (username, password, nombre, role) VALUES ('$usernameEsc', '$passEsc', '$nombreEsc', '$roleEsc')";
        if ($conn->query($sqlInsert)) {
            $userId = $conn->insert_id;
            echo "  ✓ Creado usuario: {$u['username']} (Rol: {$u['role']})\n";
            
            // Insertar permisos correspondientes
            $canC = $u['perms'][0];
            $canE = $u['perms'][1];
            $canV = $u['perms'][2];
            $canP = $u['perms'][3];
            $canS = $u['perms'][4];
            
            $sqlPerms = "INSERT INTO permisos (usuario_id, can_create, can_edit, can_view, can_print, can_send) 
                         VALUES ($userId, $canC, $canE, $canV, $canP, $canS)";
            $conn->query($sqlPerms);
        } else {
            echo "  ✗ Error al crear usuario {$u['username']}: " . $conn->error . "\n";
        }
    } else {
        echo "  - Usuario {$u['username']} ya existe. Omitiendo.\n";
    }
}

$conn->close();
echo "=== INSTALACIÓN COMPLETADA CON ÉXITO ===\n";
?>
