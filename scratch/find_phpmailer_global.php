<?php
function findPHPMailerGlobal($dir, $depth = 0) {
    if ($depth > 3) return;
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            if (str_contains(strtolower($file), 'phpmailer') || $file === 'vendor') {
                echo "Found directory: " . $path . "\n";
            }
            findPHPMailerGlobal($path, $depth + 1);
        }
    }
}
findPHPMailerGlobal('C:/wamp64/www');
?>
