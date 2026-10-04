<?php
/**
 * API: Consulta y Validación del Padrón Maestro (JCE / PRM)
 * PAD/28-32 - Plataforma Electoral
 * 
 * Búsqueda de alta velocidad por cédula para autocompletado y validación de recinto/colegio
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../ValidadorDocumentos.php';

$cedula = trim($_GET['cedula'] ?? '');
$search = trim($_GET['search'] ?? '');

// Validar que el usuario esté autenticado para búsquedas globales/masivas
if (empty($cedula)) {
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión para realizar búsquedas globales."]);
        exit;
    }
}

$db = Database::getInstance();
$conn = $db->getConnection();

if (!empty($cedula)) {
    // Normalizar formato de cédula a 000-0000000-0
    $cleanCed = preg_replace('/[^0-9]/', '', $cedula);
    
    // Validación estricta algorítmica (Luhn Mod 10)
    if (strlen($cleanCed) !== 11 || !ValidadorDocumentos::validarCedula($cleanCed)) {
        echo json_encode([
            "exito" => false,
            "encontrado" => false,
            "mensaje" => "Número de cédula inválido según el algoritmo oficial de verificación (Luhn Mod 10)."
        ]);
        exit;
    }
    
    $cedFormatted = substr($cleanCed, 0, 3) . '-' . substr($cleanCed, 3, 7) . '-' . substr($cleanCed, 10, 1);
    
    $cedEsc = $conn->real_escape_string($cedFormatted);
    $cleanEsc = $conn->real_escape_string($cleanCed);

    // 1. Consultar en el Padrón Maestro Oficial
    $sql = "
        SELECT pm.*, cr.direccion_recinto as dir_recinto_oficial, cr.es_nuevo as recinto_es_nuevo
        FROM padron_maestro_consulta pm
        LEFT JOIN catalogo_recintos_jce cr ON pm.codigo_recinto = cr.codigo_recinto
        WHERE pm.cedula = '$cedEsc' OR REPLACE(pm.cedula, '-', '') = '$cleanEsc'
        LIMIT 1
    ";
    
    $res = $conn->query($sql);
    
    if ($res && $res->num_rows > 0) {
        $v = $res->fetch_assoc();
        
        // Determinar mejor teléfono sugerido
        $telSugerido = '';
        if (!empty($v['celular'])) {
            $telSugerido = $v['celular'];
        } elseif (!empty($v['telefono_fijo'])) {
            $telSugerido = $v['telefono_fijo'];
        }
        
        echo json_encode([
            "exito" => true,
            "encontrado" => true,
            "fuente" => "PADRON_MAESTRO_JCE",
            "elector" => [
                "cedula" => $v['cedula'],
                "nombres" => $v['nombres'],
                "apellidos" => $v['apellidos'],
                "nombre_completo" => trim($v['nombres'] . ' ' . $v['apellidos']),
                "telefono_fijo" => $v['telefono_fijo'] ?? '',
                "celular" => $v['celular'] ?? '',
                "telefono_sugerido" => $telSugerido,
                "direccion" => $v['direccion'] ?? '',
                "colegio_electoral" => $v['colegio_electoral'],
                "codigo_recinto" => $v['codigo_recinto'] ?? '',
                "recinto" => $v['nombre_recinto'] ?? '',
                "recinto_direccion" => $v['dir_recinto_oficial'] ?? '',
                "numero_orden" => intval($v['numero_orden'] ?? 0),
                "posicion_recinto" => $v['posicion_recinto'] ?? (!empty($v['codigo_recinto']) ? $v['codigo_recinto'] . '-' . ($v['numero_orden'] ?? '0') : ''),
                "sector" => $v['sector'] ?? '',
                "municipio" => $v['municipio'] ?? 'SANTO DOMINGO ESTE',
                "distrito_municipal" => $v['distrito_municipal'] ?? '',
                "region" => $v['region'] ?? '',
                "militancia_prm" => intval($v['militancia_prm'] ?? 1),
                "militancia_historica" => $v['militancia_historica'] ?? ''
            ]
        ]);
        exit;
    }
    
    // 2. Fallback: Consultar en la tabla inscritos
    $sqlInscritos = "SELECT * FROM inscritos WHERE cedula = '$cedEsc' OR REPLACE(cedula, '-', '') = '$cleanEsc' LIMIT 1";
    $resIns = $conn->query($sqlInscritos);
    if ($resIns && $resIns->num_rows > 0) {
        $ins = $resIns->fetch_assoc();
        echo json_encode([
            "exito" => true,
            "encontrado" => true,
            "fuente" => "INSCRITOS_PAD2832",
            "elector" => [
                "cedula" => $ins['cedula'],
                "nombres" => $ins['nombres'],
                "apellidos" => $ins['apellidos'],
                "nombre_completo" => trim($ins['nombres'] . ' ' . $ins['apellidos']),
                "telefono_sugerido" => $ins['telefono'] ?? '',
                "direccion" => $ins['direccion'] ?? '',
                "colegio_electoral" => $ins['colegio_electoral'],
                "codigo_recinto" => '',
                "recinto" => $ins['recinto_ubicacion'] ?? '',
                "sector" => $ins['sector'] ?? '',
                "municipio" => $ins['municipio'] ?? 'SANTO DOMINGO ESTE',
                "region" => '',
                "militancia_prm" => 1
            ]
        ]);
        exit;
    }
    
    echo json_encode([
        "exito" => true,
        "encontrado" => false,
        "mensaje" => "La cédula no se encuentra registrada en el Padrón Maestro de la Circunscripción 3."
    ]);
    exit;
}

if (!empty($search)) {
    $sEsc = $conn->real_escape_string($search);
    $sql = "
        SELECT cedula, nombres, apellidos, colegio_electoral, nombre_recinto, sector, municipio, region, celular, telefono_fijo
        FROM padron_maestro_consulta
        WHERE cedula LIKE '%$sEsc%' 
           OR nombres LIKE '%$sEsc%' 
           OR apellidos LIKE '%$sEsc%' 
           OR colegio_electoral LIKE '%$sEsc%'
           OR nombre_recinto LIKE '%$sEsc%'
        LIMIT 50
    ";
    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = $r;
        }
    }
    echo json_encode([
        "exito" => true,
        "total" => count($list),
        "resultados" => $list
    ]);
    exit;
}

http_response_code(400);
echo json_encode(["exito" => false, "mensaje" => "Parámetro cedula o search requerido."]);
