<?php
/**
 * Script: Update SMTP configurations with real credentials
 * ADELOG - Plataforma Electoral
 */

require_once __DIR__ . '/../backend/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $host = "smtp.gmail.com";
    $port = 587;
    $user = "pastorandersonhenriquez@gmail.com";
    $pass = "ujxw vwkx ftik cydf";
    $secure = "tls";
    $from_email = "pastorandersonhenriquez@gmail.com";
    $from_name = "Campaña Pastora Altagracia";
    
    // Check if record with id = 1 exists
    $res = $conn->query("SELECT id FROM servidor_smtp WHERE id = 1 LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $sql = "UPDATE servidor_smtp SET 
                    smtp_host = '$host', 
                    smtp_port = $port, 
                    smtp_user = '$user', 
                    smtp_pass = '$pass', 
                    smtp_secure = '$secure', 
                    from_email = '$from_email', 
                    from_name = '$from_name' 
                WHERE id = 1";
    } else {
        $sql = "INSERT INTO servidor_smtp (id, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, from_email, from_name) 
                VALUES (1, '$host', $port, '$user', '$pass', '$secure', '$from_email', '$from_name')";
    }
    
    if ($conn->query($sql)) {
        echo "✓ Credenciales SMTP reales actualizadas correctamente en la base de datos.\n";
    } else {
        echo "Error al actualizar SMTP: " . $conn->error . "\n";
    }
} catch (Exception $e) {
    echo "Excepción: " . $e->getMessage() . "\n";
}
?>
