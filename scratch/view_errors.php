<?php
$logPath = 'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/backend/api/error.log';
if (file_exists($logPath)) {
    echo "--- BACKEND API ERROR LOG ---\n";
    $lines = file($logPath);
    $lastLines = array_slice($lines, -15);
    echo implode("", $lastLines);
} else {
    echo "No API error log found at $logPath.\n";
}

$rootLogPath = 'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/backend/error.log';
if (file_exists($rootLogPath)) {
    echo "\n--- ROOT ERROR LOG ---\n";
    $lines = file($rootLogPath);
    $lastLines = array_slice($lines, -15);
    echo implode("", $lastLines);
} else {
    echo "No root error log found at $rootLogPath.\n";
}
?>
