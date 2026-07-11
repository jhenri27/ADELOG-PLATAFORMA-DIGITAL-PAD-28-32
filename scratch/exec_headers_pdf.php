<?php
/**
 * Subprocess wrapper to capture export_pdf_2024 headers
 */
error_reporting(0);
ini_set('display_errors', 0);

@session_start();
$_SESSION['usuario_id'] = 1;
$_SESSION['role'] = 'Administrador';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'export_pdf_2024';
$_GET['region'] = '';
ob_start();
include __DIR__ . '/../backend/api/voters.php';
ob_clean();
echo implode("\n", headers_list());
?>
