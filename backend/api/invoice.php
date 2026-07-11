<?php
/**
 * API: Generador de Recibos de Ventas (PDF)
 * ADELOG - Plataforma Electoral
 */

session_start();
require_once __DIR__ . '/../db.php';

// Validar inicio de sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    die("No autorizado. Inicie sesión.");
}

$txn_id = trim($_GET['txn_id'] ?? '');

if (empty($txn_id)) {
    http_response_code(400);
    die("ID de transacción requerido.");
}

$db = Database::getInstance();
$conn = $db->getConnection();

$txnEsc = $conn->real_escape_string($txn_id);
$sql = "SELECT * FROM movimientos_finanzas WHERE txn_id = '$txnEsc' LIMIT 1";
$res = $conn->query($sql);

if (!$res || $res->num_rows === 0) {
    http_response_code(404);
    die("Transacción no encontrada.");
}

$tx = $res->fetch_assoc();

// Obtener datos del candidato desde configuración
$configRes = $conn->query("SELECT clave, valor FROM configuraciones WHERE clave IN ('candidato_nombre', 'candidato_cargo', 'plataforma_nombre', 'candidato_logo_url')");
$config = [];
if ($configRes) {
    while ($row = $configRes->fetch_assoc()) {
        $config[$row['clave']] = $row['valor'];
    }
}
$candidato = $config['candidato_nombre'] ?? 'Pastora Altagracia';
$cargo = $config['candidato_cargo'] ?? 'Diputada';
$plataforma = $config['plataforma_nombre'] ?? 'ADELOG';
$logoUrl = $config['candidato_logo_url'] ?? '';

// Resolver ruta de la imagen para backend/api/
if (empty($logoUrl)) {
    $logoImgSrc = "../../GRAFICOS PARA LA PAGINA WEB/adelog_logo_icon.png";
} else {
    if (str_starts_with($logoUrl, '../')) {
        $logoImgSrc = "../" . $logoUrl;
    } else {
        $logoImgSrc = "../../" . $logoUrl;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recibo Contable - <?php echo htmlspecialchars($tx['txn_id']); ?></title>
    <link rel="shortcut icon" type="image/png" href="../../frontend/GRAFICOS PARA LA PAGINA WEB/adelog_logo_icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #002f6c;
            --secondary: #e3a115;
            --text-dark: #0f172a;
            --text-light: #64748b;
            --border: #cbd5e1;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            background-color: #f8fafc;
            padding: 40px 20px;
            margin: 0;
        }

        .invoice-card {
            max-width: 700px;
            margin: 0 auto;
            background-color: #ffffff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 40px;
            position: relative;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 24px;
            margin-bottom: 24px;
        }

        .logo-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-img {
            width: 55px;
            height: 55px;
            border-radius: 8px;
            border: 1.5px solid var(--secondary);
            background: #fff;
            padding: 2px;
        }

        .logo-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
            margin: 0;
        }

        .logo-title p {
            font-size: 11px;
            color: var(--text-light);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .invoice-meta {
            text-align: right;
        }

        .invoice-meta h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0 0 5px 0;
        }

        .invoice-meta p {
            font-size: 13px;
            color: var(--text-light);
            margin: 2px 0;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .details-box h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            color: var(--primary);
            text-transform: uppercase;
            margin-bottom: 8px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 4px;
        }

        .details-box p {
            font-size: 13px;
            color: var(--text-dark);
            margin: 4px 0;
        }

        .table-responsive {
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background-color: #f8fafc;
            color: var(--primary);
            font-family: 'Outfit', sans-serif;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 12px;
            font-size: 13px;
            border-bottom: 1px solid #edf2f7;
            color: var(--text-dark);
        }

        .totals-box {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            margin-top: 20px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            width: 250px;
            font-size: 13px;
        }

        .total-row.grand-total {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            border-top: 2px solid #e2e8f0;
            padding-top: 8px;
            margin-top: 4px;
        }

        .paid-stamp {
            position: absolute;
            top: 130px;
            left: 50%;
            transform: translateX(-50%) rotate(-12deg);
            border: 4px solid #10b981;
            color: #10b981;
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            font-weight: 800;
            padding: 6px 20px;
            border-radius: 8px;
            text-transform: uppercase;
            letter-spacing: 2px;
            opacity: 0.15;
            pointer-events: none;
        }

        .action-bar {
            max-width: 700px;
            margin: 20px auto 0 auto;
            display: flex;
            justify-content: flex-end;
        }

        .btn-print {
            background-color: var(--primary);
            color: #fff;
            border: none;
            padding: 10px 20px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 14px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background-color: #fff !important;
                padding: 0 !important;
            }
            .invoice-card {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
            }
            .paid-stamp {
                opacity: 0.3;
            }
        }
    </style>
</head>
<body>

    <div class="invoice-card">
        <!-- Paid Stamp -->
        <div class="paid-stamp">PAGADO</div>

        <!-- Header -->
        <div class="invoice-header">
            <div class="logo-box">
                <img src="<?php echo htmlspecialchars($logoImgSrc); ?>" alt="Logo Oficial" class="logo-img">
                <div class="logo-title">
                    <h1><?php echo htmlspecialchars($plataforma); ?></h1>
                    <p><?php echo htmlspecialchars($candidato . ' ' . $cargo); ?></p>
                </div>
            </div>
            <div class="invoice-meta">
                <h2>RECIBO</h2>
                <p><strong>Fecha:</strong> <?php echo htmlspecialchars($tx['fecha']); ?></p>
                <p><strong>Ref Transacción:</strong> <?php echo htmlspecialchars($tx['txn_id']); ?></p>
            </div>
        </div>

        <!-- Grid -->
        <div class="details-grid">
            <div class="details-box">
                <h3>Cliente / Comprador</h3>
                <p><strong>Nombre:</strong> <?php echo htmlspecialchars($tx['comprador']); ?></p>
                <p><strong>Método:</strong> <?php echo htmlspecialchars($tx['metodo_pago']); ?></p>
            </div>
            <div class="details-box">
                <h3>Proveedor</h3>
                <p><strong>Empresa:</strong> Sypempresariales</p>
                <p><strong>Soporte:</strong> soporte@sypempresariales.com</p>
            </div>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th style="text-align: right;">Monto Bruto</th>
                        <th style="text-align: right;">Comisión</th>
                        <th style="text-align: right;">Monto Neto</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Suscripción Plan:</strong> <?php echo htmlspecialchars($tx['plan']); ?></td>
                        <td style="text-align: right;">$<?php echo number_format($tx['monto_bruto'], 2); ?></td>
                        <td style="text-align: right; color: #dc2626;">-$<?php echo number_format($tx['comision_paypal'], 2); ?></td>
                        <td style="text-align: right; font-weight: bold; color: #16a34a;">$<?php echo number_format($tx['monto_neto'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="totals-box">
            <div class="total-row">
                <span>Subtotal Bruto:</span>
                <span>$<?php echo number_format($tx['monto_bruto'], 2); ?></span>
            </div>
            <div class="total-row">
                <span>Comisión de Transacción:</span>
                <span style="color: #dc2626;">-$<?php echo number_format($tx['comision_paypal'], 2); ?></span>
            </div>
            <div class="total-row grand-total">
                <span>Saldo Neto Recibido:</span>
                <span>$<?php echo number_format($tx['monto_neto'], 2); ?></span>
            </div>
        </div>
    </div>

    <!-- Action Bar -->
    <div class="action-bar no-print">
        <button class="btn-print" onclick="window.print()"><i class="fa fa-print"></i> Imprimir / Guardar PDF</button>
    </div>

    <script>
        // Disparar la impresión automática en cuanto cargue el documento
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                window.print();
            }, 500);
        });
    </script>

</body>
</html>
