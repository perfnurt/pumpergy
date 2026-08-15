<?php

// Serves energy readings data as JSON for frontend charts.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_loader.php';

if (!empty($container['boot_error'])) {
	http_response_code(503);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => 'setup_required', 'detail' => $container['boot_error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

try {
	[$resolution, $start, $end] = DateRange::fromQuery($_GET);
	$data = $container['services']['dashboard']->getDashboardData($resolution, $start, $end);
} catch (Throwable $e) {
	http_response_code(503);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => 'setup_required', 'detail' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
