<?php
require_once __DIR__ . '/../backend/db.php';

echo "=== MIGRACIÓN: CREACIÓN DE TABLAS DE PADRÓN MAESTRO Y CATÁLOGO JCE ===\n\n";

$db = Database::getInstance();
$conn = $db->getConnection();

// 1. Tabla de Catálogo Oficial de Recintos JCE
$sqlRecintos = "
CREATE TABLE IF NOT EXISTS catalogo_recintos_jce (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_recinto VARCHAR(10) NOT NULL UNIQUE,
    nombre_recinto VARCHAR(255) NOT NULL,
    direccion_recinto TEXT NULL,
    sector VARCHAR(100) NULL,
    municipio VARCHAR(100) DEFAULT 'SANTO DOMINGO ESTE',
    distrito_municipal VARCHAR(100) NULL,
    circunscripcion VARCHAR(10) DEFAULT '03',
    region VARCHAR(50) NULL,
    es_nuevo TINYINT(1) DEFAULT 0,
    ano_creacion VARCHAR(20) NULL,
    total_colegios INT DEFAULT 0,
    total_inscritos INT DEFAULT 0,
    INDEX idx_rec_sector (sector),
    INDEX idx_rec_region (region),
    INDEX idx_rec_mun (municipio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$conn->query($sqlRecintos);
echo "✓ Tabla catalogo_recintos_jce verificada/creada.\n";

// 2. Tabla de Catálogo Oficial de Colegios Electorales JCE
$sqlColegios = "
CREATE TABLE IF NOT EXISTS catalogo_colegios_jce (
    id INT AUTO_INCREMENT PRIMARY KEY,
    colegio_numero VARCHAR(15) NOT NULL UNIQUE,
    codigo_recinto VARCHAR(10) NOT NULL,
    total_inscritos INT DEFAULT 0,
    ano_creacion VARCHAR(20) NULL,
    INDEX idx_col_rec (codigo_recinto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$conn->query($sqlColegios);
echo "✓ Tabla catalogo_colegios_jce verificada/creada.\n";

// 3. Tabla del Padrón Maestro de Consulta (PRM / JCE)
$sqlPadronMaestro = "
CREATE TABLE IF NOT EXISTS padron_maestro_consulta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cedula VARCHAR(15) NOT NULL UNIQUE,
    numero_orden INT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    telefono_fijo VARCHAR(30) NULL,
    celular VARCHAR(30) NULL,
    direccion TEXT NULL,
    colegio_electoral VARCHAR(15) NOT NULL,
    codigo_recinto VARCHAR(10) NULL,
    nombre_recinto VARCHAR(255) NULL,
    sector VARCHAR(100) NULL,
    municipio VARCHAR(100) DEFAULT 'SANTO DOMINGO ESTE',
    distrito_municipal VARCHAR(100) NULL,
    region VARCHAR(50) NULL,
    militancia_prm TINYINT(1) DEFAULT 1,
    militancia_historica VARCHAR(100) NULL,
    fecha_ingesta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pm_colegio (colegio_electoral),
    INDEX idx_pm_recinto (codigo_recinto),
    INDEX idx_pm_sector (sector),
    INDEX idx_pm_region (region),
    INDEX idx_pm_nombres (nombres, apellidos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$conn->query($sqlPadronMaestro);
echo "✓ Tabla padron_maestro_consulta verificada/creada.\n";

echo "\n=== MIGRACIÓN DE ESTRUCTURA BD COMPLETADA EXITOSAMENTE ===\n";
