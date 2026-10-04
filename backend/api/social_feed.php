<?php
/**
 * API PÚBLICA: Feed y Enlaces Oficiales de Redes Sociales + Marca Pública
 * PAD/28-32 — Campaña Pastora Altagracia
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../db.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? 'get_feed';

if ($action === 'get_feed') {
    // 1. Obtener configuraciones públicas de marca y redes
    $resConfig = $conn->query("SELECT clave, valor FROM configuraciones WHERE clave IN (
        'social_instagram_url', 
        'social_facebook_url', 
        'social_tiktok_url', 
        'social_x_url', 
        'candidato_nombre',
        'candidato_cargo',
        'plataforma_nombre',
        'candidato_logo_url',
        'login_banner_url'
    )");
    
    $configs = [];
    if ($resConfig) {
        while ($row = $resConfig->fetch_assoc()) {
            $configs[$row['clave']] = $row['valor'];
        }
    }

    $perfiles = [
        'instagram' => !empty($configs['social_instagram_url']) ? $configs['social_instagram_url'] : 'https://www.instagram.com/pastoraaltagraciard/',
        'facebook' => !empty($configs['social_facebook_url']) ? $configs['social_facebook_url'] : 'https://www.facebook.com/Apostolaltagraciard',
        'tiktok' => !empty($configs['social_tiktok_url']) ? $configs['social_tiktok_url'] : 'https://www.tiktok.com/@pastoraaltagraciard',
        'x' => !empty($configs['social_x_url']) ? $configs['social_x_url'] : 'https://x.com/pastoraaltard',
        'candidato' => $configs['candidato_nombre'] ?? 'Pastora Altagracia',
        'cargo' => $configs['candidato_cargo'] ?? 'Diputada Santo Domingo Circ. 3',
        'plataforma' => $configs['plataforma_nombre'] ?? 'Plataforma Oficial Digital PAD/28-32',
        'logo_url' => $configs['candidato_logo_url'] ?? '../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832-02.png'
    ];

    // 2. Obtener publicaciones activas
    $resPosts = $conn->query("SELECT * FROM redes_sociales_feed WHERE activo = 1 ORDER BY id DESC LIMIT 10");
    $publicaciones = [];
    if ($resPosts) {
        while ($p = $resPosts->fetch_assoc()) {
            $publicaciones[] = $p;
        }
    }

    echo json_encode([
        "exito" => true,
        "marca" => $perfiles,
        "perfiles" => $perfiles,
        "publicaciones" => $publicaciones
    ]);
    exit;
}

http_response_code(400);
echo json_encode(["exito" => false, "mensaje" => "Acción no válida."]);
