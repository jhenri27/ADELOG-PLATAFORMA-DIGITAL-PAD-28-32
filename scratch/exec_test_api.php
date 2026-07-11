<?php
/**
 * Subprocess wrapper for test_api
 */
error_reporting(0);
ini_set('display_errors', 0);

@session_start();
$_SESSION['usuario_id'] = 1;
$_SESSION['role'] = 'Administrador';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'test_api';
$_GET['service'] = 'google_vision';
include __DIR__ . '/../backend/api/settings.php';
?>
