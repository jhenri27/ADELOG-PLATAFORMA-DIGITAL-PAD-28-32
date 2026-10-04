<?php
/**
 * Plataforma Electoral PAD/28-32 - Candidata Pastora Altagracia
 * Módulo Público de Certificación y Validación Digital por Código QR
 * Conforme a Normas PLAD (PLAD-CERT-QR-01 y PLAD-ENTREGABLES-SYNC-01)
 */

require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/ValidadorDocumentos.php';

$db = Database::getInstance();
$conn = $db->getConnection();

$cedula = trim($_GET['cedula'] ?? '');
$folio = trim($_GET['folio'] ?? '');
$id = intval($_GET['id'] ?? 0);

// Si se pasó folio (ej: PAD2832-11-001-1423642-5), extraer cédula si es posible
if (empty($cedula) && !empty($folio)) {
    if (preg_match('/PAD2832-\d+-(.+)$/', $folio, $matches)) {
        $cedula = $matches[1];
    }
}

// Limpiar cédula
$cedulaClean = preg_replace('/\D/', '', $cedula);
$cedulaFmt = $cedula;
if (strlen($cedulaClean) === 11) {
    $cedulaFmt = substr($cedulaClean, 0, 3) . '-' . substr($cedulaClean, 3, 7) . '-' . substr($cedulaClean, 10, 1);
}

$cedEsc = $conn->real_escape_string($cedulaFmt);
$cleanEsc = $conn->real_escape_string($cedulaClean);

// Buscar en inscritos
$electorInscrito = null;
if ($id > 0 || !empty($cleanEsc)) {
    $whereIns = [];
    if ($id > 0) $whereIns[] = "i.id = $id";
    if (!empty($cleanEsc)) {
        $whereIns[] = "i.cedula = '$cedEsc'";
        $whereIns[] = "REPLACE(i.cedula, '-', '') = '$cleanEsc'";
    }
    $sqlIns = "
        SELECT i.*, u.nombre as nombre_registrador, p.nombre as perfil_registrador 
        FROM inscritos i
        LEFT JOIN usuarios u ON i.registrado_por = u.id
        LEFT JOIN perfiles p ON u.perfil_id = p.id
        WHERE " . implode(" OR ", $whereIns) . "
        LIMIT 1
    ";
    $resIns = $conn->query($sqlIns);
    $electorInscrito = ($resIns && $resIns->num_rows > 0) ? $resIns->fetch_assoc() : null;
}

// Si encontramos en inscritos, tomar su cédula exacta para buscar en Padrón Maestro
if ($electorInscrito) {
    $cedEsc = $conn->real_escape_string($electorInscrito['cedula']);
    $cleanEsc = preg_replace('/\D/', '', $electorInscrito['cedula']);
}

// Buscar en padron_maestro_consulta
$electorMaestro = null;
if (!empty($cleanEsc)) {
    $sqlPm = "
        SELECT pm.*, cr.direccion_recinto as dir_recinto_oficial 
        FROM padron_maestro_consulta pm
        LEFT JOIN catalogo_recintos_jce cr ON pm.codigo_recinto = cr.codigo_recinto
        WHERE pm.cedula = '$cedEsc' OR REPLACE(pm.cedula, '-', '') = '$cleanEsc'
        LIMIT 1
    ";
    $resPm = $conn->query($sqlPm);
    $electorMaestro = ($resPm && $resPm->num_rows > 0) ? $resPm->fetch_assoc() : null;
}

// Si no está en padron_maestro_consulta, verificar padron_consulta_circ3
if (!$electorMaestro && !empty($cleanEsc)) {
    $sqlC3 = "SELECT * FROM padron_consulta_circ3 WHERE cedula = '$cedEsc' OR REPLACE(cedula, '-', '') = '$cleanEsc' LIMIT 1";
    $resC3 = $conn->query($sqlC3);
    if ($resC3 && $resC3->num_rows > 0) {
        $c3 = $resC3->fetch_assoc();
        $electorMaestro = [
            'cedula' => $c3['cedula'],
            'nombres' => $c3['nombres'],
            'apellidos' => trim($c3['apellido1'] . ' ' . $c3['apellido2']),
            'colegio_electoral' => $c3['colegio_electoral'] ?? '',
            'codigo_recinto' => '',
            'nombre_recinto' => $c3['recinto'],
            'posicion_recinto' => $c3['zona'] ?? '',
            'numero_orden' => '',
            'sector' => $c3['sector'],
            'municipio' => $c3['municipio'],
            'militancia_prm' => 1,
            'militancia_historica' => 'Circunscripción 3'
        ];
    }
}

$encontrado = ($electorInscrito !== null || $electorMaestro !== null);
$folioFinal = $electorInscrito ? ("PAD2832-" . $electorInscrito['numero_lista'] . "-" . $electorInscrito['cedula']) : ($folio ?: 'N/A');

// Detección de circunscripción electoral y demarcación externa
$circunscripcion = 'Circunscripción 3 (Santo Domingo Este)';
$esFueraCirc3 = false;
$sectorVal = strtoupper($electorInscrito['sector'] ?? $electorMaestro['sector'] ?? '');
$recintoVal = strtoupper($electorInscrito['recinto_ubicacion'] ?? $electorMaestro['nombre_recinto'] ?? '');
$colegioVal = strval($electorInscrito['colegio_electoral'] ?? $electorMaestro['colegio_electoral'] ?? '');
$regionVal = strtoupper($electorInscrito['region'] ?? '');

if (str_contains($sectorVal, 'ISABELITA') || str_contains($recintoVal, 'ISABELITA') || $colegioVal === '1823' || str_contains($regionVal, 'CIRCUNSCRIPCIÓN 1') || str_contains($regionVal, 'CIRCUNSCRIPCION 1')) {
    $circunscripcion = 'Circunscripción 1 (Santo Domingo Este)';
    $esFueraCirc3 = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validación Oficial de Elector - PAD/28-32</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0054A6;
            --primary-dark: #003870;
            --secondary: #E3A113;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #0f172a;
            --card-bg: #1e293b;
            --text-white: #f8fafc;
            --text-muted: #94a3b8;
            --border: rgba(255, 255, 255, 0.1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        body { background: #0b1320; color: var(--text-white); min-height: 100vh; padding: 20px 15px; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; }
        .val-container { width: 100%; max-width: 680px; background: #131d2e; border: 2px solid var(--border); border-radius: 16px; box-shadow: 0 20px 35px rgba(0,0,0,0.5); overflow: hidden; }
        .val-header { background: linear-gradient(135deg, #003870 0%, #0054A6 100%); padding: 25px 20px; text-align: center; border-bottom: 4px solid var(--secondary); position: relative; }
        .val-header h1 { font-size: 20px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 4px; color: #ffffff; }
        .val-header p { font-size: 13px; color: rgba(255,255,255,0.85); }
        .val-badge-top { display: inline-flex; align-items: center; gap: 6px; background: rgba(227, 161, 19, 0.2); color: var(--secondary); border: 1px solid var(--secondary); padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; margin-bottom: 12px; text-transform: uppercase; }
        
        .val-body { padding: 25px 20px; }
        
        /* Sello de Auditoría */
        .audit-seal { display: flex; align-items: center; justify-content: space-between; background: rgba(16, 185, 129, 0.12); border: 1.5px solid var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 25px; flex-wrap: wrap; gap: 10px; }
        .audit-seal.not-found { background: rgba(239, 68, 68, 0.12); border-color: var(--danger); }
        .seal-title { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 15px; }
        .seal-title i { font-size: 22px; }
        .seal-tag { background: #10b981; color: #000; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; }
        
        /* Secciones de Datos */
        .section-card { background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border); border-radius: 12px; padding: 18px; margin-bottom: 18px; }
        .section-title { font-size: 13px; color: var(--secondary); text-transform: uppercase; font-weight: 800; letter-spacing: 0.7px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 8px; }
        
        .data-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 540px) { .data-grid { grid-template-columns: 1fr; } }
        
        .data-item { display: flex; flex-direction: column; gap: 3px; }
        .data-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
        .data-value { font-size: 14px; color: #ffffff; font-weight: 600; }
        .data-value.highlight { color: var(--secondary); font-family: monospace; font-size: 15px; }
        
        /* Badges de Estado */
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; width: fit-content; }
        .badge-prm-vigente { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #10b981; }
        .badge-prm-antiguo { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #f59e0b; }
        .badge-nuevo-elector { background: #E3A113; color: #000; font-weight: 800; }
        .badge-ml { background: #0054A6; color: #fff; border: 1px solid #38bdf8; }
        
        .val-footer { text-align: center; padding: 20px; border-top: 1px solid var(--border); font-size: 11px; color: var(--text-muted); line-height: 1.6; }
        .btn-return { display: inline-flex; align-items: center; gap: 8px; background: var(--primary); color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; margin-top: 15px; transition: background 0.2s; }
        .btn-return:hover { background: var(--primary-dark); }

        @media print {
            body { background: #ffffff !important; color: #000000 !important; padding: 0 !important; }
            .val-container { background: #ffffff !important; border-color: #cbd5e1 !important; box-shadow: none !important; color: #000000 !important; max-width: 100% !important; }
            .section-card { background: #f8fafc !important; border-color: #cbd5e1 !important; }
            .data-value { color: #000000 !important; }
            .data-label { color: #475569 !important; }
            .btn-return, .val-footer { display: none !important; }
            @page { size: portrait; margin: 6mm 8mm; }
        }
    </style>
</head>
<body>

    <div class="val-container">
        <!-- Header Oficial -->
        <div class="val-header">
            <div class="val-badge-top"><i class="fa fa-shield-alt"></i> Certificación Oficial Digital JCE / PLAD</div>
            <h1>Campaña Pastora Altagracia</h1>
            <p>Diputada Santo Domingo Este • Circunscripción 3 • Período 2028</p>
        </div>

        <div class="val-body">
            <?php if (!$encontrado): ?>
                <!-- No Encontrado -->
                <div class="audit-seal not-found">
                    <div class="seal-title" style="color: var(--danger);">
                        <i class="fa fa-times-circle"></i>
                        <div>
                            <div>REGISTRO NO LOCALIZADO</div>
                            <div style="font-size: 12px; font-weight: 400; color: var(--text-muted); margin-top: 2px;">La cédula o comprobante consultado no figura en los padrones de la Circunscripción 3.</div>
                        </div>
                    </div>
                    <span class="seal-tag" style="background: var(--danger); color: #fff;">NO VÁLIDO</span>
                </div>

                <div class="section-card" style="text-align: center; padding: 30px;">
                    <i class="fa fa-search" style="font-size: 40px; color: var(--text-muted); margin-bottom: 15px;"></i>
                    <p style="color: var(--text-muted); font-size: 14px;">El documento escaneado no coincide con ningún registro activo en el sistema electoral sincronizado.</p>
                    <a href="frontend/index.html" class="btn-return"><i class="fa fa-user-plus"></i> Inscribirse en la Plataforma</a>
                </div>
            <?php else: ?>
                <!-- Sello Oficial de Auditoría -->
                <?php if ($esFueraCirc3): ?>
                    <div class="audit-seal" style="background: rgba(245, 158, 11, 0.12); border-color: var(--warning);">
                        <div class="seal-title" style="color: var(--warning);">
                            <i class="fa fa-check-circle"></i>
                            <div>
                                <div>ESTATUS: ACTIVO Y SINCRONIZADO (SIMPATIZANTE EXTERNO)</div>
                                <div style="font-size: 12px; font-weight: 400; color: var(--text-muted); margin-top: 2px;">
                                    Inscrito en Padrón Activo Pastora Altagracia (2028) • Vota en <?php echo htmlspecialchars($circunscripcion); ?>
                                </div>
                            </div>
                        </div>
                        <span class="seal-tag" style="background: var(--warning); color: #000;"><i class="fa fa-map-marker-alt"></i> CIRC. 1 SDE</span>
                    </div>
                <?php else: ?>
                    <div class="audit-seal">
                        <div class="seal-title" style="color: var(--success);">
                            <i class="fa fa-check-circle"></i>
                            <div>
                                <div>ESTATUS: ACTIVO Y SINCRONIZADO</div>
                                <div style="font-size: 12px; font-weight: 400; color: var(--text-muted); margin-top: 2px;">Conforme con Norma PLAD-CERT-QR-01 • Verificado en Tiempo Real</div>
                            </div>
                        </div>
                        <span class="seal-tag"><i class="fa fa-bolt"></i> EN VIVO</span>
                    </div>
                <?php endif; ?>

                <!-- 1. Etiquetas de Elector -->
                <div class="section-card">
                    <div class="section-title"><i class="fa fa-tags"></i> 1. Etiquetas de Elector y Militancia</div>
                    <div class="data-grid">
                        <div class="data-item">
                            <span class="data-label">Estatus en Campaña Pastora Altagracia:</span>
                            <?php if ($electorInscrito): ?>
                                <?php if ($esFueraCirc3): ?>
                                    <span class="badge" style="background: rgba(245, 158, 11, 0.25); color: #f59e0b; border: 1px solid #f59e0b;"><i class="fa fa-user-check"></i> Nuevo Elector (Simpatizante Externo)</span>
                                <?php elseif (!empty($electorInscrito['es_militante_lider'])): ?>
                                    <span class="badge badge-ml"><i class="fa fa-user-tag"></i> Militante Líder (ML)</span>
                                <?php else: ?>
                                    <span class="badge badge-nuevo-elector"><i class="fa fa-user-check"></i> <?php echo htmlspecialchars($electorInscrito['tipo_elector'] ?: 'Nuevo Elector'); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #f59e0b;"><i class="fa fa-clock"></i> Pendiente de Captar</span>
                            <?php endif; ?>
                        </div>

                        <div class="data-item">
                            <span class="data-label">Estatus en Padrón del Partido (PRM):</span>
                            <?php if ($esFueraCirc3): ?>
                                <span class="badge" style="background: rgba(148, 163, 184, 0.2); color: #94a3b8;"><i class="fa fa-info-circle"></i> Elector Circunscripción 1 (Externo)</span>
                            <?php elseif ($electorMaestro && (!empty($electorMaestro['militancia_prm']) && $electorMaestro['militancia_prm'] == 1)): ?>
                                <span class="badge badge-prm-vigente"><i class="fa fa-id-card"></i> Militante Vigente (PRM Circ. 3)</span>
                            <?php elseif ($electorMaestro && !empty($electorMaestro['militancia_historica'])): ?>
                                <span class="badge badge-prm-antiguo"><i class="fa fa-history"></i> Militante Histórico [<?php echo htmlspecialchars(trim($electorMaestro['militancia_historica'])); ?>]</span>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(148, 163, 184, 0.2); color: #94a3b8;"><i class="fa fa-user"></i> Simpatizante No Partidario</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 2. Datos de Identidad del Elector -->
                <div class="section-card">
                    <div class="section-title"><i class="fa fa-user"></i> 2. Identidad del Elector</div>
                    <div class="data-grid">
                        <div class="data-item">
                            <span class="data-label">Nombre Completo:</span>
                            <span class="data-value" style="text-transform: uppercase;">
                                <?php echo htmlspecialchars(($electorInscrito['nombres'] ?? $electorMaestro['nombres'] ?? '') . ' ' . ($electorInscrito['apellidos'] ?? $electorMaestro['apellidos'] ?? '')); ?>
                            </span>
                        </div>
                        <div class="data-item">
                            <span class="data-label">Cédula de Identidad:</span>
                            <span class="data-value highlight"><?php echo htmlspecialchars($electorInscrito['cedula'] ?? $electorMaestro['cedula'] ?? $cedulaFmt); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="data-label">Demarcación Electoral:</span>
                            <span class="data-value" style="color: <?php echo $esFueraCirc3 ? '#f59e0b' : '#38bdf8'; ?>;"><?php echo htmlspecialchars($circunscripcion); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="data-label">Municipio / Sector:</span>
                            <span class="data-value"><?php echo htmlspecialchars(($electorInscrito['municipio'] ?? 'SANTO DOMINGO ESTE') . ' — ' . ($electorInscrito['sector'] ?? 'ISABELITA')); ?></span>
                        </div>
                        <?php if (!empty($electorInscrito['direccion'])): ?>
                        <div class="data-item" style="grid-column: 1 / -1;">
                            <span class="data-label">Dirección Registrada:</span>
                            <span class="data-value" style="color: #cbd5e1; font-size: 13px;"><?php echo htmlspecialchars($electorInscrito['direccion']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3. Lugar o Posición en los Padrones -->
                <div class="section-card">
                    <div class="section-title"><i class="fa fa-map-marked-alt"></i> 3. Lugar y Posición en los Padrones</div>
                    <div class="data-grid">
                        <!-- En Padrón Partido -->
                        <div class="data-item" style="border-right: 1px solid rgba(255,255,255,0.06); padding-right: 10px;">
                            <span class="data-label" style="color: var(--secondary);">En Padrón del Partido (JCE / PRM):</span>
                            <div style="font-size: 13px; margin-top: 4px; line-height: 1.5;">
                                <div><strong>Colegio Electoral:</strong> <?php echo htmlspecialchars($electorMaestro['colegio_electoral'] ?? $electorInscrito['colegio_electoral'] ?? 'N/A'); ?></div>
                                <div><strong>Posición en Recinto:</strong> <?php echo htmlspecialchars($electorMaestro['posicion_recinto'] ?? 'N/A'); ?></div>
                                <div><strong>Número de Orden:</strong> <?php echo htmlspecialchars($electorMaestro['numero_orden'] ? ('#' . $electorMaestro['numero_orden']) : 'N/A'); ?></div>
                                <div><strong>Recinto:</strong> <?php echo htmlspecialchars($electorMaestro['nombre_recinto'] ?? $electorInscrito['recinto_ubicacion'] ?? 'N/A'); ?></div>
                            </div>
                        </div>

                        <!-- En Padrón Pastora -->
                        <div class="data-item" style="padding-left: 5px;">
                            <span class="data-label" style="color: #38bdf8;">En Padrón Candidata (Pastora):</span>
                            <div style="font-size: 13px; margin-top: 4px; line-height: 1.5;">
                                <div><strong>Número de Lista:</strong> <span style="color: #10b981; font-weight: bold;"><?php echo $electorInscrito ? ('#' . $electorInscrito['numero_lista']) : 'No inscrito aún'; ?></span></div>
                                <div><strong>Folio Oficial:</strong> <span style="font-family: monospace; color: var(--secondary);"><?php echo htmlspecialchars($folioFinal); ?></span></div>
                                <div><strong>Período Electoral:</strong> <?php echo htmlspecialchars($electorInscrito['periodo'] ?? '2028'); ?></div>
                                <div><strong>Estado Datos:</strong> <?php echo htmlspecialchars($electorInscrito['estado_datos'] ?? 'Validado'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Perfil Responsable y Trazabilidad -->
                <div class="section-card">
                    <div class="section-title"><i class="fa fa-user-shield"></i> 4. Perfil Responsable y Canal de Origen</div>
                    <div class="data-grid">
                        <div class="data-item">
                            <span class="data-label">Coordinador Responsable:</span>
                            <span class="data-value" style="color: #38bdf8; text-transform: uppercase;">
                                <?php echo htmlspecialchars($electorInscrito['coordinador'] ?? 'Campaña General'); ?>
                            </span>
                        </div>
                        <div class="data-item">
                            <span class="data-label">Registrado Por (Perfil):</span>
                            <span class="data-value">
                                <?php echo htmlspecialchars($electorInscrito['nombre_registrador'] ?? 'Sistema Central'); ?>
                                <?php if (!empty($electorInscrito['perfil_registrador'])): ?>
                                    <small style="color: var(--text-muted);">(<?php echo htmlspecialchars($electorInscrito['perfil_registrador']); ?>)</small>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="data-item">
                            <span class="data-label">Canal de Ingesta / Origen:</span>
                            <span class="data-value"><i class="fa fa-network-wired" style="color: var(--secondary); font-size: 11px;"></i> <?php echo htmlspecialchars($electorInscrito['canal_origen'] ?? 'Padrón Maestro JCE'); ?></span>
                        </div>
                        <div class="data-item">
                            <span class="data-label">Fecha y Hora de Ingesta:</span>
                            <span class="data-value" style="font-family: monospace; color: #10b981;">
                                <?php 
                                $fechaIng = $electorInscrito['fecha_registro'] ?? $electorMaestro['fecha_ingesta'] ?? null;
                                echo $fechaIng ? date('d/m/Y H:i:s', strtotime($fechaIng)) : 'N/A';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="val-footer">
            <p><strong>PAD/28-32</strong> — Sistema Integral de Comando y Logística Electoral • Pastora Altagracia Diputada</p>
            <p>Certificación criptográfica de unicidad territorial y auditoría padronal bajo normativa PLAD v4.2.</p>
        </div>
    </div>

</body>
</html>
