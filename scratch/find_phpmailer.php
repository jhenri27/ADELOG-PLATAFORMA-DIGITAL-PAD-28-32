<?php
function findPHPMailer($dir) {
    $it = new RecursiveDirectoryIterator($dir);
    foreach (new RecursiveIteratorIterator($it) as $file) {
        if ($file->isDir()) continue;
        if (str_contains(strtolower($file->getFilename()), 'phpmailer.php')) {
            echo "PHPMailer file found: " . $file->getPathname() . "\n";
        }
    }
}
findPHPMailer('C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/');
?>
