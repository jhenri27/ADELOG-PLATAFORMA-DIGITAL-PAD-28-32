<?php
session_start();
$_SESSION['usuario_id'] = 1;
$_SESSION['role'] = 'Administrador';
$_SESSION['usuario'] = 'admin';

$_GET['action'] = 'run_backup';
$_SERVER['REQUEST_METHOD'] = 'POST';

require_once __DIR__ . '/../backend/api/settings.php';
?>
