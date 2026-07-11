<?php
/**
 * Migración para configurar las tablas del Módulo de Configuración y ETL
 */

require_once __DIR__ . '/../backend/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    echo "=== CREANDO TABLAS PARA SECCIÓN A ===\n";
    
    // 1. Tabla configuraciones
    $sqlConfig = "CREATE TABLE IF NOT EXISTS configuraciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clave VARCHAR(100) NOT NULL UNIQUE,
        valor TEXT NOT NULL
    )";
    if ($conn->query($sqlConfig)) {
        echo "✓ Tabla 'configuraciones' configurada.\n";
    }
    
    // Sembrar valores por defecto si no existen
    $defaultConfigs = [
        'candidato_nombre' => 'Pastora Altagracia De Los Santos',
        'candidato_cargo' => 'Diputada Santo Domingo Circ. 3',
        'plataforma_nombre' => 'Plataforma Oficial Digital Pastora Altagracia',
        'candidato_logo_url' => 'GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png',
        'limite_intentos_login' => '5',
        'bloqueo_ip_tiempo' => '15',
        'inactividad_sesion' => '30'
    ];
    
    foreach ($defaultConfigs as $clave => $valor) {
        $clEsc = $conn->real_escape_string($clave);
        $valEsc = $conn->real_escape_string($valor);
        $conn->query("INSERT IGNORE INTO configuraciones (clave, valor) VALUES ('$clEsc', '$valEsc')");
    }
    echo "✓ Semilla de 'configuraciones' realizada.\n";
    
    // 2. Tabla servidor_smtp
    $sqlSmtp = "CREATE TABLE IF NOT EXISTS servidor_smtp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        smtp_host VARCHAR(150) NOT NULL,
        smtp_port INT NOT NULL,
        smtp_user VARCHAR(150) NOT NULL,
        smtp_pass VARCHAR(255) NOT NULL,
        smtp_secure VARCHAR(10) NOT NULL,
        from_email VARCHAR(150) NOT NULL,
        from_name VARCHAR(150) NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    if ($conn->query($sqlSmtp)) {
        echo "✓ Tabla 'servidor_smtp' configurada.\n";
        
        // Sembrar valores SMTP por defecto (desde config.php)
        $conn->query("INSERT IGNORE INTO servidor_smtp (id, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, from_email, from_name)
                      VALUES (1, 'smtp.gmail.com', 587, 'contacto@pastoraaltagracia.com', '', 'tls', 'registro-pad@pastoraaltagracia.com', 'Campana Pastora Altagracia')");
    }
    
    // 3. Tabla logs_notificaciones
    $sqlLogs = "CREATE TABLE IF NOT EXISTS logs_notificaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(20) NOT NULL,
        destinatario VARCHAR(150) NOT NULL,
        asunto VARCHAR(200) NULL,
        mensaje TEXT NOT NULL,
        estado VARCHAR(20) DEFAULT 'pendiente',
        detalles_error TEXT NULL,
        fecha_envio DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    if ($conn->query($sqlLogs)) {
        echo "✓ Tabla 'logs_notificaciones' configurada.\n";
    }
    
    // 4. Tabla api_keys_integracion
    $sqlApis = "CREATE TABLE IF NOT EXISTS api_keys_integracion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        servicio VARCHAR(50) NOT NULL UNIQUE,
        client_id VARCHAR(150) NULL,
        api_key VARCHAR(255) NOT NULL,
        api_url VARCHAR(255) NOT NULL,
        estado INT DEFAULT 1
    )";
    if ($conn->query($sqlApis)) {
        echo "✓ Tabla 'api_keys_integracion' configurada.\n";
        
        // Sembrar Google Vision API Key por defecto (desde config.php)
        $conn->query("INSERT IGNORE INTO api_keys_integracion (servicio, client_id, api_key, api_url, estado)
                      VALUES ('ocr_service', NULL, 'AIzaSyAkWDsH18r5JmUuSyTTBz3cnpwP6pu4k1s', 'https://vision.googleapis.com/v1/images:annotate', 1)");
    }
    
    // 5. Tabla historial_etl
    $sqlEtl = "CREATE TABLE IF NOT EXISTS historial_etl (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre_archivo VARCHAR(150) NOT NULL,
        registros_cargados INT DEFAULT 0,
        registros_omitidos INT DEFAULT 0,
        detalles_errores TEXT NULL,
        usuario_id INT NULL,
        fecha_ejecucion DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    if ($conn->query($sqlEtl)) {
        echo "✓ Tabla 'historial_etl' configurada.\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
