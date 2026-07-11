<?php
$dir = 'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/frontend/';
foreach (glob($dir . "*.html") as $file) {
    echo "Archivo: " . basename($file) . " (Size: " . filesize($file) . " bytes)\n";
}
?>
