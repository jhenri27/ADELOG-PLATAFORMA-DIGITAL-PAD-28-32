<?php
$files = [
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/backend/api/voters.php',
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/frontend/assets/js/app.js',
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/frontend/index.html',
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/frontend/login.html',
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/README.md',
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/comprobante.php',
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/frontend/assets/descargas/formulario_captacion.html',
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/frontend/assets/descargas/manual_promotor.html',
    'C:/wamp64/www/PLATAFORMA DIGITAL-PAD-28-32/frontend/assets/descargas/manual_promotor_template.html'
];

$replacements = [
    '© Campaña Pastora Altagracia - PRM 2026. Todos los derechos reservados.' => 'Desarrollado para la administración de logisticas de comandos de campañas en RD, por sypempresariales . Copyright © 2026 Sypempresariales.',
    '© 2026 Campaña Pastora Altagracia - Diputada SDE Circ. 3. Todos los derechos reservados.' => 'Desarrollado para la administración de logisticas de comandos de campañas en RD, por sypempresariales . Copyright © 2026 Sypempresariales.',
    '*Desarrollado para la campaña Pastora Altagracia - PRM 2026/2028. Todos los derechos reservados.*' => 'Desarrollado para la administración de logisticas de comandos de campañas en RD, por sypempresariales . Copyright © 2026 Sypempresariales.',
    '© Campaña Pastora Altagracia SDE Circ. 3' => 'Desarrollado para la administración de logisticas de comandos de campañas en RD, por sypempresariales . Copyright © 2026 Sypempresariales.',
    'Campaña Pastora Altagracia - PRM 2026. Todos los derechos reservados.' => 'Desarrollado para la administración de logisticas de comandos de campañas en RD, por sypempresariales . Copyright © 2026 Sypempresariales.'
];

foreach ($files as $filePath) {
    if (!file_exists($filePath)) {
        echo "[WARN] Archivo no encontrado: $filePath\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    $original = $content;
    
    foreach ($replacements as $search => $replace) {
        $content = str_replace($search, $replace, $content);
    }
    
    if ($content !== $original) {
        file_put_contents($filePath, $content);
        echo "[OK] Reemplazo completado en: $filePath\n";
    } else {
        echo "[INFO] Sin cambios necesarios en: $filePath\n";
    }
}
?>
