<?php
require_once __DIR__ . '/db.php';

echo "=== MIGRACIÓN Y NORMALIZACIÓN DE PADRÓN MAESTRO (PLAD) ===\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

// 1. Agregar columna posicion_recinto si no existe
$checkCol = $conn->query("SHOW COLUMNS FROM padron_maestro_consulta LIKE 'posicion_recinto'");
if ($checkCol && $checkCol->num_rows === 0) {
    $conn->query("ALTER TABLE padron_maestro_consulta ADD COLUMN posicion_recinto VARCHAR(30) NULL AFTER numero_orden, ADD INDEX idx_pm_pos_rec (posicion_recinto)");
    echo "✓ Columna posicion_recinto agregada con éxito.\n";
} else {
    echo "✓ Columna posicion_recinto ya existe.\n";
}

// 2. Limpieza masiva de colegio_electoral (remover prefijos de total de electores ej. '588 1608B' -> '1608B')
echo "\n--- Limpiando códigos de colegios electorales y generando posicion_recinto ---\n";

// Ejecutar limpieza con consultas SQL optimizadas
$conn->query("
    UPDATE padron_maestro_consulta
    SET colegio_electoral = SUBSTRING_INDEX(colegio_electoral, ' ', -1)
    WHERE colegio_electoral LIKE '% %'
");
echo "✓ Prefijos numéricos de colegios eliminados masivamente.\n";

// 3. Actualización de registros para asegurar posicion_recinto poblado
$conn->query("
    UPDATE padron_maestro_consulta 
    SET posicion_recinto = CONCAT(IFNULL(codigo_recinto, '00000'), '-', IFNULL(numero_orden, '0')) 
    WHERE posicion_recinto IS NULL OR posicion_recinto = ''
");
echo "✓ Identificadores posicion_recinto consolidados.\n";

// 4. Actualización del caso específico ERICKSON RAMON CARVAJAL FORTUNA (001-1153817-9)
$sqlErickson = "
    UPDATE padron_maestro_consulta 
    SET 
        colegio_electoral = '1608B',
        codigo_recinto = '00445',
        nombre_recinto = 'LICEO PROFESOR SIMON OROZCO',
        numero_orden = 77,
        posicion_recinto = '00445-77',
        celular = '829-638-2726',
        telefono_fijo = '809-594-7376',
        direccion = 'MANZANA 4691',
        sector = 'INVIVIENDA',
        municipio = 'SANTO DOMINGO ESTE',
        region = 'REGION 3',
        militancia_historica = 'RATIFICADO (MA PR 24M 24P EP)'
    WHERE cedula = '001-1153817-9' OR REPLACE(cedula, '-', '') = '00111538179'
";
$conn->query($sqlErickson);
echo "✓ Registro de ERICKSON RAMON CARVAJAL FORTUNA actualizado con datos ratificados (Posición: 00445-77 | Cel: 829-638-2726 | Dir: MANZANA 4691).\n";

echo "\n=== NORMALIZACIÓN COMPLETADA CON ÉXITO ===\n";
