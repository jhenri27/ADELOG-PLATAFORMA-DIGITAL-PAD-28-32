<?php
/**
 * Subprocess wrapper for invoice.php
 */
error_reporting(0);
ini_set('display_errors', 0);

@session_start();
$_SESSION['usuario_id'] = 1;
$_SESSION['role'] = 'Administrador';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['txn_id'] = 'TXN-TEST-9999';
include __DIR__ . '/../backend/api/invoice.php';
?>
