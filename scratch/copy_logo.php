<?php
$src = 'C:/Users/jhenr/.gemini/antigravity/brain/343dee55-ec1a-4f91-8b98-6924ccd0b94d/adelog_logo_icon_1783568080761.png';
$dest = 'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/GRAFICOS PARA LA PAGINA WEB/adelog_logo_icon.png';

if (file_exists($src)) {
    if (copy($src, $dest)) {
        echo "Logo copiado con éxito a: $dest\n";
    } else {
        echo "Error al copiar el archivo.\n";
    }
} else {
    echo "El archivo de origen no existe.\n";
}
?>
