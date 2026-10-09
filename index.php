<?php
/**
 * Redirección principal de raíz PAD/28-32 hacia el portal frontend
 */
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = 'frontend/index.html' . (!empty($queryString) ? ('?' . $queryString) : '');

header('Location: ' . $target, true, 302);
exit;
