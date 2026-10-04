<?php
require_once __DIR__ . '/db.php';

echo "=== MIGRACIÓN DE TABLA Y CONFIGURACIONES DE REDES SOCIALES ===\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

// 1. Crear tabla redes_sociales_feed
$sqlTable = "
CREATE TABLE IF NOT EXISTS redes_sociales_feed (
    id INT AUTO_INCREMENT PRIMARY KEY,
    red_social ENUM('Instagram', 'Facebook', 'TikTok', 'X') NOT NULL DEFAULT 'Instagram',
    autor VARCHAR(100) NOT NULL DEFAULT 'Pastora Altagracia',
    tiempo_publicacion VARCHAR(50) NOT NULL DEFAULT 'Reciente',
    contenido TEXT NOT NULL,
    hashtags VARCHAR(255) DEFAULT '#PastoraDiputada #SDECir3 #PRM',
    enlace_publicacion VARCHAR(255) NULL,
    imagen_url VARCHAR(255) NULL,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$conn->query($sqlTable);
echo "✓ Tabla redes_sociales_feed verificada/creada.\n";

// 2. Insertar enlaces oficiales por defecto si no existen en configuraciones
$defaultConfigs = [
    'social_instagram_url' => 'https://www.instagram.com/pastoraaltagraciard/',
    'social_facebook_url' => 'https://www.facebook.com/Apostolaltagraciard',
    'social_tiktok_url' => 'https://www.tiktok.com/@pastoraaltagraciard',
    'social_x_url' => 'https://x.com/pastoraaltard'
];

foreach ($defaultConfigs as $key => $val) {
    $kEsc = $conn->real_escape_string($key);
    $vEsc = $conn->real_escape_string($val);
    $conn->query("INSERT IGNORE INTO configuraciones (clave, valor) VALUES ('$kEsc', '$vEsc')");
}
echo "✓ Parámetros de redes sociales insertados en configuraciones.\n";

// 3. Sembrar publicaciones iniciales si la tabla está vacía
$checkRows = $conn->query("SELECT COUNT(*) as total FROM redes_sociales_feed");
$count = $checkRows ? intval($checkRows->fetch_assoc()['total']) : 0;

if ($count === 0) {
    $posts = [
        [
            'red_social' => 'Instagram',
            'autor' => 'Pastora Altagracia',
            'tiempo_publicacion' => 'Hace 2 horas',
            'contenido' => 'Agradecida del inmenso apoyo de nuestra comunidad en Santo Domingo Este Circ. 3 durante nuestro recorrido de hoy. ¡El cambio sigue con fuerza y determinación! 🇩🇴💪',
            'hashtags' => '#PastoraDiputada #SDECir3 #PRM #ElCambioSigue',
            'enlace_publicacion' => 'https://www.instagram.com/pastoraaltagraciard/',
            'activo' => 1
        ],
        [
            'red_social' => 'Facebook',
            'autor' => 'Pastora Altagracia',
            'tiempo_publicacion' => 'Ayer',
            'contenido' => 'Extraordinaria reunión de planificación con nuestro equipo de coordinadores de centros de acopio en Invivienda y San Luis. Afinando los detalles logísticos para asegurar una victoria contundente en cada colegio electoral. 📋✨',
            'hashtags' => '#LogisticaElectoral #VictoriaAsegurada #PastoraAltagracia',
            'enlace_publicacion' => 'https://www.facebook.com/Apostolaltagraciard',
            'activo' => 1
        ],
        [
            'red_social' => 'Instagram',
            'autor' => 'Pastora Altagracia',
            'tiempo_publicacion' => 'Hace 3 días',
            'contenido' => 'Jornada comunitaria y operativo de registro en Los Frailes y Boca Chica. Escuchando las necesidades de nuestras familias y reafirmando nuestro compromiso legislativo por el desarrollo de la Circunscripción 3. 🤝🗳️',
            'hashtags' => '#CompromisoSocial #SantoDomingoEste #BocaChica #Pastora2026',
            'enlace_publicacion' => 'https://www.instagram.com/pastoraaltagraciard/',
            'activo' => 1
        ]
    ];

    $stmt = $conn->prepare("INSERT INTO redes_sociales_feed (red_social, autor, tiempo_publicacion, contenido, hashtags, enlace_publicacion, activo) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($posts as $p) {
        $stmt->bind_param("ssssssi", $p['red_social'], $p['autor'], $p['tiempo_publicacion'], $p['contenido'], $p['hashtags'], $p['enlace_publicacion'], $p['activo']);
        $stmt->execute();
    }
    $stmt->close();
    echo "✓ 3 publicaciones iniciales de campaña sembradas en redes_sociales_feed.\n";
} else {
    echo "✓ Ya existen $count publicaciones en redes_sociales_feed.\n";
}

echo "\n=== MIGRACIÓN COMPLETADA CON ÉXITO ===\n";
