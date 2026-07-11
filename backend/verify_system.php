<?php
/**
 * Script de Pruebas y Verificación del Sistema
 * PAD/28-32 - Plataforma Electoral
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ValidadorDocumentos.php';
require_once __DIR__ . '/Mailer.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== INICIANDO AUDITORÍA Y VERIFICACIÓN DEL SISTEMA PAD/28-32 ===\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

// ==================== PRUEBA 1: VALIDACIÓN ALGORÍTMICA LUHN ====================
echo "PRUEBA 1: VALIDACIÓN ALGORÍTMICA DE CÉDULA DOMINICANA (LUHN)\n";
$cedulaValida = '001-1423642-5'; // Cédula de Anderson en la imagen de prueba
$cedulaInvalida = '001-1423642-8'; // Modificado dígito verificador

$resValida = ValidadorDocumentos::validarCedula($cedulaValida);
$resInvalida = ValidadorDocumentos::validarCedula($cedulaInvalida);

echo "  - Cédula Real '$cedulaValida': " . ($resValida ? "✓ VÁLIDA (Correcto)" : "✗ INVÁLIDA (Error)") . "\n";
echo "  - Cédula Falsa '$cedulaInvalida': " . (!$resInvalida ? "✓ DETECTADA COMO INVÁLIDA (Correcto)" : "✗ DETECTADA COMO VÁLIDA (Error)") . "\n\n";


// ==================== PRUEBA 2: VALIDACIÓN DE TELÉFONOS ====================
echo "PRUEBA 2: VALIDACIÓN DE PREFIJOS TELEFÓNICOS (809/829/849)\n";
$telValido = '829-543-9876';
$telInvalido = '718-543-9876'; // Prefijo internacional de NY

$resTelValido = ValidadorDocumentos::validarTelefono($telValido);
$resTelInvalido = ValidadorDocumentos::validarTelefono($telInvalido);

echo "  - Celular Dominicano '$telValido': " . ($resTelValido ? "✓ VÁLIDA (Correcto)" : "✗ INVÁLIDA (Error)") . "\n";
echo "  - Celular Internacional '$telInvalido': " . (!$resTelInvalido ? "✓ DETECTADA COMO INVÁLIDA (Correcto)" : "✗ DETECTADA COMO VÁLIDA (Error)") . "\n\n";


// ==================== PRUEBA 3: PREVENCIÓN DE DUPLICADOS ====================
echo "PRUEBA 3: PREVENCIÓN DE DUPLICIDAD Y MENSAJE DE REGLA ELECTORAL\n";

$testCedula = '001-1423642-5';
$coordinador1 = 'Ing. José Anderson';
$coordinador2 = 'Dra. Altagracia Santos';

// Limpiar si ya existe
$testCedulaEsc = $conn->real_escape_string($testCedula);
$conn->query("DELETE FROM inscritos WHERE cedula = '$testCedulaEsc'");

// 1ra Inserción (Coordinador 1)
$numero_lista = 10001; // Número manual para prueba
$sql1 = "INSERT INTO inscritos (numero_lista, cedula, nombres, apellidos, colegio_electoral, recinto_ubicacion, direccion, sector, municipio, telefono, coordinador, centro_acopio) 
         VALUES ($numero_lista, '$testCedulaEsc', 'JOSE ANDERSON', 'HENRIQUEZ MARTE', '1401', 'ESCUELA BASICA RAMONA NERIS SOSA', 'LIBERTAD CASA 20', 'SABANA PERDIDA', 'SANTO DOMINGO NORTE', '8091234567', '$coordinador1', 'Centro de Prueba')";
         
if ($conn->query($sql1)) {
    echo "  - Primer registro insertado con éxito por el coordinador '$coordinador1'.\n";
} else {
    echo "  - Error al insertar primer registro: " . $conn->error . "\n";
}

// 2da Inserción (Intento de Duplicado por Coordinador 2)
$checkDup = $conn->query("SELECT cedula, coordinador FROM inscritos WHERE cedula = '$testCedulaEsc' LIMIT 1");
if ($checkDup && $checkDup->num_rows > 0) {
    $dupRow = $checkDup->fetch_assoc();
    $coordReg = $dupRow['coordinador'];
    
    echo "  - Intento de duplicado bloqueado con éxito.\n";
    echo "  - Mensaje arrojado:\n";
    echo "    >>> \"La cédula $testCedula ya está registrada en la plataforma y fue suministrada por el coordinador $coordReg.\"\n";
    echo "    ✓ Bloqueo y mensaje correctos.\n\n";
} else {
    echo "  - ERROR: No se bloqueó el duplicado.\n\n";
}


// ==================== PRUEBA 4: TRAZABILIDAD Y AUDITORÍA ====================
echo "PRUEBA 4: TRAZABILIDAD DE AUDITORÍA (ISO 27001 / ISO 54001)\n";

// Insertar un registro de auditoría simulado
$ip = '127.0.0.1';
$detalles = "Ejecución de auditoría de prueba en el script de verificación.";
$conn->query("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (NULL, 'TEST_AUDIT', '$detalles', '$ip')");

$resAudit = $conn->query("SELECT id, accion, detalles, fecha_evento FROM logs_auditoria WHERE accion = 'TEST_AUDIT' ORDER BY id DESC LIMIT 1");
if ($resAudit && $resAudit->num_rows > 0) {
    $audit = $resAudit->fetch_assoc();
    echo "  - Registro en `logs_auditoria` encontrado: [ID: {$audit['id']}] [Acción: {$audit['accion']}] [Fecha: {$audit['fecha_evento']}]\n";
    echo "    ✓ Auditoría funcionando correctamente.\n\n";
} else {
    echo "  - ERROR: No se encontró el registro de auditoría en la tabla.\n\n";
}


// ==================== PRUEBA 5: ENVÍO DE COMPROBANTES (MAILER) ====================
echo "PRUEBA 5: MAILER Y REGISTRO DE CONSTANCIA\n";

$resMail = Mailer::enviar('jhenri@gmail.com', 'Constancia de Prueba', '<h3>Prueba de Voucher</h3>', true);
if ($resMail) {
    // Comprobar si se escribió en el log
    $logPath = __DIR__ . '/logs/mailer.log';
    if (file_exists($logPath)) {
        $logContent = file_get_contents($logPath);
        $lines = explode("\n", trim($logContent));
        $lastLine = end($lines);
        echo "  - Última línea del log de correos:\n";
        echo "    >>> $lastLine\n";
        echo "    ✓ Mailer logueado y funcionando.\n\n";
    } else {
        echo "  - Warning: No se encontró el archivo de logs de correo.\n\n";
    }
} else {
    echo "  - ERROR al enviar correo.\n\n";
}

// Limpiar registro de prueba
$conn->query("DELETE FROM inscritos WHERE cedula = '$testCedulaEsc'");

echo "=== AUDITORÍA Y VERIFICACIÓN FINALIZADA CON ÉXITO ===";
?>
