<?php

// Exposes settings read and update endpoints.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_loader.php';

if (!empty($container['boot_error'])) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'setup_required', 'detail' => $container['boot_error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$settings = $container['services']['settings'] ?? null;
if (!$settings instanceof SettingsService) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'settings_unavailable'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET') {
        $result = $settings->getLegionellaSchedule();
    } elseif ($method === 'POST') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'invalid_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $result = $settings->saveLegionellaSchedule(
            (int)($payload['legionella_day'] ?? 1),
            (int)($payload['legionella_hour'] ?? 2)
        );
    } else {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'method_not_allowed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'settings_failed', 'detail' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);