<?php
require_once __DIR__ . '/../backend/db.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    echo "=== CREANDO TABLA MOVIMIENTOS_FINANZAS ===\n";
    
    $sql = "CREATE TABLE IF NOT EXISTS movimientos_finanzas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        txn_id VARCHAR(100) NOT NULL UNIQUE,
        comprador VARCHAR(150) NOT NULL,
        plan VARCHAR(50) NOT NULL,
        monto_bruto DECIMAL(10,2) NOT NULL,
        comision_paypal DECIMAL(10,2) NOT NULL,
        monto_neto DECIMAL(10,2) NOT NULL
    )";
    
    if ($conn->query($sql)) {
        echo "✓ Tabla 'movimientos_finanzas' creada.\n";
    }
    
    // Sembrar movimientos de prueba simulados
    $transacciones = [
        [
            'fecha' => '2026-06-15 10:24:15',
            'txn_id' => 'TXN-8742918849L',
            'comprador' => 'Juan Ramón Almánzar',
            'plan' => 'Plan Básico ($299.00)',
            'monto_bruto' => 299.00,
            'comision' => 10.93, // 3.49% + $0.49
            'monto_neto' => 288.07
        ],
        [
            'fecha' => '2026-06-20 16:45:30',
            'txn_id' => 'TXN-9024817462B',
            'comprador' => 'Dra. María Estela Ortiz',
            'plan' => 'Plan Premium ($599.00)',
            'monto_bruto' => 599.00,
            'comision' => 21.40,
            'monto_neto' => 577.60
        ],
        [
            'fecha' => '2026-07-02 09:12:00',
            'txn_id' => 'TXN-1049281749K',
            'comprador' => 'Coordinadora General SDE 3',
            'plan' => 'Plan Enterprise ($1,299.00)',
            'monto_bruto' => 1299.00,
            'comision' => 45.82,
            'monto_neto' => 1253.18
        ],
        [
            'fecha' => '2026-07-05 14:05:11',
            'txn_id' => 'TXN-5629184729P',
            'comprador' => 'Ing. Pedro Luis Castillo',
            'plan' => 'Plan Básico ($299.00)',
            'monto_bruto' => 299.00,
            'comision' => 10.93,
            'monto_neto' => 288.07
        ],
        [
            'fecha' => '2026-07-08 19:30:25',
            'txn_id' => 'TXN-2938104758M',
            'comprador' => 'Movimiento Reformador SD',
            'plan' => 'Plan Premium ($599.00)',
            'monto_bruto' => 599.00,
            'comision' => 21.40,
            'monto_neto' => 577.60
        ]
    ];
    
    foreach ($transacciones as $t) {
        $fecha = $t['fecha'];
        $txn = $t['txn_id'];
        $comp = $conn->real_escape_string($t['comprador']);
        $plan = $conn->real_escape_string($t['plan']);
        $bruto = $t['monto_bruto'];
        $com = $t['comision'];
        $neto = $t['monto_neto'];
        
        $conn->query("INSERT IGNORE INTO movimientos_finanzas (fecha, txn_id, comprador, plan, monto_bruto, comision_paypal, monto_neto) 
                      VALUES ('$fecha', '$txn', '$comp', '$plan', $bruto, $com, $neto)");
    }
    echo "✓ Transacciones financieras sembradas.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
