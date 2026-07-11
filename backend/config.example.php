<?php
/**
 * Plantilla de Configuración de Base de Datos y Sistema (Ejemplo)
 * Renombrar a config.php y rellenar con sus credenciales correspondientes.
 * ADELOG - Plataforma Electoral
 */

// ==================== CONFIGURACIÓN DE BASE DE DATOS ====================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', ''); // Contraseña de base de datos
define('DB_NAME', 'pad_electoral_2832');
define('DB_PORT', 3306);

// ==================== CONFIGURACIÓN DE APLICACIÓN ====================
define('APP_NAME', 'ADELOG - Plataforma Electoral');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'America/Santo_Domingo');
date_default_timezone_set(APP_TIMEZONE);

// ==================== CONFIGURACIÓN DE SEGURIDAD ====================
define('HASH_ALGORITHM', PASSWORD_BCRYPT);
define('HASH_COST', 10);

// ==================== CONFIGURACIÓN DE API Y RUTAS ====================
define('API_BASE_URL', 'http://localhost/PLATAFORMA DIGITAL-PAD-28-32/backend/api');
define('FRONTEND_BASE_URL', 'http://localhost/PLATAFORMA DIGITAL-PAD-28-32/frontend');

// ==================== CONFIGURACIÓN DE GOOGLE VISION API (OCR) ====================
define('GOOGLE_VISION_API_KEY', 'TU_API_KEY_DE_GOOGLE_VISION_AQUI');
define('GOOGLE_VISION_KEY_PATH', 'C:/path/to/google-key.json');

// ==================== CONFIGURACIÓN DE CORREO (SMTP) ====================
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'tu-correo@gmail.com');
define('MAIL_PASSWORD', 'tu-contraseña-aplicacion-google');
define('MAIL_FROM', 'registro-pad@tu-dominio.com');
define('MAIL_FROM_NAME', 'Campaña Electoral');

// ==================== CONFIGURACIÓN DE ERRORES ====================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');
?>
