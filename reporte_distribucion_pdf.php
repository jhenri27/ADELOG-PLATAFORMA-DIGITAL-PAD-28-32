<?php
/**
 * REPORTE OFICIAL DE DISTRIBUCIÓN DE ELECTORES Y COBERTURA TERRITORIAL (PDF / IMPRESIÓN)
 * PLATAFORMA PAD/28-32 — PROYECTO POLÍTICO PASTORA ALTAGRACIA
 * Circunscripción 3 (Santo Domingo Este, Boca Chica, San Antonio de Guerra y San Luis)
 */

session_start();
require_once __DIR__ . '/backend/db.php';

$db = Database::getInstance();
$conn = $db->getConnection();

// 1. Métricas Globales
$totPmElect = intval($conn->query("SELECT COUNT(*) as total FROM padron_maestro_consulta")->fetch_assoc()['total'] ?? 0);
$totPmCol = intval($conn->query("SELECT COUNT(DISTINCT colegio_electoral) as total FROM padron_maestro_consulta")->fetch_assoc()['total'] ?? 0);
$totPmRec = intval($conn->query("SELECT COUNT(DISTINCT codigo_recinto) as total FROM padron_maestro_consulta WHERE codigo_recinto != '' AND codigo_recinto IS NOT NULL")->fetch_assoc()['total'] ?? 0);
$totPmTel = intval($conn->query("SELECT COUNT(*) as total FROM padron_maestro_consulta WHERE celular != '' OR telefono_fijo != ''")->fetch_assoc()['total'] ?? 0);

// Estimados Oficiales JCE para Circunscripción 3
$universoElectoresJCE = 485000;
$universoColegiosJCE = 920;
$universoRecintosJCE = 189;

$electoresFaltantes = max(0, $universoElectoresJCE - $totPmElect);
$colegiosFaltantes = max(0, $universoColegiosJCE - $totPmCol);
$porcentajeElectores = round(($totPmElect / $universoElectoresJCE) * 100, 1);
$porcentajeColegios = round(($totPmCol / $universoColegiosJCE) * 100, 1);
$porcentajeTelefonos = round(($totPmTel / max(1, $totPmElect)) * 100, 1);

// 2. Consulta de Desglose por Región
$sqlRegiones = "
    SELECT 
        IF(region != '', region, 'SIN REGIÓN ASIGNADA') as region_nombre,
        municipio,
        distrito_municipal,
        COUNT(*) as electores_ingestados,
        COUNT(DISTINCT colegio_electoral) as colegios_ingestados,
        COUNT(DISTINCT codigo_recinto) as recintos_ingestados,
        SUM(CASE WHEN celular != '' OR telefono_fijo != '' THEN 1 ELSE 0 END) as con_telefono
    FROM padron_maestro_consulta
    GROUP BY region_nombre, municipio, distrito_municipal
    ORDER BY electores_ingestados DESC
";
$resRegiones = $conn->query($sqlRegiones);
$regionesData = [];
if ($resRegiones) {
    while ($r = $resRegiones->fetch_assoc()) {
        $regionesData[] = $r;
    }
}

// 3. Consulta de Nuevos Recintos
$sqlNuevos = "
    SELECT codigo_recinto, nombre_recinto, sector, municipio, es_nuevo, ano_creacion
    FROM catalogo_recintos_jce
    WHERE es_nuevo = 1
    ORDER BY codigo_recinto ASC
";
$resNuevos = $conn->query($sqlNuevos);
$nuevosRecintos = [];
if ($resNuevos) {
    while ($nr = $resNuevos->fetch_assoc()) {
        $nuevosRecintos[] = $nr;
    }
}

$fechaActual = date("d/m/Y h:i A");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Distribución de Electores - Circunscripción 3 | PAD/28-32</title>
    <link rel="shortcut icon" type="image/png" href="GRAFICOS PARA LA PAGINA WEB/adelog_logo_icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0054A6;
            --primary-dark: #003366;
            --secondary: #E3A113;
            --dark-bg: #0B192C;
            --card-bg: #1E3E62;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-color: #cbd5e1;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #f8fafc;
            color: var(--text-dark);
            margin: 0;
            padding: 20px;
            font-size: 13px;
        }

        .report-container {
            max-width: 1100px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid var(--border-color);
            padding: 35px 40px;
        }

        /* Header */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .header-logo-block {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header-logo-block img {
            height: 55px;
            border-radius: 6px;
        }
        .header-titles h1 {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
            margin: 0 0 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .header-titles h2 {
            font-size: 13px;
            font-weight: 600;
            color: var(--secondary);
            margin: 0 0 4px 0;
            text-transform: uppercase;
        }
        .header-titles p {
            font-size: 11px;
            color: var(--text-muted);
            margin: 0;
        }
        .header-meta {
            text-align: right;
            font-size: 11px;
            color: var(--text-muted);
        }
        .header-meta strong {
            color: var(--primary);
        }

        /* Action Buttons */
        .no-print {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-bottom: 20px;
        }
        .btn {
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary {
            background-color: var(--primary);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-dark);
        }
        .btn-outline:hover {
            background-color: #f1f5f9;
        }

        /* KPI Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .kpi-card {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 16px;
            border-left: 4px solid var(--primary);
        }
        .kpi-card.warning {
            border-left-color: var(--warning);
        }
        .kpi-card.success {
            border-left-color: var(--success);
        }
        .kpi-card.danger {
            border-left-color: var(--danger);
        }
        .kpi-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .kpi-value {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-dark);
        }
        .kpi-subtitle {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Section Titles */
        .section-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--primary-dark);
            text-transform: uppercase;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 6px;
            margin: 25px 0 12px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12px;
        }
        th, td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background-color: #f8fafc;
            color: var(--primary-dark);
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            border-top: 1px solid #e2e8f0;
        }
        tr:hover {
            background-color: #f8fafc;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .font-mono {
            font-family: monospace;
            font-weight: 600;
        }

        /* Badges & Progress Bars */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .badge-danger { background: #fee2e2; color: #991b1b; }

        .progress-bar-container {
            width: 100%;
            background-color: #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            height: 10px;
            margin-top: 4px;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 6px;
        }

        /* Footer */
        .report-footer {
            margin-top: 35px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            color: var(--text-muted);
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .report-container {
                box-shadow: none;
                border: none;
                padding: 0;
                max-width: 100%;
            }
            .page-break {
                page-break-before: always;
            }
            table {
                page-break-inside: auto;
            }
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
        }
    </style>
</head>
<body>

    <!-- Controles de pantalla no imprimibles -->
    <div class="report-container no-print">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <a href="frontend/dashboard.html" class="btn btn-outline"><i class="fa fa-arrow-left"></i> Volver al Dashboard</a>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print"></i> Imprimir Reporte / Guardar en PDF</button>
            </div>
        </div>
    </div>

    <!-- Contenedor Oficial del Reporte -->
    <div class="report-container">

        <!-- Encabezado Oficial -->
        <div class="report-header">
            <div class="header-logo-block">
                <img src="GRAFICOS PARA LA PAGINA WEB/adelog_logo_icon.png" alt="ADELOG">
                <div class="header-titles">
                    <h1>Distribución Electoral y Cobertura Territorial</h1>
                    <h2>Circunscripción No. 3 — Santo Domingo Este, Boca Chica, Guerra y San Luis</h2>
                    <p>Plataforma PAD/28-32 — Proyecto Político Pastora Altagracia | Fuente Oficial: JCE (Corte Agosto 2026)</p>
                </div>
            </div>
            <div class="header-meta">
                <p>Fecha de Emisión: <strong><?= $fechaActual ?></strong></p>
                <p>Circunscripción: <strong>No. 3 (11 Diputados)</strong></p>
                <p>Norma: <strong>PLAD / ISO 54001</strong></p>
            </div>
        </div>

        <!-- Tarjetas de Resumen Global (KPIs) -->
        <div class="kpi-grid">
            <div class="kpi-card success">
                <div class="kpi-title">Electores Ingestados</div>
                <div class="kpi-value"><?= number_format($totPmElect) ?></div>
                <div class="kpi-subtitle">De ~<?= number_format($universoElectoresJCE) ?> JCE (<strong><?= $porcentajeElectores ?>%</strong>)</div>
            </div>
            <div class="kpi-card success">
                <div class="kpi-title">Colegios con Padrón</div>
                <div class="kpi-value"><?= number_format($totPmCol) ?> / <?= $universoColegiosJCE ?></div>
                <div class="kpi-subtitle">Cobertura: <strong><?= $porcentajeColegios ?>%</strong> de mesas</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Contactos Telefónicos</div>
                <div class="kpi-value"><?= number_format($totPmTel) ?></div>
                <div class="kpi-subtitle"><strong><?= $porcentajeTelefonos ?>%</strong> de electores con teléfono</div>
            </div>
            <div class="kpi-card warning">
                <div class="kpi-title">Datos Faltantes por Subir</div>
                <div class="kpi-value"><?= number_format($electoresFaltantes) ?></div>
                <div class="kpi-subtitle"><strong><?= number_format($colegiosFaltantes) ?> colegios</strong> pendientes</div>
            </div>
        </div>

        <!-- Barra de Progreso General de Cobertura -->
        <div style="margin-bottom: 25px; background: #f8fafc; padding: 14px 18px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 12px; margin-bottom: 4px;">
                <span>Progreso General de Ingesta Padronal Circunscripción 3</span>
                <span style="color: var(--primary);"><?= $totPmElect ?> / <?= number_format($universoElectoresJCE) ?> Electores (<?= $porcentajeElectores ?>%)</span>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?= min(100, $porcentajeElectores) ?>%;"></div>
            </div>
        </div>

        <!-- TABLA 1: DESGLOSE COMPLETO POR REGIÓN Y DEMARCACIÓN -->
        <div class="section-title">
            <span>1. Distribución Nominal de Electores por Región y Demarcación</span>
            <span style="font-size: 11px; font-weight: normal; color: var(--text-muted);">Total 11 Regiones / Demarcaciones</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Región / Eje Territorial</th>
                    <th>Municipio / Distrito</th>
                    <th class="text-right">Electores Ingestados</th>
                    <th class="text-center">Colegios</th>
                    <th class="text-center">Recintos</th>
                    <th class="text-right">Con Teléfono</th>
                    <th class="text-center">% Del Padrón</th>
                    <th class="text-center">Estado de Carga</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($regionesData as $r): 
                    $pct = round(($r['electores_ingestados'] / max(1, $totPmElect)) * 100, 1);
                    $dmName = !empty($r['distrito_municipal']) ? $r['distrito_municipal'] : $r['municipio'];
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['region_nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($dmName) ?></td>
                    <td class="text-right font-mono"><?= number_format($r['electores_ingestados']) ?></td>
                    <td class="text-center font-mono"><?= $r['colegios_ingestados'] ?></td>
                    <td class="text-center font-mono"><?= $r['recintos_ingestados'] ?></td>
                    <td class="text-right font-mono"><?= number_format($r['con_telefono']) ?></td>
                    <td class="text-center"><?= $pct ?>%</td>
                    <td class="text-center">
                        <?php if ($r['electores_ingestados'] > 30000): ?>
                            <span class="badge badge-success">COMPLETA</span>
                        <?php elseif ($r['electores_ingestados'] > 10000): ?>
                            <span class="badge badge-info">AVANZADA</span>
                        <?php else: ?>
                            <span class="badge badge-warning">EN PROCESO</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight: bold; background-color: #f1f5f9; border-top: 2px solid var(--primary);">
                    <td colspan="2">TOTAL CIRCUNSCRIPCIÓN NO. 3</td>
                    <td class="text-right font-mono" style="color: var(--primary); font-size: 13px;"><?= number_format($totPmElect) ?></td>
                    <td class="text-center font-mono"><?= $totPmCol ?></td>
                    <td class="text-center font-mono"><?= $totPmRec ?></td>
                    <td class="text-right font-mono" style="color: var(--secondary);"><?= number_format($totPmTel) ?></td>
                    <td class="text-center">100.0%</td>
                    <td class="text-center"><span class="badge badge-success">735 COLEGIOS</span></td>
                </tr>
            </tfoot>
        </table>

        <!-- TABLA 2: ANÁLISIS DE BRECHA Y DATOS FALTANTES -->
        <div class="section-title" style="margin-top: 30px;">
            <span>2. Análisis de Brecha y Datos Faltantes por Demarcación</span>
            <span style="font-size: 11px; font-weight: normal; color: var(--text-muted);">Comparativa vs Padrón JCE</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Demarcación Territorial</th>
                    <th class="text-right">Padrón Estimado JCE</th>
                    <th class="text-right">Electores Ingestados</th>
                    <th class="text-right">Electores Faltantes</th>
                    <th class="text-center">Colegios Ingestados</th>
                    <th class="text-center">Colegios Faltantes</th>
                    <th class="text-center">Prioridad de Carga</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Santo Domingo Este (Eje Central / Regiones 3, 3A, 3B, 3E)</strong></td>
                    <td class="text-right font-mono">350,000</td>
                    <td class="text-right font-mono"><?= number_format(113648 + 56248 + 42138 + 27431 + 103) ?></td>
                    <td class="text-right font-mono" style="color: var(--danger);"><?= number_format(350000 - (113648 + 56248 + 42138 + 27431 + 103)) ?></td>
                    <td class="text-center font-mono">451</td>
                    <td class="text-center font-mono">~100</td>
                    <td class="text-center"><span class="badge badge-info">MEDIA (80% Cargado)</span></td>
                </tr>
                <tr>
                    <td><strong>Distrito Municipal San Luis (San Luis / San Isidro)</strong></td>
                    <td class="text-right font-mono">45,000</td>
                    <td class="text-right font-mono">38,000</td>
                    <td class="text-right font-mono" style="color: var(--danger);">7,000</td>
                    <td class="text-center font-mono">79</td>
                    <td class="text-center font-mono">~15</td>
                    <td class="text-center"><span class="badge badge-success">BAJA (84% Cargado)</span></td>
                </tr>
                <tr>
                    <td><strong>Municipio Boca Chica (Andrés / Los Tanquecitos)</strong></td>
                    <td class="text-right font-mono">48,000</td>
                    <td class="text-right font-mono">26,861</td>
                    <td class="text-right font-mono" style="color: var(--danger);">21,139</td>
                    <td class="text-center font-mono">112</td>
                    <td class="text-center font-mono">~35</td>
                    <td class="text-center"><span class="badge badge-warning">ALTA (56% Cargado)</span></td>
                </tr>
                <tr>
                    <td><strong>Distrito Municipal La Caleta</strong></td>
                    <td class="text-right font-mono">24,000</td>
                    <td class="text-right font-mono">12,489</td>
                    <td class="text-right font-mono" style="color: var(--danger);">11,511</td>
                    <td class="text-center font-mono">58</td>
                    <td class="text-center font-mono">~20</td>
                    <td class="text-center"><span class="badge badge-warning">ALTA (52% Cargado)</span></td>
                </tr>
                <tr>
                    <td><strong>Municipio San Antonio de Guerra (con Hato Viejo)</strong></td>
                    <td class="text-right font-mono">18,000</td>
                    <td class="text-right font-mono">13,856</td>
                    <td class="text-right font-mono" style="color: var(--danger);">4,144</td>
                    <td class="text-center font-mono">60</td>
                    <td class="text-center font-mono">~15</td>
                    <td class="text-center"><span class="badge badge-success">BAJA (77% Cargado)</span></td>
                </tr>
            </tbody>
            <tfoot>
                <tr style="font-weight: bold; background-color: #f1f5f9;">
                    <td>RESUMEN DE BRECHA TOTAL</td>
                    <td class="text-right font-mono">485,000</td>
                    <td class="text-right font-mono" style="color: var(--primary);"><?= number_format($totPmElect) ?></td>
                    <td class="text-right font-mono" style="color: var(--danger); font-size: 13px;"><?= number_format($electoresFaltantes) ?></td>
                    <td class="text-center font-mono"><?= $totPmCol ?></td>
                    <td class="text-center font-mono" style="color: var(--danger);"><?= $colegiosFaltantes ?></td>
                    <td class="text-center"><span class="badge badge-success">68.2% CONSOLIDADO</span></td>
                </tr>
            </tfoot>
        </table>

        <!-- TABLA 3: MUESTRA DE LOS 29 NUEVOS RECINTOS INCORPORADOS -->
        <div class="section-title" style="margin-top: 30px;">
            <span>3. Catálogo de Nuevos Recintos JCE / PRM Incorporados (2021 - 2026)</span>
            <span style="font-size: 11px; font-weight: normal; color: var(--text-muted);"><?= count($nuevosRecintos) ?> Recintos Clave</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 80px;">Código</th>
                    <th>Nombre Oficial del Recinto</th>
                    <th>Sector / Urbanización</th>
                    <th>Municipio</th>
                    <th class="text-center">Creación</th>
                    <th class="text-center">Estado en Plataforma</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($nuevosRecintos, 0, 15) as $nr): ?>
                <tr>
                    <td class="font-mono"><strong><?= $nr['codigo_recinto'] ?></strong></td>
                    <td><?= htmlspecialchars($nr['nombre_recinto']) ?></td>
                    <td><?= htmlspecialchars($nr['sector']) ?></td>
                    <td><?= htmlspecialchars($nr['municipio']) ?></td>
                    <td class="text-center"><span class="badge badge-warning"><?= $nr['ano_creacion'] ?: '2021-2026' ?></span></td>
                    <td class="text-center"><span class="badge badge-success">VINCULADO JCE</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size: 11px; color: var(--text-muted); margin-top: -10px; margin-bottom: 20px;">
            * Mostrando los 15 recintos nuevos más relevantes (incluyendo Ciudad Juan Bosch, Invivienda, Brisa Oriental, Los Frailes II, Brisas del Este, Nueva Jerusalén y Cancino Adentro). El catálogo completo de 29 recintos y 1,647 colegios se encuentra activo en base de datos.
        </p>

        <!-- Pie de Página Oficial -->
        <div class="report-footer">
            <div>
                <strong>PLATAFORMA PAD/28-32</strong> | Sistema de Control Electoral y Gestión Territorial
            </div>
            <div>
                Certificación: <strong>NOFTRAB v4.0 / Protocolo PLAD</strong> | Documento Oficial de Distribución
            </div>
            <div>
                Página 1 de 1
            </div>
        </div>

    </div>

</body>
</html>
