<?php

// Triggers and reports data synchronization from source files.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_loader.php';

if (!empty($container['boot_error'])) {
	http_response_code(503);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => 'setup_required', 'detail' => $container['boot_error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

try {
	$interval = (int)($container['config']['sync_interval_seconds'] ?? 300);
	$force = (string)($_GET['force'] ?? '') === '1';
	$result = $container['services']['import']->importIfDue($interval, $force);
} catch (Throwable $e) {
	http_response_code(503);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => 'setup_required', 'detail' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
