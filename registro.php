<?php
/**
 * Redirección de Enlaces Oficiales de Captación y Registro PAD/28-32
 * Preserva todos los parámetros (?canal=...&ref=...) hacia el formulario de inscripción
 */
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = 'frontend/index.html' . (!empty($queryString) ? ('?' . $queryString) : '');

header('Location: ' . $target, true, 302);
exit;
