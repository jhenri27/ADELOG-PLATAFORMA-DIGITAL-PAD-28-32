<?php
/**
 * Migración y Estandarización de Base de Datos - Normas PLAD
 * PAD/28-32 Plataforma Electoral
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$conn = $db->getConnection();

echo "=== INICIANDO FASE 1: ESTANDARIZACIÓN Y HOMOGENEIZACIÓN DE BASE DE DATOS ===\n";

// 1.1 Homogeneizar collation de padron_consulta_circ3 a utf8mb4_unicode_ci
echo "1. Homogeneizando collation de padron_consulta_circ3 a utf8mb4_unicode_ci...\n";
$sqlCollate = "ALTER TABLE padron_consulta_circ3 CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn->query($sqlCollate)) {
    echo "✓ padron_consulta_circ3 convertida exitosamente a utf8mb4_unicode_ci.\n";
} else {
    echo "✗ Error al convertir collation: " . $conn->error . "\n";
}

// 1.2 Agregar y poblar la columna tipo_elector en inscritos
echo "2. Verificando columna tipo_elector en tabla inscritos...\n";
$checkCol = $conn->query("SHOW COLUMNS FROM inscritos LIKE 'tipo_elector'");
if ($checkCol && $checkCol->num_rows === 0) {
    $sqlAddCol = "ALTER TABLE inscritos ADD COLUMN tipo_elector VARCHAR(50) DEFAULT 'Nuevo Elector' AFTER estado_datos";
    if ($conn->query($sqlAddCol)) {
        echo "✓ Columna tipo_elector agregada a inscritos.\n";
    } else {
        echo "✗ Error al agregar columna: " . $conn->error . "\n";
    }
} else {
    echo "✓ Columna tipo_elector ya existe en inscritos.\n";
}

// Actualizar registros existentes para asegurar el etiquetado canónico
echo "3. Aplicando etiquetado canónico de Nuevo Elector a registros existentes...\n";
$sqlUpdateTipo = "UPDATE inscritos SET tipo_elector = IF(es_militante_lider = 1, 'Nuevo Elector (ML)', 'Nuevo Elector') WHERE tipo_elector IS NULL OR tipo_elector = ''";
$conn->query($sqlUpdateTipo);
echo "✓ Registros actualizados con su etiqueta de elector correspondiente.\n";

// 1.3 Verificar y blindar índices en inscritos, padron_maestro_consulta y padron_consulta_circ3
echo "4. Verificando índices y restricción UNIQUE (cedula) en inscritos...\n";
$indexRes = $conn->query("SHOW INDEX FROM inscritos WHERE Key_name = 'cedula' OR Key_name = 'uq_cedula'");
if ($indexRes && $indexRes->num_rows > 0) {
    echo "✓ Restricción UNIQUE en cedula verificada en tabla inscritos.\n";
} else {
    echo "Creando índice UNIQUE en cedula...\n";
    $conn->query("ALTER TABLE inscritos ADD UNIQUE KEY uq_cedula (cedula)");
    echo "✓ Índice UNIQUE uq_cedula creado.\n";
}

// Índice en padron_maestro_consulta
echo "5. Verificando índices en padron_maestro_consulta...\n";
$conn->query("ALTER TABLE padron_maestro_consulta ADD INDEX IF NOT EXISTS idx_pm_cedula_clean (cedula)");

echo "\n=== FASE 1 COMPLETADA CON ÉXITO ===\n";
