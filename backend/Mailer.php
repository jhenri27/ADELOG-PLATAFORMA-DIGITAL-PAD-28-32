<?php
/**
 * Simple Mailer Class
 * PAD/28-32 - Plataforma Electoral
 */

require_once __DIR__ . '/config.php';

class Mailer {
    public static function enviar($to, $subject, $message, $isHtml = true, $votante_id = null) {
        if (empty($to)) {
            return true;
        }

        // Intentar envío mediante SMTP de la base de datos
        try {
            require_once __DIR__ . '/db.php';
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            $resSmtp = $conn->query("SELECT * FROM servidor_smtp WHERE id = 1 LIMIT 1");
            $smtp = $resSmtp ? $resSmtp->fetch_assoc() : [];
            
            if (!empty($smtp) && !empty($smtp['smtp_host']) && !empty($smtp['smtp_user'])) {
                require_once __DIR__ . '/libs/PHPMailer/Exception.php';
                require_once __DIR__ . '/libs/PHPMailer/PHPMailer.php';
                require_once __DIR__ . '/libs/PHPMailer/SMTP.php';
                
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtp['smtp_host'];
                $mail->SMTPAuth = !empty($smtp['smtp_pass']);
                $mail->Username = $smtp['smtp_user'];
                $mail->Password = $smtp['smtp_pass'];
                $mail->SMTPSecure = $smtp['smtp_secure'] === 'tls' ? 'tls' : 'ssl';
                $mail->Port = intval($smtp['smtp_port']);
                
                // Evitar fallos de certificados locales en el handshake
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );
                
                $mail->setFrom($smtp['from_email'], $smtp['from_name']);
                $mail->addAddress($to);
                $mail->isHTML($isHtml);
                $mail->Subject = $subject;
                $mail->Body = $message;
                $mail->CharSet = 'UTF-8';
                
                $result = $mail->send();
                $estadoLog = $result ? "enviado" : "fallido";
                self::registrarLogDB('email', $to, $subject, $message, $estadoLog, $votante_id);
                self::registrarLogFile($to, $subject, "PHPMailer: " . strtoupper($estadoLog));
                return $result;
            }
        } catch (Exception $e) {
            error_log("Error en Mailer PHPMailer: " . $e->getMessage());
            self::registrarLogDB('email', $to, $subject, $message, 'fallido', $votante_id, $e->getMessage());
            self::registrarLogFile($to, $subject, "PHPMailer ERROR: " . $e->getMessage());
        }

        // Fallback a mail() de PHP
        try {
            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            if ($isHtml) {
                $headers[] = 'Content-type: text/html; charset=utf-8';
            } else {
                $headers[] = 'Content-type: text/plain; charset=utf-8';
            }
            $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
            $headers[] = 'Reply-To: ' . MAIL_FROM;
            $headers[] = 'X-Mailer: PHP/' . phpversion();

            $headerStr = implode("\r\n", $headers);
            $result = @mail($to, $subject, $message, $headerStr);
            
            $estadoLog = $result ? "enviado" : "fallido";
            self::registrarLogDB('email', $to, $subject, $message, $estadoLog, $votante_id);
            self::registrarLogFile($to, $subject, "mail() Fallback: " . strtoupper($estadoLog));
            
            return true;
        } catch (Exception $e) {
            error_log("Error al enviar correo en Mailer native: " . $e->getMessage());
            self::registrarLogDB('email', $to, $subject, $message, 'fallido', $votante_id, $e->getMessage());
            self::registrarLogFile($to, $subject, "Native ERROR: " . $e->getMessage());
            return false;
        }
    }

    private static function registrarLogFile($to, $subject, $status) {
        $logFile = __DIR__ . '/logs/mailer.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0777, true);
        }
        $date = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$date}] [{$status}] Para: {$to} | Asunto: {$subject}\n", FILE_APPEND);
    }

    private static function registrarLogDB($tipo, $destinatario, $asunto, $mensaje, $status, $votante_id = null, $detalles_error = '') {
        try {
            require_once __DIR__ . '/db.php';
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            $tipoEsc = $conn->real_escape_string($tipo);
            $destEsc = $conn->real_escape_string($destinatario);
            $asuntoEsc = $conn->real_escape_string($asunto);
            $msgEsc = $conn->real_escape_string($mensaje);
            $statusEsc = $conn->real_escape_string($status);
            $voterIdVal = $votante_id ? intval($votante_id) : 'NULL';
            $errEsc = $conn->real_escape_string($detalles_error);
            
            $sql = "INSERT INTO logs_notificaciones (tipo, destinatario, asunto, mensaje, estado, detalles_error, votante_id) 
                    VALUES ('$tipoEsc', '$destEsc', '$asuntoEsc', '$msgEsc', '$statusEsc', '$errEsc', $voterIdVal)";
            $conn->query($sql);
        } catch (Exception $e) {
            error_log("Error al registrar log en BD: " . $e->getMessage());
        }
    }
}
?>
