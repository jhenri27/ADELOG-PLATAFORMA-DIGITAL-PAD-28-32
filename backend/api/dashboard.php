<?php
/**
 * API: Estadísticas del Dashboard y Gráficos
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';

// Validar inicio de sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión."]);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

try {
    // 1. Resumen de Métricas Principales
    // Total Inscritos
    $resTotal = $conn->query("SELECT COUNT(*) as total FROM inscritos");
    $totalInscritos = intval($resTotal->fetch_assoc()['total']);
    
    // Desglose por origen
    $resOrigins = $conn->query("SELECT canal_origen, COUNT(*) as cantidad FROM inscritos GROUP BY canal_origen");
    $origenes = [
        "Manual" => 0,
        "OCR" => 0,
        "WhatsApp Bot" => 0,
        "QR Campaign" => 0
    ];
    if ($resOrigins) {
        while ($row = $resOrigins->fetch_assoc()) {
            $origenes[$row['canal_origen']] = intval($row['cantidad']);
        }
    }
    
    // Total Campañas Activas
    $resQr = $conn->query("SELECT COUNT(*) as total FROM campanas_qr");
    $totalCampanas = intval($resQr->fetch_assoc()['total']);
    
    // Total Incidencias Helpdesk Abiertas
    $resInc = $conn->query("SELECT COUNT(*) as total FROM helpdesk_incidencias WHERE estado != 'Resuelto'");
    $incidenciasAbiertas = intval($resInc->fetch_assoc()['total']);
    
    // 2. Crecimiento Real-Time por Región vs Contienda 2024
    // Obtener los datos históricos
    $resHist = $conn->query("SELECT region, votos_diputada FROM resultados_historicos ORDER BY votos_diputada DESC");
    $regionesStats = [];
    
    // Inicializar estadísticas por región
    $regionesNombres = [
        'REGION 3-B', 'REGION 3', 'REGION 3-E', 'REGION 3-C', 'REGION 3-D', 
        'REGION 3-A', 'SAN LUIS', 'LA CALETA', 'BOCA CHICA', 'REGION 3-F', 'GUERRA'
    ];
    
    $votosHistoricos = [];
    $inscritosActuales = [];
    foreach ($regionesNombres as $r) {
        $votosHistoricos[$r] = 0;
        $inscritosActuales[$r] = 0;
    }
    
    // Llenar votos históricos
    if ($resHist) {
        while ($row = $resHist->fetch_assoc()) {
            $rName = strtoupper($row['region']);
            // Mapeo flexible
            if (str_contains($rName, 'GUERRA')) $rName = 'GUERRA';
            if (str_contains($rName, 'SAN LUIS')) $rName = 'SAN LUIS';
            if (str_contains($rName, 'LA CALETA')) $rName = 'LA CALETA';
            if (str_contains($rName, 'BOCA CHICA')) $rName = 'BOCA CHICA';
            
            if (isset($votosHistoricos[$rName])) {
                $votosHistoricos[$rName] = intval($row['votos_diputada']);
            }
        }
    }
    
    // Obtener inscritos actuales y agruparlos heurísticamente
    $resVoters = $conn->query("SELECT sector, municipio, recinto_ubicacion FROM inscritos");
    if ($resVoters) {
        while ($row = $resVoters->fetch_assoc()) {
            $muni = strtoupper($row['municipio'] ?? '');
            $sect = strtoupper($row['sector'] ?? '');
            $rec = strtoupper($row['recinto_ubicacion'] ?? '');
            
            // Heurística de agrupación por región
            $mappedRegion = 'REGION 3'; // default
            
            if (str_contains($muni, 'GUERRA') || str_contains($sect, 'GUERRA')) {
                $mappedRegion = 'GUERRA';
            } elseif (str_contains($muni, 'SAN LUIS') || str_contains($sect, 'BONITO') || str_contains($sect, 'SAN LUIS')) {
                $mappedRegion = 'SAN LUIS';
            } elseif (str_contains($muni, 'CALETA') || str_contains($sect, 'CALETA')) {
                $mappedRegion = 'LA CALETA';
            } elseif (str_contains($muni, 'BOCA CHICA') || str_contains($sect, 'BOCA CHICA')) {
                $mappedRegion = 'BOCA CHICA';
            } elseif (str_contains($sect, '3-B') || str_contains($sect, '3B') || str_contains($rec, '3-B') || str_contains($rec, '3B')) {
                $mappedRegion = 'REGION 3-B';
            } elseif (str_contains($sect, '3-E') || str_contains($sect, '3E') || str_contains($rec, '3-E') || str_contains($rec, '3E')) {
                $mappedRegion = 'REGION 3-E';
            } elseif (str_contains($sect, '3-C') || str_contains($sect, '3C') || str_contains($rec, '3-C') || str_contains($rec, '3C')) {
                $mappedRegion = 'REGION 3-C';
            } elseif (str_contains($sect, '3-D') || str_contains($sect, '3D') || str_contains($rec, '3-D') || str_contains($rec, '3D')) {
                $mappedRegion = 'REGION 3-D';
            } elseif (str_contains($sect, '3-A') || str_contains($sect, '3A') || str_contains($rec, '3-A') || str_contains($rec, '3A')) {
                $mappedRegion = 'REGION 3-A';
            } elseif (str_contains($sect, '3-F') || str_contains($sect, '3F') || str_contains($rec, '3-F') || str_contains($rec, '3F')) {
                $mappedRegion = 'REGION 3-F';
            }
            
            $inscritosActuales[$mappedRegion]++;
        }
    }
    
    // Armar el payload para los gráficos de Chart.js
    $chartData = [];
    foreach ($regionesNombres as $r) {
        $chartData[] = [
            "region" => $r,
            "votos_2024" => $votosHistoricos[$r],
            "inscritos_2026" => $inscritosActuales[$r]
        ];
    }
    
    // 3. Actividad Reciente de Registro (Últimos 7 días)
    $actividad7Dias = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $actividad7Dias[$date] = 0;
    }
    
    $resDaily = $conn->query("SELECT DATE(fecha_registro) as fecha, COUNT(*) as cantidad 
                             FROM inscritos 
                             WHERE fecha_registro >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                             GROUP BY DATE(fecha_registro)");
    if ($resDaily) {
        while ($row = $resDaily->fetch_assoc()) {
            $fecha = $row['fecha'];
            if (isset($actividad7Dias[$fecha])) {
                $actividad7Dias[$fecha] = intval($row['cantidad']);
            }
        }
    }
    
    $dailyChart = [];
    foreach ($actividad7Dias as $fecha => $cantidad) {
        $dailyChart[] = [
            "fecha" => date('d/m', strtotime($fecha)),
            "cantidad" => $cantidad
        ];
    }

    echo json_encode([
        "exito" => true,
        "resumen" => [
            "total_inscritos" => $totalInscritos,
            "origenes" => $origenes,
            "total_campanas" => $totalCampanas,
            "incidencias_abiertas" => $incidenciasAbiertas
        ],
        "regiones" => $chartData,
        "actividad_diaria" => $dailyChart
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "exito" => false,
        "mensaje" => "Error al obtener estadísticas: " . $e->getMessage()
    ]);
}
?>
